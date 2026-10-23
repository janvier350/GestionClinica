<?php
/**
 * class/pacientes_util.php — Utilidades compartidas de pacientes.
 *  - paciente_crear_minimo(): alta rápida con datos mínimos (triaje sin cita, historias escaneadas).
 *  - paciente_archivos_asegurar(): crea/actualiza la tabla de documentos del paciente (self-healing),
 *    con las columnas 'tipo' (p. ej. historia_fisica) y 'fecha_doc' (fecha de la historia en papel).
 */
require_once(__DIR__ . '/auditoria.php');

/**
 * Crea un paciente con datos mínimos. Devuelve ['ok'=>bool, 'id'=>int, 'error'=>string|null].
 * Rechaza duplicados por cédula o teléfono (activos): devuelve error 'DUPLICADO' y el id existente.
 */
function paciente_crear_minimo($c, $nombres, $apellidos, $cedula, $telefono, $feNac, $sex, $contexto = 'alta rápida') {
    $nombres = trim($nombres); $apellidos = trim($apellidos); $cedula = trim($cedula);
    $telefono = trim($telefono); $feNac = trim($feNac);
    $sex = ($sex === 'Fame') ? 'Fame' : 'Male';
    $dt = DateTime::createFromFormat('Y-m-d', $feNac);
    if ($nombres === '' || $apellidos === '' || !$dt || $dt->format('Y-m-d') !== $feNac) {
        return ['ok'=>false, 'id'=>0, 'error'=>'DATOS_INCOMPLETOS'];
    }
    $dup = $c->prepare("SELECT IDPACIENTE FROM AG_PACIENTE WHERE ESTADO='A' AND ((? <> '' AND CEDULA = ?) OR (? <> '' AND TELEFONO = ?)) LIMIT 1");
    $dup->bind_param('ssss', $cedula, $cedula, $telefono, $telefono); $dup->execute();
    $x = $dup->get_result()->fetch_assoc(); $dup->close();
    if ($x) return ['ok'=>false, 'id'=>(int)$x['IDPACIENTE'], 'error'=>'DUPLICADO'];

    $v = '';
    $ins = $c->prepare("INSERT INTO AG_PACIENTE (NOMBRES, APELLIDOS, EMAIL, FECHANACIMIENTO, TELEFONO, CEDULA, TITLE, SEX, GENDER, ESTADO, ADDRESS, NOTES, ADDNOTES)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'A', ?, ?, ?)");
    $ins->bind_param('ssssssssssss', $nombres, $apellidos, $v, $feNac, $telefono, $cedula, $v, $sex, $v, $v, $v, $v);
    $ok = $ins->execute(); $id = (int)$c->insert_id; $ins->close();
    if (!$ok) return ['ok'=>false, 'id'=>0, 'error'=>'DB'];
    auditar($c, 'Pacientes', 'crear', 'AG_PACIENTE', $id, 'Creó paciente ('.$contexto.'): '.trim($nombres.' '.$apellidos));
    return ['ok'=>true, 'id'=>$id, 'error'=>null];
}

function paciente_archivos_asegurar($c) {
    @$c->query("CREATE TABLE IF NOT EXISTS paciente_archivos (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        IDPACIENTE INT NOT NULL,
        titulo VARCHAR(255) NULL,
        nombre_original VARCHAR(255) NOT NULL,
        archivo VARCHAR(255) NOT NULL,
        mime VARCHAR(100) NULL,
        tamano INT NULL,
        id_usuario INT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        estado CHAR(1) NOT NULL DEFAULT 'A',
        INDEX idx_pac (IDPACIENTE, estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['tipo' => "VARCHAR(20) NULL", 'fecha_doc' => "DATE NULL"] as $col => $def) {
        $r = @$c->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='paciente_archivos' AND COLUMN_NAME='$col'");
        if ($r && (int)$r->fetch_assoc()['c'] === 0) @$c->query("ALTER TABLE paciente_archivos ADD COLUMN $col $def");
    }
}
