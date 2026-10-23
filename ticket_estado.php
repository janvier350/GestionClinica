<?php
/**
 * ticket_estado.php — Cambia estado/prioridad de un ticket. Sólo quien gestiona
 * (permiso panel.tickets: SISTEMA y la Dra.). Responde JSON.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
if (!modulo_activo('tickets')) { http_response_code(403); exit('Módulo desactivado'); }
require_once(__DIR__ . "/class/tickets.php");
require_once(__DIR__ . "/class/auditoria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["rol"])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (!tickets_puede_gestionar()) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }

tickets_ensure_tablas($conexion);
$cat = tickets_catalogos();

$id     = (int)($_POST['id'] ?? 0);
$estado = $_POST['estado'] ?? '';
$prio   = $_POST['prioridad'] ?? '';
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID']); exit; }
if (!in_array($estado, $cat['estado'], true))    { echo json_encode(['ok'=>false,'error'=>'ESTADO']); exit; }
if (!in_array($prio,   $cat['prioridad'], true)) { echo json_encode(['ok'=>false,'error'=>'PRIORIDAD']); exit; }

$cierra = in_array($estado, ['Resuelto','Cerrado'], true);
$sql = "UPDATE tickets SET estado=?, prioridad=?, fecha_actualizacion=NOW(), fecha_cierre=".($cierra?"COALESCE(fecha_cierre,NOW())":"NULL")." WHERE id=?";
$st = $conexion->prepare($sql);
$st->bind_param('ssi', $estado, $prio, $id);
if (!$st->execute()) { echo json_encode(['ok'=>false,'error'=>'Error: '.$st->error]); exit; }
$st->close();

auditar($conexion, 'Tickets', 'estado', 'TICKET', $id, 'Cambió estado a "'.$estado.'" / prioridad "'.$prio.'"');

echo json_encode(['ok'=>true]);
