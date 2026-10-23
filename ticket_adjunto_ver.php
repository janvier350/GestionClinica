<?php
/**
 * ticket_adjunto_ver.php — Sirve un adjunto de ticket de forma segura.
 * Acceso: quien gestiona (panel.tickets) o el solicitante del ticket.
 * Uso: ticket_adjunto_ver.php?id=123[&dl=1]
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
if (!modulo_activo('tickets')) { http_response_code(403); exit('Módulo desactivado'); }
require_once(__DIR__ . "/class/tickets.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { http_response_code(403); exit('No autorizado'); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); http_response_code(403); exit('Sesión expirada'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Solicitud inválida'); }

$st = $conexion->prepare(
    "SELECT a.nombre_original, a.archivo, a.mime, t.id_solicitante
       FROM ticket_adjuntos a INNER JOIN tickets t ON t.id = a.id_ticket
      WHERE a.id=? LIMIT 1"
);
$st->bind_param('i', $id); $st->execute();
$row = $st->get_result()->fetch_assoc(); $st->close();
if (!$row) { http_response_code(404); exit('No encontrado'); }

$idUser = (int)($_SESSION['iduser'] ?? 0);
if (!tickets_puede_gestionar() && (int)$row['id_solicitante'] !== $idUser) { http_response_code(403); exit('Sin permiso'); }

$base = basename($row['archivo']);
$abs  = __DIR__ . '/tickets_adjuntos/' . $base;
if (!is_file($abs)) { http_response_code(404); exit('Archivo no disponible'); }

$mime   = $row['mime'] ?: 'application/octet-stream';
$dl     = isset($_GET['dl']) && $_GET['dl'] == '1';
$disp   = $dl ? 'attachment' : 'inline';
$nombre = preg_replace('/[\r\n"]/', '', (string)($row['nombre_original'] ?: $base));

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
readfile($abs);
exit;
