<?php
/**
 * class/derivacion.php — Derivación al terminar la consulta.
 *
 *  - PROFESIONAL: crea una cita de hoy (estado 'Derivada') para el profesional
 *    receptor, con el triaje copiado. El historial clínico ya es por paciente,
 *    así que el receptor ve todas las atenciones previas.
 *  - SUERO: deja una o varias órdenes (producto, dosis, vía, indicaciones) en la
 *    cola de enfermería; la enfermera registra la aplicación (hora, lote,
 *    observaciones, reacciones).
 */
require_once(__DIR__ . '/auditoria.php');

function derivacion_asegurar_tablas($c) {
    $a = (bool)@$c->query("CREATE TABLE IF NOT EXISTS derivacion (
        IDDERIVACION INT AUTO_INCREMENT PRIMARY KEY,
        TIPO VARCHAR(12) NOT NULL,
        IDPACIENTE INT NOT NULL,
        IDCITA_ORIGEN INT NOT NULL,
        IDCITA_DESTINO INT NULL,
        IDUSUARIO_ORIGEN INT NULL,
        IDUSUARIO_DESTINO INT NULL,
        MOTIVO TEXT NULL,
        ESTADO VARCHAR(12) NOT NULL DEFAULT 'Pendiente',
        FECHA_CREACION DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_destino (IDCITA_DESTINO),
        INDEX idx_origen (IDCITA_ORIGEN),
        INDEX idx_estado (TIPO, ESTADO)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $b = (bool)@$c->query("CREATE TABLE IF NOT EXISTS suero_orden (
        IDORDEN INT AUTO_INCREMENT PRIMARY KEY,
        IDDERIVACION INT NOT NULL,
        PRODUCTO VARCHAR(150) NOT NULL,
        DOSIS VARCHAR(80) NULL,
        VIA VARCHAR(40) NULL,
        INDICACIONES TEXT NULL,
        ESTADO VARCHAR(12) NOT NULL DEFAULT 'Pendiente',
        FECHA_APLICACION DATETIME NULL,
        IDUSUARIO_APLICA INT NULL,
        LOTE VARCHAR(60) NULL,
        OBSERVACIONES TEXT NULL,
        REACCIONES TEXT NULL,
        INDEX idx_deriv (IDDERIVACION),
        INDEX idx_estado (ESTADO)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $a && $b;
}

/**
 * Crea una derivación desde la cita $idCitaOrigen.
 * $d: tipo ('PROFESIONAL'|'SUERO'), motivo, idDoctorDestino, items[] (producto,dosis,via,indicaciones).
 * Devuelve ['ok'=>bool, 'error'=>?, 'id'=>?, 'idCitaDestino'=>?].
 */
function derivacion_crear($c, $idCitaOrigen, $d, $idUsuario) {
    derivacion_asegurar_tablas($c);
    $idCitaOrigen = (int)$idCitaOrigen;
    $q = $c->query("SELECT IDPACIENTE, IDDOCTOR, IDTIPOCONSULTA, IDAGENCIA FROM AG_CITA WHERE IDCITA = $idCitaOrigen AND ESTADO='A' LIMIT 1");
    $o = $q ? $q->fetch_assoc() : null;
    if (!$o) return ['ok'=>false,'error'=>'CITA_NO_ENCONTRADA'];
    $idPac  = (int)$o['IDPACIENTE'];
    $tipo   = ($d['tipo'] ?? '') === 'SUERO' ? 'SUERO' : 'PROFESIONAL';
    $motivo = trim((string)($d['motivo'] ?? ''));
    if ($motivo === '') return ['ok'=>false,'error'=>'MOTIVO_REQUERIDO'];

    $idDest = null; $idCitaDest = null;
    $items = [];
    if ($tipo === 'PROFESIONAL') {
        $idDest = (int)($d['idDoctorDestino'] ?? 0);
        if ($idDest <= 0) return ['ok'=>false,'error'=>'PROFESIONAL_REQUERIDO'];
        if ($idDest === (int)$o['IDDOCTOR']) return ['ok'=>false,'error'=>'MISMO_PROFESIONAL'];
        $rd = $c->query("SELECT U.IDADM_USUARIO FROM ADM_USUARIO U INNER JOIN ADM_ROL R ON R.IDADM_ROL=U.IDADM_ROL
                          WHERE U.IDADM_USUARIO = $idDest AND U.ESTADO='A' AND R.CARGO='DOCTOR' LIMIT 1");
        if (!$rd || !$rd->fetch_assoc()) return ['ok'=>false,'error'=>'PROFESIONAL_INVALIDO'];
    } else {
        foreach ((array)($d['items'] ?? []) as $it) {
            $prod = trim((string)($it['producto'] ?? ''));
            if ($prod === '') continue;
            $items[] = [
                mb_substr($prod, 0, 150),
                mb_substr(trim((string)($it['dosis'] ?? '')), 0, 80),
                mb_substr(trim((string)($it['via'] ?? '')), 0, 40),
                trim((string)($it['indicaciones'] ?? '')),
            ];
        }
        if (!$items) return ['ok'=>false,'error'=>'SUERO_REQUERIDO'];
    }

    $c->begin_transaction();
    try {
        if ($tipo === 'PROFESIONAL') {
            $hoy = date('Y-m-d'); $ini = date('H:i'); $fin = date('H:i', strtotime($ini) + 3600);
            $idTipoC = (int)$o['IDTIPOCONSULTA']; $idAg = (int)($o['IDAGENCIA'] ?? 0);
            $com = 'Derivado desde la cita #'.$idCitaOrigen;
            $st = $c->prepare("INSERT INTO AG_CITA (IDPACIENTE, IDTIPOCONSULTA, IDDOCTOR, IDUSUARIO, FECHA_CITA, HORA_INICIO, HORA_FIN, IDAGENCIA, ESTADO_CITA, ESTADO, COMENTARIO)
                               VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), 'Derivada', 'A', ?)");
            $st->bind_param('iiiisssis', $idPac, $idTipoC, $idDest, $idUsuario, $hoy, $ini, $fin, $idAg, $com);
            if (!$st->execute()) throw new Exception('CITA_DESTINO');
            $idCitaDest = (int)$c->insert_id; $st->close();
            // El triaje viaja con el paciente (si existe)
            @$c->query("INSERT INTO triaje (IDCITA, IDPACIENTE, PESO_KG, TALLA_CM, PRESION, FC, SAT_O2, TEMPERATURA, GLUCOSA, MOTIVO, IDUSUARIO)
                        SELECT $idCitaDest, IDPACIENTE, PESO_KG, TALLA_CM, PRESION, FC, SAT_O2, TEMPERATURA, GLUCOSA, MOTIVO, IDUSUARIO
                          FROM triaje WHERE IDCITA = $idCitaOrigen");
        }
        $st = $c->prepare("INSERT INTO derivacion (TIPO, IDPACIENTE, IDCITA_ORIGEN, IDCITA_DESTINO, IDUSUARIO_ORIGEN, IDUSUARIO_DESTINO, MOTIVO)
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
        $st->bind_param('siiiiis', $tipo, $idPac, $idCitaOrigen, $idCitaDest, $idUsuario, $idDest, $motivo);
        if (!$st->execute()) throw new Exception('DERIVACION');
        $idDer = (int)$c->insert_id; $st->close();
        foreach ($items as $it) {
            $st = $c->prepare("INSERT INTO suero_orden (IDDERIVACION, PRODUCTO, DOSIS, VIA, INDICACIONES) VALUES (?, ?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''))");
            $st->bind_param('issss', $idDer, $it[0], $it[1], $it[2], $it[3]);
            if (!$st->execute()) throw new Exception('SUERO');
            $st->close();
        }
        $c->commit();
    } catch (Exception $e) {
        $c->rollback();
        return ['ok'=>false,'error'=>'ERROR_'.$e->getMessage()];
    }
    auditar($c, 'Derivación', 'crear', 'derivacion', $idDer,
        ($tipo === 'SUERO' ? 'Derivó a sueros ('.count($items).' orden/es)' : 'Derivó al profesional #'.$idDest.' (cita #'.$idCitaDest.')').' desde la cita #'.$idCitaOrigen);
    return ['ok'=>true,'id'=>$idDer,'idCitaDestino'=>$idCitaDest];
}

/**
 * Registra el resultado de una orden de suero (Aplicado | Suspendido).
 * Cierra la derivación cuando no quedan órdenes pendientes.
 */
function suero_registrar($c, $idOrden, $estado, $lote, $obs, $reacc, $fechaHora, $idUsuario) {
    $idOrden = (int)$idOrden;
    if (!in_array($estado, ['Aplicado','Suspendido'], true)) return false;
    $ts = strtotime($fechaHora);
    $fecha = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    $st = $c->prepare("UPDATE suero_orden SET ESTADO=?, FECHA_APLICACION=?, IDUSUARIO_APLICA=?, LOTE=NULLIF(?,''), OBSERVACIONES=NULLIF(?,''), REACCIONES=NULLIF(?,'')
                        WHERE IDORDEN=? AND ESTADO='Pendiente'");
    if (!$st) return false;
    $st->bind_param('ssisssi', $estado, $fecha, $idUsuario, $lote, $obs, $reacc, $idOrden);
    $ok = $st->execute() && $st->affected_rows === 1; $st->close();
    if (!$ok) return false;
    $r = $c->query("SELECT IDDERIVACION FROM suero_orden WHERE IDORDEN = $idOrden");
    $idDer = $r ? (int)($r->fetch_assoc()['IDDERIVACION'] ?? 0) : 0;
    if ($idDer) {
        $p = $c->query("SELECT COUNT(*) c FROM suero_orden WHERE IDDERIVACION = $idDer AND ESTADO='Pendiente'");
        if ($p && (int)$p->fetch_assoc()['c'] === 0) $c->query("UPDATE derivacion SET ESTADO='Atendida' WHERE IDDERIVACION = $idDer");
    }
    auditar($c, 'Derivación', 'editar', 'suero_orden', $idOrden, ($estado === 'Aplicado' ? 'Aplicó' : 'Suspendió').' suero (orden #'.$idOrden.')');
    return true;
}
