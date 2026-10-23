<?php
/**
 * class/enfermeria.php — Apoyo a enfermería y exámenes.
 *  - Notas de enfermería (evolución de la sueroterapia): tabla nota_enfermeria.
 *  - Exámenes (laboratorio, ecografía, imagen…): órdenes con estado Pendiente / Realizado / Cancelado.
 *  - Historial por paciente: qué sueros se le han puesto o le tocan, exámenes y notas.
 */
require_once(__DIR__ . '/auditoria.php');

function enfermeria_asegurar_tablas($c) {
    $a = (bool)@$c->query("CREATE TABLE IF NOT EXISTS nota_enfermeria (
        IDNOTA INT AUTO_INCREMENT PRIMARY KEY,
        IDPACIENTE INT NOT NULL,
        IDORDEN INT NULL,
        IDCITA INT NULL,
        NOTA TEXT NOT NULL,
        IDUSUARIO INT NULL,
        FECHA DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pac (IDPACIENTE, FECHA),
        INDEX idx_orden (IDORDEN)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $b = (bool)@$c->query("CREATE TABLE IF NOT EXISTS examen_orden (
        IDEXAMEN INT AUTO_INCREMENT PRIMARY KEY,
        IDPACIENTE INT NOT NULL,
        IDCITA_ORIGEN INT NULL,
        TIPO VARCHAR(20) NOT NULL DEFAULT 'Laboratorio',
        EXAMEN VARCHAR(200) NOT NULL,
        INDICACION TEXT NULL,
        ESTADO VARCHAR(12) NOT NULL DEFAULT 'Pendiente',
        FECHA_SOLICITUD DATETIME DEFAULT CURRENT_TIMESTAMP,
        IDUSUARIO_SOLICITA INT NULL,
        FECHA_REALIZADO DATETIME NULL,
        IDUSUARIO_REALIZA INT NULL,
        RESULTADO TEXT NULL,
        INDEX idx_pac (IDPACIENTE, ESTADO),
        INDEX idx_estado (ESTADO, FECHA_SOLICITUD)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $a && $b;
}

function examen_tipos() { return ['Laboratorio', 'Ecografía', 'Imagen', 'Otro']; }

/** Agrega una nota de enfermería (ligada a una orden de suero y/o cita). */
function nota_enfermeria_agregar($c, $idPaciente, $idOrden, $idCita, $nota, $idUsuario) {
    $nota = trim((string)$nota);
    if ($nota === '' || (int)$idPaciente <= 0) return false;
    $idPaciente = (int)$idPaciente; $idOrden = $idOrden ? (int)$idOrden : null; $idCita = $idCita ? (int)$idCita : null;
    $st = $c->prepare("INSERT INTO nota_enfermeria (IDPACIENTE, IDORDEN, IDCITA, NOTA, IDUSUARIO) VALUES (?, ?, ?, ?, ?)");
    if (!$st) return false;
    $st->bind_param('iiisi', $idPaciente, $idOrden, $idCita, $nota, $idUsuario);
    $ok = $st->execute(); $id = (int)$c->insert_id; $st->close();
    if ($ok) auditar($c, 'Enfermería', 'crear', 'nota_enfermeria', $id, 'Nota de enfermería (paciente #'.$idPaciente.($idOrden ? ', orden de suero #'.$idOrden : '').')');
    return $ok;
}

/** Crea una orden de examen. Devuelve ['ok'=>bool,'error'=>?,'id'=>?]. */
function examen_crear($c, $idPaciente, $idCita, $tipo, $examen, $indicacion, $idUsuario) {
    $examen = mb_substr(trim((string)$examen), 0, 200);
    if ($examen === '') return ['ok'=>false,'error'=>'EXAMEN_REQUERIDO'];
    if (!in_array($tipo, examen_tipos(), true)) $tipo = 'Otro';
    $idPaciente = (int)$idPaciente; $idCita = $idCita ? (int)$idCita : null; $indicacion = trim((string)$indicacion);
    $q = $c->query("SELECT 1 FROM AG_PACIENTE WHERE IDPACIENTE = $idPaciente AND ESTADO='A' LIMIT 1");
    if (!$q || !$q->fetch_assoc()) return ['ok'=>false,'error'=>'PACIENTE_INVALIDO'];
    $st = $c->prepare("INSERT INTO examen_orden (IDPACIENTE, IDCITA_ORIGEN, TIPO, EXAMEN, INDICACION, IDUSUARIO_SOLICITA) VALUES (?, ?, ?, ?, NULLIF(?,''), ?)");
    $st->bind_param('iisssi', $idPaciente, $idCita, $tipo, $examen, $indicacion, $idUsuario);
    $ok = $st->execute(); $id = (int)$c->insert_id; $st->close();
    if (!$ok) return ['ok'=>false,'error'=>'DB'];
    auditar($c, 'Exámenes', 'crear', 'examen_orden', $id, 'Solicitó examen: '.$examen.' ('.$tipo.') para el paciente #'.$idPaciente);
    return ['ok'=>true,'id'=>$id];
}

/** Marca un examen Realizado o Cancelado (solo si está Pendiente). */
function examen_cambiar_estado($c, $idExamen, $estado, $resultado, $fechaHora, $idUsuario) {
    if (!in_array($estado, ['Realizado','Cancelado'], true)) return false;
    $idExamen = (int)$idExamen; $resultado = trim((string)$resultado);
    $ts = strtotime((string)$fechaHora); $fecha = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    $st = $c->prepare("UPDATE examen_orden SET ESTADO=?, FECHA_REALIZADO=?, IDUSUARIO_REALIZA=?, RESULTADO=NULLIF(?,'') WHERE IDEXAMEN=? AND ESTADO='Pendiente'");
    if (!$st) return false;
    $st->bind_param('ssisi', $estado, $fecha, $idUsuario, $resultado, $idExamen);
    $ok = $st->execute() && $st->affected_rows === 1; $st->close();
    if ($ok) auditar($c, 'Exámenes', 'editar', 'examen_orden', $idExamen, 'Examen marcado como '.$estado);
    return $ok;
}

/** Exámenes de un paciente (todos), más recientes primero. */
function examenes_paciente($c, $idPaciente) {
    $idPaciente = (int)$idPaciente; $out = [];
    $q = @$c->query("SELECT E.*, TRIM(CONCAT(COALESCE(U.NOMBRES,''),' ',COALESCE(U.APELLIDOS,''))) AS SOLICITA,
                            TRIM(CONCAT(COALESCE(R.NOMBRES,''),' ',COALESCE(R.APELLIDOS,''))) AS REALIZA
                       FROM examen_orden E
                       LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = E.IDUSUARIO_SOLICITA
                       LEFT JOIN ADM_USUARIO R ON R.IDADM_USUARIO = E.IDUSUARIO_REALIZA
                      WHERE E.IDPACIENTE = $idPaciente
                      ORDER BY (E.ESTADO='Pendiente') DESC, E.FECHA_SOLICITUD DESC, E.IDEXAMEN DESC");
    if ($q) while ($r = $q->fetch_assoc()) $out[] = $r;
    return $out;
}

/** Sueros del paciente (órdenes de todas las derivaciones tipo SUERO) y notas de enfermería. */
function historial_enfermeria($c, $idPaciente) {
    $idPaciente = (int)$idPaciente; $sueros = []; $notas = [];
    $q = @$c->query("SELECT S.IDORDEN, S.PRODUCTO, S.DOSIS, S.VIA, S.INDICACIONES, S.ESTADO, S.FECHA_APLICACION, S.LOTE, S.OBSERVACIONES, S.REACCIONES,
                            D.FECHA_CREACION, TRIM(CONCAT(COALESCE(U.NOMBRES,''),' ',COALESCE(U.APELLIDOS,''))) AS INDICA
                       FROM suero_orden S
                       INNER JOIN derivacion D ON D.IDDERIVACION = S.IDDERIVACION
                       LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = D.IDUSUARIO_ORIGEN
                      WHERE D.IDPACIENTE = $idPaciente AND D.TIPO = 'SUERO'
                      ORDER BY (S.ESTADO='Pendiente') DESC, COALESCE(S.FECHA_APLICACION, D.FECHA_CREACION) DESC, S.IDORDEN DESC");
    if ($q) while ($r = $q->fetch_assoc()) $sueros[] = $r;
    $q = @$c->query("SELECT N.IDNOTA, N.IDORDEN, N.NOTA, N.FECHA, TRIM(CONCAT(COALESCE(U.NOMBRES,''),' ',COALESCE(U.APELLIDOS,''))) AS AUTOR
                       FROM nota_enfermeria N LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = N.IDUSUARIO
                      WHERE N.IDPACIENTE = $idPaciente ORDER BY N.FECHA DESC, N.IDNOTA DESC LIMIT 60");
    if ($q) while ($r = $q->fetch_assoc()) $notas[] = $r;
    return ['sueros' => $sueros, 'notas' => $notas, 'examenes' => examenes_paciente($c, $idPaciente)];
}
