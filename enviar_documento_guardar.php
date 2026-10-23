<?php
session_start();
date_default_timezone_set('America/New_York');
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/mailer.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["rol"])) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'No autorizado']); exit; }

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

$idDoc = (int)($_POST['id_documento'] ?? 0);
$idPac = (int)($_POST['id_paciente'] ?? 0);
if (!$idDoc || !$idPac) { echo json_encode(['ok'=>false,'msg'=>'Datos incompletos.']); exit; }

// Documento
$stmtD = $conexion->prepare("SELECT titulo FROM documentos WHERE id_documento = ? AND estado = 1");
$stmtD->bind_param("i", $idDoc);
$stmtD->execute();
$doc = $stmtD->get_result()->fetch_assoc();
$stmtD->close();
if (!$doc) { echo json_encode(['ok'=>false,'msg'=>'Documento no encontrado o inactivo.']); exit; }

// Paciente
$stmtP = $conexion->prepare("SELECT NOMBRES, APELLIDOS, EMAIL FROM AG_PACIENTE WHERE IDPACIENTE = ?");
$stmtP->bind_param("i", $idPac);
$stmtP->execute();
$pac = $stmtP->get_result()->fetch_assoc();
$stmtP->close();
if (!$pac) { echo json_encode(['ok'=>false,'msg'=>'Paciente no encontrado.']); exit; }

$correo = trim($pac['EMAIL'] ?? '');
$nombrePac = trim(($pac['NOMBRES'] ?? '') . ' ' . ($pac['APELLIDOS'] ?? ''));
if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false,'msg'=>'El paciente no tiene un correo válido registrado.']); exit;
}

// Generar token y código
$token  = bin2hex(random_bytes(16));            // 32 caracteres
$codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

// Guardar el envío
$fechaEnvio = date('Y-m-d H:i:s');
$stmtI = $conexion->prepare(
    "INSERT INTO documento_envio (id_documento, IDPACIENTE, token, codigo, estado, fecha_envio)
     VALUES (?, ?, ?, ?, 'Pendiente', ?)"
);
$stmtI->bind_param("iisss", $idDoc, $idPac, $token, $codigo, $fechaEnvio);
if (!$stmtI->execute()) {
    echo json_encode(['ok'=>false,'msg'=>'Error al registrar el envío: ' . $stmtI->error]); exit;
}
$stmtI->close();

// Construir el link de firma
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$link   = $base . '/firmar.php?t=' . $token;

// ── Enviar correo ────────────────────────────────────────────────────
$brand  = mail_brand();
$brandH = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
$htmlBody = "
<div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;'>
  <div style='background:#5a2d82;color:#fff;padding:22px;border-radius:8px 8px 0 0;text-align:center;'>
    <h2 style='margin:0;'>Documento para firmar</h2>
    <p style='margin:6px 0 0;color:#e0c8f8;'>{$brandH}</p>
  </div>
  <div style='border:1px solid #eee;border-top:none;padding:24px;border-radius:0 0 8px 8px;'>
    <p>Hola, <strong>" . htmlspecialchars($nombrePac) . "</strong>:</p>
    <p>Tienes un documento pendiente de revisar y firmar: <strong>" . htmlspecialchars($doc['titulo']) . "</strong>.</p>
    <p>Para abrirlo, haz clic en el botón e ingresa el código de acceso:</p>
    <p style='text-align:center;margin:24px 0;'>
      <a href='" . htmlspecialchars($link) . "' style='background:#6f42c1;color:#fff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:bold;'>Abrir documento</a>
    </p>
    <p style='text-align:center;'>Código de acceso:<br>
      <span style='font-size:26px;font-weight:bold;letter-spacing:4px;color:#5a2d82;'>" . htmlspecialchars($codigo) . "</span>
    </p>
    <p style='font-size:12px;color:#888;margin-top:24px;'>Si no esperabas este mensaje, puedes ignorarlo.</p>
  </div>
</div>";

$textBody = "Hola $nombrePac,\n\nTienes un documento para firmar: " . $doc['titulo'] . "\n\n"
          . "Ábrelo aquí: $link\nCódigo de acceso: $codigo\n\n$brand";

$mail = new PHPMailer(true);
try {
    mailer_configurar($mail);   // SMTP + remitente desde Panel de Control → Correo

    $mail->addAddress($correo, $nombrePac);
    $mail->isHTML(true);
    $mail->Subject = '=?UTF-8?B?' . base64_encode('Documento para firmar - ' . $brand) . '?=';
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;
    $mail->send();

    echo json_encode(['ok'=>true,'msg'=>'Documento enviado a ' . $correo, 'link'=>$link, 'codigo'=>$codigo, 'correo'=>$correo]);
} catch (Exception $e) {
    error_log("Envio documento - PHPMailer: " . $e->getMessage());
    // El envío quedó registrado; solo el correo falló. Devolvemos link+código para compartir manualmente.
    echo json_encode([
        'ok'=>true,
        'correo_fallo'=>true,
        'msg'=>'El registro se creó, pero no se pudo enviar el correo. Comparte el link y el código manualmente.',
        'link'=>$link, 'codigo'=>$codigo, 'correo'=>$correo
    ]);
}
$conexion->close();
