<?php
/**
 * paciente_crear_rapido.php — Alta rápida de paciente (JSON) para historias escaneadas.
 * POST: nombres, apellidos, cedula, telefono, feNac (Y-m-d), sex. Requiere pac.historias.
 * Responde {ok, id} o {ok:false, error, id?} (DUPLICADO devuelve el id existente).
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/pacientes_util.php");
header('Content-Type: application/json; charset=utf-8');
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION['rol'])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (!puede('pac.historias')) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }
echo json_encode(paciente_crear_minimo($conexion, $_POST['nombres'] ?? '', $_POST['apellidos'] ?? '', $_POST['cedula'] ?? '',
    $_POST['telefono'] ?? '', $_POST['feNac'] ?? '', $_POST['sex'] ?? '', 'historia escaneada'), JSON_UNESCAPED_UNICODE);
