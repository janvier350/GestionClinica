<?php
/**
 * ticket_guardar.php — Crea una nueva solicitud/ticket. Cualquier usuario con
 * sesión puede crear. Acepta adjuntos (adjuntos[]). Responde JSON {ok,id}.
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }

tickets_ensure_tablas($conexion);
$cat = tickets_catalogos();

$titulo = trim($_POST['titulo'] ?? '');
$desc   = trim($_POST['descripcion'] ?? '');
$modulo = in_array($_POST['modulo'] ?? '', $cat['modulo'], true) ? $_POST['modulo'] : 'Otro';
$tipo   = in_array($_POST['tipo'] ?? '', $cat['tipo'], true) ? $_POST['tipo'] : 'Error';
$prio   = in_array($_POST['prioridad'] ?? '', $cat['prioridad'], true) ? $_POST['prioridad'] : 'Media';

if ($titulo === '') { echo json_encode(['ok'=>false,'error'=>'El título es obligatorio.']); exit; }
$titulo = mb_substr($titulo, 0, 160);

$idUser = (int)($_SESSION['iduser'] ?? 0);
$nombre = trim((($_SESSION['nombres'] ?? '') . ' ' . ($_SESSION['apellidos'] ?? '')));
if ($nombre === '') $nombre = (string)($_SESSION['username'] ?? '');
$nombre = mb_substr($nombre, 0, 160);
$rol    = (string)($_SESSION['rol'] ?? '');

$ins = $conexion->prepare(
    "INSERT INTO tickets (titulo, descripcion, modulo, tipo, prioridad, estado, id_solicitante, solicitante, rol_solicitante)
     VALUES (?,?,?,?,?, 'Abierto', ?,?,?)"
);
$ins->bind_param('sssssiss', $titulo, $desc, $modulo, $tipo, $prio, $idUser, $nombre, $rol);
if (!$ins->execute()) { echo json_encode(['ok'=>false,'error'=>'Error al guardar: '.$ins->error]); exit; }
$idTicket = (int)$conexion->insert_id;
$ins->close();

// Adjuntos
$nAdj = 0;
if (!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name'])) {
    $n = count($_FILES['adjuntos']['name']);
    for ($i = 0; $i < $n; $i++) {
        $f = [
            'name'     => $_FILES['adjuntos']['name'][$i],
            'type'     => $_FILES['adjuntos']['type'][$i],
            'tmp_name' => $_FILES['adjuntos']['tmp_name'][$i],
            'error'    => $_FILES['adjuntos']['error'][$i],
            'size'     => $_FILES['adjuntos']['size'][$i],
        ];
        if (tickets_guardar_adjunto($conexion, $f, $idTicket, null)) $nAdj++;
    }
}

auditar($conexion, 'Tickets', 'crear', 'TICKET', $idTicket, 'Creó solicitud: '.$titulo.($nAdj?" (+$nAdj adj.)":''));

echo json_encode(['ok'=>true, 'id'=>$idTicket]);
