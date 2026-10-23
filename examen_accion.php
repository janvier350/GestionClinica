<?php
/**
 * examen_accion.php — Órdenes de examen (JSON).
 * POST accion=crear   : idPaciente, idCita?, tipo, examen, indicacion      (examenes.ordenar)
 * POST accion=realizar|cancelar : idExamen, resultado?, fecha_hora?         (examenes.registrar)
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/enfermeria.php");
header('Content-Type: application/json; charset=utf-8');
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION['rol'])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }
enfermeria_asegurar_tablas($conexion);
$uid = (int)($_SESSION['iduser'] ?? 0);
$acc = $_POST['accion'] ?? '';

if ($acc === 'crear') {
    if (!puede('examenes.ordenar')) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; }
    echo json_encode(examen_crear($conexion, (int)($_POST['idPaciente'] ?? 0), (int)($_POST['idCita'] ?? 0), $_POST['tipo'] ?? '', $_POST['examen'] ?? '', $_POST['indicacion'] ?? '', $uid), JSON_UNESCAPED_UNICODE);
} elseif ($acc === 'realizar' || $acc === 'cancelar') {
    if (!puede('examenes.registrar')) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; }
    $ok = examen_cambiar_estado($conexion, (int)($_POST['idExamen'] ?? 0), $acc === 'realizar' ? 'Realizado' : 'Cancelado', $_POST['resultado'] ?? '', $_POST['fecha_hora'] ?? '', $uid);
    echo json_encode(['ok'=>$ok, 'error'=>$ok ? null : 'NO_ACTUALIZADO']);
} else { echo json_encode(['ok'=>false,'error'=>'ACCION']); }
