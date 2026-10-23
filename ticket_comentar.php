<?php
/**
 * ticket_comentar.php — Agrega un comentario (con adjuntos opcionales) a un
 * ticket. Puede comentar quien gestiona (panel.tickets) o el solicitante del
 * ticket. Responde JSON {ok}.
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
$idUser = (int)($_SESSION['iduser'] ?? 0);
$id     = (int)($_POST['id'] ?? 0);
$txt    = trim($_POST['comentario'] ?? '');
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID']); exit; }

// Verificar acceso: gestor o dueño del ticket.
$q = $conexion->prepare("SELECT id_solicitante FROM tickets WHERE id=? LIMIT 1");
$q->bind_param('i', $id); $q->execute();
$row = $q->get_result()->fetch_assoc(); $q->close();
if (!$row) { echo json_encode(['ok'=>false,'error'=>'NO_EXISTE']); exit; }
if (!tickets_puede_gestionar() && (int)$row['id_solicitante'] !== $idUser) {
    echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit;
}

$hayArch = (!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name']) && count(array_filter($_FILES['adjuntos']['name'])) > 0);
if ($txt === '' && !$hayArch) { echo json_encode(['ok'=>false,'error'=>'VACIO']); exit; }

$nombre = trim((($_SESSION['nombres'] ?? '') . ' ' . ($_SESSION['apellidos'] ?? '')));
if ($nombre === '') $nombre = (string)($_SESSION['username'] ?? '');
$nombre = mb_substr($nombre, 0, 160);
$rol    = (string)($_SESSION['rol'] ?? '');
$cuerpo = $txt !== '' ? $txt : '(adjunto)';

$ins = $conexion->prepare("INSERT INTO ticket_comentarios (id_ticket, id_usuario, usuario, rol, comentario) VALUES (?,?,?,?,?)");
$ins->bind_param('iisss', $id, $idUser, $nombre, $rol, $cuerpo);
if (!$ins->execute()) { echo json_encode(['ok'=>false,'error'=>'Error: '.$ins->error]); exit; }
$idCom = (int)$conexion->insert_id;
$ins->close();

if ($hayArch) {
    $n = count($_FILES['adjuntos']['name']);
    for ($i = 0; $i < $n; $i++) {
        $f = [
            'name'     => $_FILES['adjuntos']['name'][$i],
            'type'     => $_FILES['adjuntos']['type'][$i],
            'tmp_name' => $_FILES['adjuntos']['tmp_name'][$i],
            'error'    => $_FILES['adjuntos']['error'][$i],
            'size'     => $_FILES['adjuntos']['size'][$i],
        ];
        tickets_guardar_adjunto($conexion, $f, $id, $idCom);
    }
}

// Marca actualización del ticket
@$conexion->query("UPDATE tickets SET fecha_actualizacion=NOW() WHERE id=".(int)$id);

auditar($conexion, 'Tickets', 'editar', 'TICKET', $id, 'Comentó en la solicitud');

echo json_encode(['ok'=>true]);
