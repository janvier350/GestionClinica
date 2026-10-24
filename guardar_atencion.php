<?php
/**
 * guardar_atencion.php — Guarda el informe de una atención (AG_HISTORIAL)
 * y marca la cita como Atendida SOLO si el informe se guardó correctamente.
 *
 * Comportamiento importante:
 *   - Usa prepare/bind_param (informe puede ser grande, hasta LONGTEXT).
 *   - UPSERT por IDCITA: si ya existe un historial para esa cita, se
 *     actualiza en vez de insertar una fila nueva (evita duplicados).
 *   - Si el INSERT/UPDATE falla, NO marca la cita como Atendida y
 *     devuelve el error real al frontend para que muestre un aviso.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once("class/auditoria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { echo 'SIN_SESION'; exit; }

$idCita  = isset($_POST['idCita'])  ? (int)$_POST['idCita']  : 0;
$informe = isset($_POST['informe']) ? (string)$_POST['informe'] : '';
$peso    = isset($_POST['peso'])    ? (float)$_POST['peso']   : 0.0;
$talla   = isset($_POST['talla'])   ? (float)$_POST['talla']  : 0.0;
$imc     = isset($_POST['imc'])     ? (float)$_POST['imc']    : 0.0;

if ($idCita <= 0 || trim(strip_tags($informe)) === '') {
    echo 'DATOS_INCOMPLETOS';
    exit;
}

// ¿Ya existe un historial para esta cita? — para hacer UPSERT y evitar duplicados
$chk = $conexion->prepare("SELECT IDHISTORIAL FROM AG_HISTORIAL WHERE IDCITA = ? LIMIT 1");
$chk->bind_param('i', $idCita);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if ($row && !empty($row['IDHISTORIAL'])) {
    // UPDATE del historial existente
    $idHist = (int)$row['IDHISTORIAL'];
    $stmt = $conexion->prepare(
        "UPDATE AG_HISTORIAL SET CONTENIDO_INFORME = ?, PESO = ?, TALLA = ?, IMC = ? WHERE IDHISTORIAL = ?"
    );
    // 's' para el informe (permite envíos grandes hasta max_allowed_packet)
    $stmt->bind_param('sdddi', $informe, $peso, $talla, $imc, $idHist);
} else {
    // INSERT nuevo
    $stmt = $conexion->prepare(
        "INSERT INTO AG_HISTORIAL (IDCITA, CONTENIDO_INFORME, PESO, TALLA, IMC) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('isddd', $idCita, $informe, $peso, $talla, $imc);
}

if (!$stmt->execute()) {
    // NO marcar la cita como Atendida si el informe no se pudo guardar
    $err = $stmt->error;
    $stmt->close();
    $conexion->close();
    // Log opcional para diagnosticar
    error_log('guardar_atencion.php - fallo al guardar informe idCita=' . $idCita . ' err=' . $err);
    echo 'ERROR: ' . $err;
    exit;
}
$stmt->close();

// Marcar la cita como Atendida (sólo llegamos aquí si el informe se guardó bien)
$updCita = $conexion->prepare("UPDATE AG_CITA SET ESTADO_CITA = 'A' WHERE IDCITA = ?");
$updCita->bind_param('i', $idCita);
if (!$updCita->execute()) {
    $err = $updCita->error;
    $updCita->close();
    $conexion->close();
    error_log('guardar_atencion.php - informe guardado pero fallo al marcar Atendida idCita=' . $idCita . ' err=' . $err);
    // El informe SÍ quedó guardado; sólo el marcado como Atendida falló.
    echo 'PARCIAL: ' . $err;
    exit;
}
$updCita->close();

// Atención rápida: si la cita nació de un turno, queda Atendido (listo para cobrar)
@require_once(__DIR__ . '/class/turnos.php');
if (function_exists('turno_cita_atendida')) turno_cita_atendida($conexion, $idCita);

// Si esta cita era el destino de una derivación, la derivación queda atendida (tabla opcional)
@$conexion->query("UPDATE derivacion SET ESTADO='Atendida' WHERE IDCITA_DESTINO = ".(int)$idCita." AND TIPO='PROFESIONAL'");

auditar($conexion, 'Atención', 'atender', 'AG_CITA', $idCita, 'Guardó la nota de consulta y marcó la cita como Atendida.');

$conexion->close();

echo 'OK';
