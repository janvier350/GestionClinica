<?php
/**
 * paciente_telefono_guardar.php — Corrige el teléfono de un paciente desde el
 * modal de la cita (para enviar WhatsApp al número correcto). POST: idPaciente, telefono.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/auditoria.php");
header('Content-Type: application/json; charset=utf-8');
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION['rol'])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }

$id  = (int)($_POST['idPaciente'] ?? 0);
$tel = trim((string)($_POST['telefono'] ?? ''));
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'PACIENTE_INVALIDO']); exit; }
if (!preg_match('/^[0-9+()\-\s.]{7,25}$/', $tel)) { echo json_encode(['ok'=>false,'error'=>'TELEFONO_INVALIDO']); exit; }

$st = $conexion->prepare("UPDATE AG_PACIENTE SET TELEFONO = ? WHERE IDPACIENTE = ? AND ESTADO='A'");
$st->bind_param('si', $tel, $id);
$ok = $st->execute() && $st->affected_rows >= 0; $st->close();
if ($ok) auditar($conexion, 'Pacientes', 'editar', 'AG_PACIENTE', $id, 'Actualizó el teléfono desde el calendario (WhatsApp)');
echo json_encode(['ok'=>(bool)$ok]);
