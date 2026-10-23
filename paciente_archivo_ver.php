<?php
/**
 * paciente_archivo_ver.php — Sirve un documento de paciente de forma segura.
 * Los archivos NO son accesibles por URL directa; sólo aquí, validando sesión,
 * expiración y permiso pac.archivos, y registrando el acceso en la auditoría.
 * Uso: paciente_archivo_ver.php?id=123        (ver en el navegador)
 *      paciente_archivo_ver.php?id=123&dl=1    (descargar)
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/auditoria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { http_response_code(403); exit('No autorizado'); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); http_response_code(403); exit('Sesión expirada'); }
if (!puede('pac.archivos') && !puede('pac.historias')) { http_response_code(403); exit('Sin permiso'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Solicitud inválida'); }

$st = $conexion->prepare("SELECT IDPACIENTE, nombre_original, archivo, mime FROM paciente_archivos WHERE id=? AND estado='A' LIMIT 1");
$st->bind_param('i', $id); $st->execute();
$row = $st->get_result()->fetch_assoc(); $st->close();
if (!$row) { http_response_code(404); exit('No encontrado'); }

// Ruta segura: sólo el nombre base dentro de la carpeta protegida.
$base = basename($row['archivo']);
$abs  = __DIR__ . '/documentos_pacientes/' . $base;
if (!is_file($abs)) { http_response_code(404); exit('Archivo no disponible'); }

// Registrar el acceso (importante para HIPAA: quién vio qué).
auditar($conexion, 'Documentos', 'ver', 'AG_PACIENTE', (int)$row['IDPACIENTE'], 'Abrió documento: '.$row['nombre_original']);

$mime = $row['mime'] ?: 'application/octet-stream';
$dl   = isset($_GET['dl']) && $_GET['dl'] == '1';
$disp = $dl ? 'attachment' : 'inline';
$nombre = preg_replace('/[\r\n"]/', '', (string)$row['nombre_original']);

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
readfile($abs);
exit;
