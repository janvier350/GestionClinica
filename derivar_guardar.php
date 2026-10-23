<?php
/**
 * derivar_guardar.php — Crea una derivación desde la pantalla de atención.
 * POST: idCita, tipo (PROFESIONAL|SUERO), motivo, idDoctorDestino, items[][producto|dosis|via|indicaciones]
 * Responde JSON {ok, id, idCitaDestino} o {ok:false, error}.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/derivacion.php");
header('Content-Type: application/json; charset=utf-8');
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION['rol'])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (!puede('derivar.crear')) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }

$res = derivacion_crear($conexion, (int)($_POST['idCita'] ?? 0), [
    'tipo'            => $_POST['tipo'] ?? '',
    'motivo'          => $_POST['motivo'] ?? '',
    'idDoctorDestino' => $_POST['idDoctorDestino'] ?? 0,
    'items'           => $_POST['items'] ?? [],
], (int)($_SESSION['iduser'] ?? 0));
echo json_encode($res, JSON_UNESCAPED_UNICODE);
