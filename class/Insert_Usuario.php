<?php
require_once("funciones.php");
require_once("conexionBD.php");
require_once(__DIR__ . "/mailer.php");
$conexion = conectarse();
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';

// Capturar datos del formulario
$nombres   = trim($_POST['nombres']   ?? '');
$apellidos = trim($_POST['apellidos'] ?? '');
$telefono  = trim($_POST['telefono']  ?? '');
$correo    = trim($_POST['correo']    ?? '');
$Idrol     = (int)($_POST['Idrol']    ?? 0);
$usuario   = trim($_POST['usuario']   ?? '');
$clave     = $_POST['clave']          ?? '';
$idAgencia = 1;
$imagen    = 'https://mdbootstrap.com/img/new/avatars/8.jpg';

if ($nombres === '' || $apellidos === '' || $usuario === '' || $clave === '' || !$Idrol) {
    echo "<script>alert('Datos incompletos. Complete todos los campos.'); window.location.href = '../PNC_UsuarioCrear.php';</script>";
    exit();
}

$cifrar = password_hash($clave, PASSWORD_DEFAULT);

// Validar si el usuario ya existe
$stmtV = $conexion->prepare("SELECT IDADM_USUARIO FROM ADM_USUARIO WHERE USUARIO = ? AND ESTADO = 'A'");
$stmtV->bind_param("s", $usuario);
$stmtV->execute();
$stmtV->store_result();
if ($stmtV->num_rows > 0) {
    $stmtV->close();
    echo "<script>alert('Usuario ya existe!'); window.location.href = '../PNC_UsuarioCrear.php';</script>";
    exit();
}
$stmtV->close();

// ¿Existe la columna CORREO? (la agrega migrar_correo_usuario.php)
$dbName = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
$tieneCorreo = (int)$conexion->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='ADM_USUARIO' AND COLUMN_NAME='CORREO'"
)->fetch_assoc()['c'] > 0;

// Insertar nuevo usuario
if ($tieneCorreo) {
    $stmt = $conexion->prepare(
        "INSERT INTO ADM_USUARIO (NOMBRES, APELLIDOS, TELEFONO, CORREO, USUARIO, CONTRASENA, IDADM_ROL, IDAGENCIA, IMG, ESTADO)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'A')"
    );
    $stmt->bind_param("ssssssiis", $nombres, $apellidos, $telefono, $correo, $usuario, $cifrar, $Idrol, $idAgencia, $imagen);
} else {
    $stmt = $conexion->prepare(
        "INSERT INTO ADM_USUARIO (NOMBRES, APELLIDOS, TELEFONO, USUARIO, CONTRASENA, IDADM_ROL, IDAGENCIA, IMG, ESTADO)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'A')"
    );
    $stmt->bind_param("sssssiis", $nombres, $apellidos, $telefono, $usuario, $cifrar, $Idrol, $idAgencia, $imagen);
}

if (!$stmt->execute()) {
    $err = addslashes($stmt->error);
    $stmt->close();
    echo "<script>alert('Error al insertar usuario: $err'); window.location.href = '../PNC_UsuarioCrear.php';</script>";
    exit();
}
$stmt->close();

// ── Enviar correo de bienvenida (si se indicó un correo) ────────────
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    // Sin correo válido: usuario creado, no se envía nada
    echo "<script>alert('Usuario creado correctamente.'); window.location.href = '../PNC_UsuarioCrear.php';</script>";
    exit();
}

// Nombre del rol (para el correo)
$rolNombre = '';
$stmtR = $conexion->prepare("SELECT CARGO FROM ADM_ROL WHERE IDADM_ROL = ?");
$stmtR->bind_param("i", $Idrol);
$stmtR->execute();
$rowR = $stmtR->get_result()->fetch_assoc();
$stmtR->close();
$rolNombre = $rowR['CARGO'] ?? '';

// Enlace de la aplicación (login) calculado dinámicamente
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'srossnutritions.com';
$appDir  = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/HealthSchedule/class/Insert_Usuario.php')));
$appDir  = rtrim($appDir, '/');
$appUrl  = $scheme . '://' . $host . $appDir . '/index.php';

$nombreCompleto = trim($nombres . ' ' . $apellidos);

$brand  = mail_brand();
$brandH = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
$htmlBody = "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6f9;padding:30px 0;'>
    <tr><td align='center'>
      <table width='580' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);'>

        <!-- Encabezado -->
        <tr>
          <td style='background:#5a2d82;padding:28px 32px;text-align:center;'>
            <h1 style='color:#ffffff;margin:0;font-size:22px;'>¡Bienvenido/a!</h1>
            <p style='color:#e0c8f8;margin:6px 0 0;font-size:13px;'>{$brandH}</p>
          </td>
        </tr>

        <!-- Saludo -->
        <tr>
          <td style='padding:28px 32px 10px;'>
            <p style='font-size:15px;color:#333;margin:0;'>
              Hola, <strong>" . htmlspecialchars($nombreCompleto) . "</strong>
            </p>
            <p style='font-size:14px;color:#555;margin:10px 0 0;'>
              Se ha creado tu cuenta en el Sistema de Citas de {$brandH}. Ya puedes ingresar con estos datos:
            </p>
          </td>
        </tr>

        <!-- Credenciales -->
        <tr>
          <td style='padding:16px 32px;'>
            <table width='100%' cellpadding='0' cellspacing='0'
                   style='background:#f9f5ff;border-radius:6px;border-left:4px solid #5a2d82;'>
              <tr>
                <td style='padding:16px 20px;'>
                  <table width='100%' cellpadding='6' cellspacing='0' style='font-size:14px;color:#333;'>
                    <tr>
                      <td style='width:40%;color:#888;'>👤 Usuario</td>
                      <td><strong>" . htmlspecialchars($usuario) . "</strong></td>
                    </tr>
                    <tr>
                      <td style='color:#888;'>🔑 Contraseña</td>
                      <td><strong>" . htmlspecialchars($clave) . "</strong></td>
                    </tr>
                    " . ($rolNombre ? "
                    <tr>
                      <td style='color:#888;'>🎫 Rol</td>
                      <td><strong>" . htmlspecialchars($rolNombre) . "</strong></td>
                    </tr>" : "") . "
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Botón de acceso -->
        <tr>
          <td style='padding:8px 32px 4px;text-align:center;'>
            <a href='" . htmlspecialchars($appUrl) . "'
               style='display:inline-block;background:#5a2d82;color:#ffffff;text-decoration:none;
                      padding:12px 28px;border-radius:6px;font-size:15px;font-weight:bold;'>
              Ingresar a la aplicación
            </a>
          </td>
        </tr>
        <tr>
          <td style='padding:6px 32px 20px;text-align:center;'>
            <p style='font-size:12px;color:#999;margin:0;'>
              O copia este enlace: <br>
              <a href='" . htmlspecialchars($appUrl) . "' style='color:#5a2d82;'>" . htmlspecialchars($appUrl) . "</a>
            </p>
          </td>
        </tr>

        <!-- Recomendación -->
        <tr>
          <td style='padding:0 32px 24px;'>
            <p style='font-size:13px;color:#777;margin:0;'>
              Por seguridad, te recomendamos cambiar tu contraseña después de tu primer ingreso y no compartir estos datos.
            </p>
          </td>
        </tr>

        <!-- Pie -->
        <tr>
          <td style='background:#f0e8fa;padding:16px 32px;text-align:center;'>
            <p style='margin:0;font-size:12px;color:#999;'>
              Este correo fue generado automáticamente por el Sistema de Citas {$brandH}.<br>
              Por favor no responda a este mensaje.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>";

$textBody = "Hola $nombreCompleto,\n\n"
          . "Se ha creado tu cuenta en el Sistema de Citas de $brand.\n"
          . "Usuario: $usuario\n"
          . "Contraseña: $clave\n"
          . ($rolNombre ? "Rol: $rolNombre\n" : "")
          . "\nIngresa aquí: $appUrl\n"
          . "\nPor seguridad, cambia tu contraseña tras el primer ingreso.\n$brand";

$mail = new PHPMailer(true);
try {
    mailer_configurar($mail);   // SMTP + remitente desde Panel de Control → Correo


    $mail->addAddress($correo, $nombreCompleto);

    $mail->isHTML(true);
    $mail->Subject  = '=?UTF-8?B?' . base64_encode('Bienvenido a ' . $brand) . '?=';
    $mail->Body     = $htmlBody;
    $mail->AltBody  = $textBody;

    $mail->send();

    echo "<script>alert('Usuario creado y correo de bienvenida enviado a $correo'); window.location.href = '../PNC_UsuarioCrear.php';</script>";
} catch (Exception $e) {
    // El usuario YA fue creado; solo el correo falló
    error_log("PHPMailer error (bienvenida) para $correo: " . $mail->ErrorInfo);
    echo "<script>alert('Usuario creado correctamente.\\nNota: no se pudo enviar el correo de bienvenida.'); window.location.href = '../PNC_UsuarioCrear.php';</script>";
}

$conexion->close();
?>
