<?php
/**
 * class/triaje.php — Triaje: signos vitales y motivo de consulta ligados a la cita.
 *
 * Una fila por cita (IDCITA único). Al guardar, la cita pasa a estado 'Triada'
 * (si estaba Pendiente/Confirmada/Reagendada) para que el profesional vea quién
 * está listo para atender. Los valores de peso/talla se guardan en kg/cm, igual
 * que AG_HISTORIAL.
 */
require_once(__DIR__ . '/auditoria.php');

/** Crea la tabla si no existe (self-healing, como app_config). */
function triaje_asegurar_tabla($c) {
    return (bool)@$c->query("CREATE TABLE IF NOT EXISTS triaje (
        IDTRIAJE INT AUTO_INCREMENT PRIMARY KEY,
        IDCITA INT NOT NULL,
        IDPACIENTE INT NOT NULL,
        PESO_KG DECIMAL(6,2) NULL,
        TALLA_CM DECIMAL(5,1) NULL,
        PRESION VARCHAR(20) NULL,
        FC SMALLINT NULL,
        SAT_O2 TINYINT NULL,
        TEMPERATURA DECIMAL(4,1) NULL,
        GLUCOSA DECIMAL(6,1) NULL,
        MOTIVO TEXT NULL,
        IDUSUARIO INT NULL,
        FECHA_REGISTRO DATETIME DEFAULT CURRENT_TIMESTAMP,
        FECHA_MODIFICACION DATETIME NULL,
        UNIQUE KEY uq_cita (IDCITA),
        INDEX idx_paciente (IDPACIENTE)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Triaje de una cita (array asociativo) o null. */
function triaje_obtener($c, $idCita) {
    $idCita = (int)$idCita;
    $rs = @$c->query("SELECT T.*, CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS REGISTRADO_POR
                        FROM triaje T LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = T.IDUSUARIO
                       WHERE T.IDCITA = $idCita LIMIT 1");
    return ($rs && ($r = $rs->fetch_assoc())) ? $r : null;
}

/** Convierte a número o null (acepta coma decimal). */
function triaje_num($v, $min, $max) {
    $v = trim(str_replace(',', '.', (string)$v));
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f >= $min && $f <= $max) ? $f : null;
}

/**
 * Guarda (inserta o actualiza) el triaje de una cita.
 * $d: peso_kg, talla_cm, presion, fc, sat_o2, temperatura, glucosa, motivo.
 * Devuelve true/false.
 */
function triaje_guardar($c, $idCita, $d, $idUsuario) {
    $idCita = (int)$idCita;
    $rc = $c->query("SELECT IDPACIENTE, ESTADO_CITA FROM AG_CITA WHERE IDCITA = $idCita AND ESTADO='A' LIMIT 1");
    if (!$rc || !($cita = $rc->fetch_assoc())) return false;
    $idPac = (int)$cita['IDPACIENTE'];

    $peso = triaje_num($d['peso_kg']     ?? '', 1, 500);
    $talla= triaje_num($d['talla_cm']    ?? '', 20, 260);
    $fc   = triaje_num($d['fc']          ?? '', 20, 300);
    $sat  = triaje_num($d['sat_o2']      ?? '', 30, 100);
    $temp = triaje_num($d['temperatura'] ?? '', 30, 45);
    $glu  = triaje_num($d['glucosa']     ?? '', 10, 1000);
    $pres = mb_substr(trim((string)($d['presion'] ?? '')), 0, 20);
    $mot  = trim((string)($d['motivo'] ?? ''));

    $existe = triaje_obtener($c, $idCita) !== null;
    if ($existe) {
        $st = $c->prepare("UPDATE triaje SET PESO_KG=?, TALLA_CM=?, PRESION=NULLIF(?,''), FC=?, SAT_O2=?, TEMPERATURA=?, GLUCOSA=?,
                                  MOTIVO=NULLIF(?,''), IDUSUARIO=?, FECHA_MODIFICACION=NOW() WHERE IDCITA=?");
        if (!$st) return false;
        $st->bind_param('ddsiiddsii', $peso, $talla, $pres, $fc, $sat, $temp, $glu, $mot, $idUsuario, $idCita);
    } else {
        $st = $c->prepare("INSERT INTO triaje (PESO_KG, TALLA_CM, PRESION, FC, SAT_O2, TEMPERATURA, GLUCOSA, MOTIVO, IDUSUARIO, IDCITA, IDPACIENTE)
                           VALUES (?, ?, NULLIF(?,''), ?, ?, ?, ?, NULLIF(?,''), ?, ?, ?)");
        if (!$st) return false;
        $st->bind_param('ddsiiddsiii', $peso, $talla, $pres, $fc, $sat, $temp, $glu, $mot, $idUsuario, $idCita, $idPac);
    }
    $ok = $st->execute(); $st->close();
    if (!$ok) return false;

    // La cita queda "Triada" solo si aún no ha sido atendida/cancelada.
    if (in_array($cita['ESTADO_CITA'], ['Pendiente','Confirmada','Reagendada'], true)) {
        $c->query("UPDATE AG_CITA SET ESTADO_CITA='Triada' WHERE IDCITA = $idCita");
    }
    auditar($c, 'Triaje', $existe ? 'editar' : 'crear', 'triaje', $idCita,
            ($existe ? 'Actualizó' : 'Registró').' triaje de la cita #'.$idCita);
    return true;
}
