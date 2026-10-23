<?php
/**
 * paciente_archivo_subir.php — Sube un documento (PDF/imagen) de un paciente.
 * Guarda el archivo en una carpeta protegida (no accesible por web) y registra
 * la fila en paciente_archivos. Requiere sesión y permiso pac.archivos.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/pacientes_util.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["rol"])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (!puede('pac.archivos') && !puede('pac.historias')) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; }

$idPaciente = (int)($_POST['idPaciente'] ?? 0);
$titulo     = trim($_POST['titulo'] ?? '');
$tipo       = (($_POST['tipo'] ?? '') === 'historia_fisica') ? 'historia_fisica' : null;
$fechaDoc   = trim($_POST['fecha_doc'] ?? '');
if ($fechaDoc !== '') { $fd = DateTime::createFromFormat('Y-m-d', $fechaDoc); $fechaDoc = ($fd && $fd->format('Y-m-d') === $fechaDoc) ? $fechaDoc : ''; }
paciente_archivos_asegurar($conexion);
if ($idPaciente <= 0) { echo json_encode(['ok'=>false,'error'=>'PACIENTE_INVALIDO']); exit; }
if (!isset($_FILES['archivo'])) { echo json_encode(['ok'=>false,'error'=>'SIN_ARCHIVO']); exit; }

$f = $_FILES['archivo'];
if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Error de subida ('.(int)$f['error'].')']); exit; }
if ($f['size'] > 25 * 1024 * 1024) { echo json_encode(['ok'=>false,'error'=>'El archivo supera el límite de 25 MB.']); exit; }

// Validar tipo real por contenido (no confiar en la extensión enviada).
$permitidos = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
];
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $f['tmp_name']);
    finfo_close($fi);
}
if (!isset($permitidos[$mime])) { echo json_encode(['ok'=>false,'error'=>'Formato no permitido. Use PDF, JPG, PNG o WEBP.']); exit; }
$ext = $permitidos[$mime];

// Verificar que el paciente exista
$chk = $conexion->prepare("SELECT IDPACIENTE FROM AG_PACIENTE WHERE IDPACIENTE=? LIMIT 1");
$chk->bind_param('i', $idPaciente); $chk->execute();
if (!$chk->get_result()->fetch_assoc()) { $chk->close(); echo json_encode(['ok'=>false,'error'=>'PACIENTE_NO_EXISTE']); exit; }
$chk->close();

// Carpeta protegida (fuera de la vista pública por .htaccess).
$dir = __DIR__ . '/documentos_pacientes';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    echo json_encode(['ok'=>false,'error'=>'No se pudo crear la carpeta de documentos.']); exit;
}

// Nombre de almacenamiento aleatorio (no adivinable). Se sirve sólo por el visor.
try { $rand = bin2hex(random_bytes(8)); } catch (Exception $e) { $rand = substr(md5(uniqid('', true)), 0, 16); }
$almacen = 'doc_' . $idPaciente . '_' . time() . '_' . $rand . '.' . $ext;
$destAbs = $dir . '/' . $almacen;

if (!move_uploaded_file($f['tmp_name'], $destAbs)) {
    echo json_encode(['ok'=>false,'error'=>'No se pudo guardar el archivo.']); exit;
}
@chmod($destAbs, 0640);

$nombreOrig = mb_substr((string)$f['name'], 0, 255);
if ($titulo === '') $titulo = preg_replace('/\.[A-Za-z0-9]+$/', '', $nombreOrig);
$titulo = mb_substr($titulo, 0, 255);
$tam    = (int)$f['size'];
$idUser = (int)($_SESSION['iduser'] ?? 0);

$ins = $conexion->prepare(
    "INSERT INTO paciente_archivos (IDPACIENTE, titulo, nombre_original, archivo, mime, tamano, id_usuario, tipo, fecha_doc)
     VALUES (?,?,?,?,?,?,?,?,NULLIF(?,''))"
);
$ins->bind_param('issssiiss', $idPaciente, $titulo, $nombreOrig, $almacen, $mime, $tam, $idUser, $tipo, $fechaDoc);
if (!$ins->execute()) {
    @unlink($destAbs);
    echo json_encode(['ok'=>false,'error'=>'Error al registrar: '.$ins->error]); exit;
}
$nuevoId = (int)$conexion->insert_id;
$ins->close();

auditar($conexion, 'Documentos', 'agregar', 'AG_PACIENTE', $idPaciente, 'Subió documento: '.$nombreOrig);

echo json_encode(['ok'=>true, 'id'=>$nuevoId]);
