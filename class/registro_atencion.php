<?php
/**
 * class/registro_atencion.php — "Registro de atención" con el formato de la hoja física de la clínica:
 * encabezado (Servicio, Fecha, Tratante) y filas de Indicaciones / Cantidad / Recomendaciones.
 * Convive con la nota libre (AG_HISTORIAL); una fila de encabezado por cita.
 */
require_once(__DIR__ . '/auditoria.php');

function registro_asegurar_tablas($c) {
    $a = (bool)@$c->query("CREATE TABLE IF NOT EXISTS atencion_registro (
        IDREGISTRO INT AUTO_INCREMENT PRIMARY KEY,
        IDCITA INT NOT NULL,
        IDPACIENTE INT NOT NULL,
        SERVICIO VARCHAR(150) NULL,
        FECHA DATE NULL,
        TRATANTE VARCHAR(150) NULL,
        IDUSUARIO INT NULL,
        FECHA_REGISTRO DATETIME DEFAULT CURRENT_TIMESTAMP,
        FECHA_MODIFICACION DATETIME NULL,
        UNIQUE KEY uq_cita (IDCITA),
        INDEX idx_pac (IDPACIENTE)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $b = (bool)@$c->query("CREATE TABLE IF NOT EXISTS atencion_registro_item (
        IDITEM INT AUTO_INCREMENT PRIMARY KEY,
        IDREGISTRO INT NOT NULL,
        ORDEN SMALLINT NOT NULL DEFAULT 0,
        INDICACION TEXT NULL,
        CANTIDAD VARCHAR(60) NULL,
        RECOMENDACION TEXT NULL,
        INDEX idx_reg (IDREGISTRO)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $a && $b;
}

/** Registro de una cita: ['reg'=>fila|null, 'items'=>[...]] */
function registro_obtener($c, $idCita) {
    $idCita = (int)$idCita;
    $out = ['reg' => null, 'items' => []];
    $r = @$c->query("SELECT * FROM atencion_registro WHERE IDCITA = $idCita LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) {
        $out['reg'] = $x;
        $q = @$c->query("SELECT INDICACION, CANTIDAD, RECOMENDACION FROM atencion_registro_item WHERE IDREGISTRO = ".(int)$x['IDREGISTRO']." ORDER BY ORDEN, IDITEM");
        if ($q) while ($i = $q->fetch_assoc()) $out['items'][] = $i;
    }
    return $out;
}

/**
 * Guarda encabezado + filas (reemplaza las filas). $items: [['indicacion','cantidad','recomendacion'], ...]
 * Las filas completamente vacías se omiten. Devuelve ['ok'=>bool,'error'=>?].
 */
function registro_guardar($c, $idCita, $servicio, $fecha, $tratante, $items, $idUsuario) {
    registro_asegurar_tablas($c);
    $idCita = (int)$idCita;
    $rc = $c->query("SELECT IDPACIENTE FROM AG_CITA WHERE IDCITA = $idCita AND ESTADO='A' LIMIT 1");
    $cita = $rc ? $rc->fetch_assoc() : null;
    if (!$cita) return ['ok'=>false,'error'=>'CITA_NO_ENCONTRADA'];
    $idPac = (int)$cita['IDPACIENTE'];
    $servicio = mb_substr(trim($servicio), 0, 150);
    $tratante = mb_substr(trim($tratante), 0, 150);
    $fd = DateTime::createFromFormat('Y-m-d', (string)$fecha);
    $fecha = ($fd && $fd->format('Y-m-d') === $fecha) ? $fecha : date('Y-m-d');

    $limpios = [];
    foreach ((array)$items as $it) {
        $ind = trim((string)($it['indicacion'] ?? '')); $can = mb_substr(trim((string)($it['cantidad'] ?? '')), 0, 60); $rec = trim((string)($it['recomendacion'] ?? ''));
        if ($ind === '' && $can === '' && $rec === '') continue;
        $limpios[] = [$ind, $can, $rec];
    }
    if (count($limpios) > 60) return ['ok'=>false,'error'=>'DEMASIADAS_FILAS'];

    $c->begin_transaction();
    try {
        $ex = registro_obtener($c, $idCita)['reg'];
        if ($ex) {
            $idReg = (int)$ex['IDREGISTRO'];
            $st = $c->prepare("UPDATE atencion_registro SET SERVICIO=NULLIF(?,''), FECHA=?, TRATANTE=NULLIF(?,''), IDUSUARIO=?, FECHA_MODIFICACION=NOW() WHERE IDREGISTRO=?");
            $st->bind_param('sssii', $servicio, $fecha, $tratante, $idUsuario, $idReg);
            if (!$st->execute()) throw new Exception('REG'); $st->close();
            $c->query("DELETE FROM atencion_registro_item WHERE IDREGISTRO = $idReg");
        } else {
            $st = $c->prepare("INSERT INTO atencion_registro (IDCITA, IDPACIENTE, SERVICIO, FECHA, TRATANTE, IDUSUARIO) VALUES (?, ?, NULLIF(?,''), ?, NULLIF(?,''), ?)");
            $st->bind_param('iisssi', $idCita, $idPac, $servicio, $fecha, $tratante, $idUsuario);
            if (!$st->execute()) throw new Exception('REG'); $idReg = (int)$c->insert_id; $st->close();
        }
        $st = $c->prepare("INSERT INTO atencion_registro_item (IDREGISTRO, ORDEN, INDICACION, CANTIDAD, RECOMENDACION) VALUES (?, ?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''))");
        foreach ($limpios as $n => $l) {
            $st->bind_param('iisss', $idReg, $n, $l[0], $l[1], $l[2]);
            if (!$st->execute()) throw new Exception('ITEM');
        }
        $st->close();
        $c->commit();
    } catch (Exception $e) { $c->rollback(); return ['ok'=>false,'error'=>'ERROR_'.$e->getMessage()]; }
    auditar($c, 'Atención', $ex ? 'editar' : 'crear', 'atencion_registro', $idReg, ($ex ? 'Actualizó' : 'Registró').' el registro de atención de la cita #'.$idCita.' ('.count($limpios).' fila/s)');
    return ['ok'=>true, 'filas'=>count($limpios)];
}
