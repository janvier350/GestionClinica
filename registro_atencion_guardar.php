<?php
/**
 * registro_atencion_guardar.php — Guarda el registro de atención (formato clínica) de una cita.
 * POST: idCita, servicio, fecha, tratante, items[][indicacion|cantidad|recomendacion]. JSON.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/registro_atencion.php");
header('Content-Type: application/json; charset=utf-8');
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION['rol'])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (!puede('atencion.registro')) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }
echo json_encode(registro_guardar($conexion, (int)($_POST['idCita'] ?? 0), $_POST['servicio'] ?? '', $_POST['fecha'] ?? '',
    $_POST['tratante'] ?? '', $_POST['items'] ?? [], (int)($_SESSION['iduser'] ?? 0)), JSON_UNESCAPED_UNICODE);
