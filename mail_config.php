<?php
/**
 * mail_config.php — Configuración del correo que se envía a pacientes y usuarios.
 * Solo SISTEMA. Guarda en app_config (claves 'mail.*', contraseña cifrada) vía
 * class/mailer.php. Incluye envío de prueba.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/mailer.php");
require_once(__DIR__ . "/class/whatsapp.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
if (strtoupper($_SESSION["rol"]) !== 'SISTEMA') { header("Location: break.php"); exit(); }

$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$msg = ''; $msgTipo = ''; $debug = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar';
    if ($accion === 'guardar') {
        $v = [
            'enabled'     => isset($_POST['enabled']) ? '1' : '0',
            'brand'       => trim($_POST['brand'] ?? ''),
            'host'        => trim($_POST['host'] ?? ''),
            'port'        => trim($_POST['port'] ?? ''),
            'secure'      => $_POST['secure'] ?? 'ssl',
            'user'        => trim($_POST['user'] ?? ''),
            'pass'        => (string)($_POST['pass'] ?? ''),
            'from_email'  => trim($_POST['from_email'] ?? ''),
            'from_name'   => trim($_POST['from_name'] ?? ''),
            'reply_email' => trim($_POST['reply_email'] ?? ''),
            'reply_name'  => trim($_POST['reply_name'] ?? ''),
            'verify_ssl'  => isset($_POST['verify_ssl']) ? '1' : '0',
            'footer_es'   => trim($_POST['footer_es'] ?? ''),
            'footer_en'   => trim($_POST['footer_en'] ?? ''),
        ];
        $err = '';
        if (!in_array($v['secure'], ['ssl','tls','none'], true)) $err = $en ? 'Invalid security type.' : 'Tipo de seguridad no válido.';
        elseif ($v['host'] !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $v['host'])) $err = $en ? 'Invalid server name.' : 'Servidor no válido.';
        elseif ($v['port'] !== '' && (!ctype_digit($v['port']) || (int)$v['port'] < 1 || (int)$v['port'] > 65535)) $err = $en ? 'Invalid port.' : 'Puerto no válido.';
        elseif ($v['from_email'] !== '' && !filter_var($v['from_email'], FILTER_VALIDATE_EMAIL)) $err = $en ? 'Invalid sender email.' : 'Correo remitente no válido.';
        elseif ($v['reply_email'] !== '' && !filter_var($v['reply_email'], FILTER_VALIDATE_EMAIL)) $err = $en ? 'Invalid reply-to email.' : 'Correo "responder a" no válido.';
        if ($err) { $msg = $err; $msgTipo = 'danger'; }
        elseif (mail_cfg_guardar($conexion, $v)) {
            auditar($conexion, 'Configuración', 'editar', 'CONFIG', null, 'Actualizó la configuración de correo (servidor '.$v['host'].', remitente '.$v['from_email'].($v['pass'] !== '' ? ', cambió la contraseña' : '').')');
            $msg = $en ? 'Email settings saved.' : 'Configuración de correo guardada.'; $msgTipo = 'success';
        } else { $msg = $en ? 'Could not save.' : 'No se pudo guardar.'; $msgTipo = 'danger'; }
    } elseif ($accion === 'whatsapp') {
        [$okWa, $errWa] = wa_cfg_guardar($conexion, $_POST['wa_country'] ?? '', trim($_POST['wa_number'] ?? ''));
        if ($okWa) {
            auditar($conexion, 'Configuración', 'editar', 'CONFIG', null, 'Actualizó WhatsApp (país +'.wa_pais().', número de la clínica '.(wa_numero() !== '' ? '+'.wa_numero() : 'sin definir').')');
            $msg = $en ? 'WhatsApp settings saved.' : 'Configuración de WhatsApp guardada.'; $msgTipo = 'success';
        } else {
            $msg = $errWa === 'COUNTRY' ? ($en ? 'Invalid country code (digits only, e.g. 593).' : 'Código de país no válido (solo dígitos, ej. 593).')
                 : ($errWa === 'NUMBER' ? ($en ? 'Invalid WhatsApp number.' : 'Número de WhatsApp no válido.') : ($en ? 'Could not save.' : 'No se pudo guardar.'));
            $msgTipo = 'danger';
        }
    } elseif ($accion === 'probar') {
        $dest = trim($_POST['destino'] ?? '');
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) { $msg = $en ? 'Enter a valid test address.' : 'Ingresa un correo de prueba válido.'; $msgTipo = 'danger'; }
        else {
            require_once(__DIR__ . '/PHPMailer/src/Exception.php');
            require_once(__DIR__ . '/PHPMailer/src/PHPMailer.php');
            require_once(__DIR__ . '/PHPMailer/src/SMTP.php');
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $log = '';
            try {
                mailer_configurar($mail);
                $mail->SMTPDebug = 2; $mail->Timeout = 15;
                $mail->Debugoutput = function($str) use (&$log) { $log .= $str; };
                $mail->addAddress($dest);
                $mail->isHTML(true);
                $mail->Subject = '=?UTF-8?B?' . base64_encode(($en ? 'Test email - ' : 'Correo de prueba - ') . mail_brand()) . '?=';
                $mail->Body    = '<p>' . h($en ? 'This is a test email from the appointment system.' : 'Este es un correo de prueba del sistema de citas.') . '</p><p><b>' . h(mail_brand()) . '</b></p>';
                $mail->AltBody = ($en ? 'This is a test email from the appointment system.' : 'Este es un correo de prueba del sistema de citas.') . "\n" . mail_brand();
                $mail->send();
                auditar($conexion, 'Configuración', 'editar', 'CONFIG', null, 'Envió un correo de prueba a '.$dest);
                $msg = ($en ? 'Test email sent to ' : 'Correo de prueba enviado a ') . $dest; $msgTipo = 'success';
            } catch (\Exception $e) {
                $msg = ($en ? 'Could not send: ' : 'No se pudo enviar: ') . $e->getMessage(); $msgTipo = 'danger';
            }
            // Oculta credenciales (líneas base64 de AUTH) del registro técnico
            $debug = preg_replace('/^(CLIENT -> SERVER: )[A-Za-z0-9+\/=]{8,}\s*$/m', '$1[oculto]', $log);
        }
    }
}

$c = mail_cfg(true);
$tienePass = ($c['pass'] !== '');
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Email settings':'Configuración de correo'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="./main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
</head>
<body>
<div class="app-container app-theme-white body-tabs-shadow fixed-sidebar fixed-header">
    <div class="app-header header-shadow">
        <div class="app-header__logo"><div class="logo-src"></div>
            <div class="header__pane ml-auto">
                <button type="button" class="hamburger close-sidebar-btn hamburger--elastic" data-class="closed-sidebar">
                    <span class="hamburger-box"><span class="hamburger-inner"></span></span>
                </button>
            </div>
        </div>
        <div class="app-header__mobile-menu">
            <button type="button" class="hamburger hamburger--elastic mobile-toggle-nav">
                <span class="hamburger-box"><span class="hamburger-inner"></span></span>
            </button>
        </div>
        <div class="app-header__content"><div class="app-header-left"></div>
            <div class="app-header-right"><div class="header-btn-lg pr-0"><div class="widget-content p-0"><div class="widget-content-wrapper">
                <div class="widget-content-left ml-3 header-user-info">
                    <div class="widget-heading"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                    <div class="widget-subheading"><?php echo htmlspecialchars($_SESSION['rol'] ?? ''); ?></div>
                </div>
                <div class="widget-content-left ms-3"><a href="salir.php" class="btn btn-sm btn-outline-secondary"><?php te('common.close'); ?></a></div>
            </div></div></div></div>
        </div>
    </div>
    <div class="app-main">
        <div class="app-sidebar sidebar-shadow"><?php include("./menu/menu_adm.php"); ?></div>
        <div class="app-main__outer"><div class="app-main__inner">

            <div class="app-page-title mb-3"><div class="page-title-wrapper"><div class="page-title-heading">
                <div class="page-title-icon"><i class="pe-7s-mail icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Email & WhatsApp':'Correo y WhatsApp'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Mail account used to email patients and new users, and the clinic WhatsApp.':'Cuenta de correo con la que se escribe a pacientes y usuarios nuevos, y WhatsApp de la clínica.'; ?></div>
                </div>
            </div></div></div>

            <?php if ($msg !== ''): ?><div class="alert alert-<?php echo h($msgTipo); ?>"><?php echo h($msg); ?></div><?php endif; ?>
            <?php if (!mail_configurado()): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?php echo $en?'Email is not configured: no email is sent until you fill in the server and sender.':'El correo no está configurado: no se enviará ningún correo hasta que completes el servidor y el remitente.'; ?></div><?php endif; ?>

            <div class="row"><div class="col-lg-8">
            <form method="POST" class="card shadow-sm mb-3"><div class="card-body">
                <input type="hidden" name="accion" value="guardar">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="enabled" <?php echo $c['enabled']==='1'?'checked':''; ?>>
                    <label class="form-check-label" for="enabled"><?php echo $en?'Send emails':'Enviar correos'; ?></label>
                </div>
                <div class="mb-3"><label class="form-label fw-semibold"><?php echo $en?'Clinic name (emails, login, documents)':'Nombre de la clínica (correos, login y documentos)'; ?></label>
                    <input name="brand" class="form-control" maxlength="80" value="<?php echo h($c['brand']); ?>" placeholder="L'Chaim">
                    <div class="form-text"><?php echo $en?'Appears in emails (header, subject, footer), the login page, page titles and the signing page.':'Aparece en los correos (encabezado, asunto y pie), el login, los títulos de página y la página de firma.'; ?></div></div>
                <h6 class="mt-4"><?php echo $en?'Outgoing server (SMTP)':'Servidor de salida (SMTP)'; ?></h6>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label"><?php echo $en?'Server':'Servidor'; ?></label><input name="host" class="form-control" value="<?php echo h($c['host']); ?>" placeholder="mail.midominio.com"></div>
                    <div class="col-md-3"><label class="form-label"><?php echo $en?'Port':'Puerto'; ?></label><input name="port" class="form-control" value="<?php echo h($c['port']); ?>" placeholder="465"></div>
                    <div class="col-md-3"><label class="form-label"><?php echo $en?'Security':'Seguridad'; ?></label>
                        <select name="secure" class="form-select">
                            <option value="ssl"  <?php echo $c['secure']==='ssl'?'selected':''; ?>>SSL/TLS (465)</option>
                            <option value="tls"  <?php echo $c['secure']==='tls'?'selected':''; ?>>STARTTLS (587)</option>
                            <option value="none" <?php echo $c['secure']==='none'?'selected':''; ?>><?php echo $en?'None (25)':'Ninguna (25)'; ?></option>
                        </select></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label"><?php echo $en?'Username':'Usuario'; ?></label><input name="user" class="form-control" autocomplete="off" value="<?php echo h($c['user']); ?>" placeholder="citas@midominio.com"></div>
                    <div class="col-md-6"><label class="form-label"><?php echo $en?'Password':'Contraseña'; ?></label>
                        <input type="password" name="pass" class="form-control" autocomplete="new-password" placeholder="<?php echo $tienePass ? ($en?'Saved — leave blank to keep':'Guardada — deja vacío para conservarla') : ''; ?>"></div>
                </div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="verify_ssl" id="verify_ssl" <?php echo $c['verify_ssl']==='1'?'checked':''; ?>>
                    <label class="form-check-label" for="verify_ssl"><?php echo $en?'Validate the server SSL certificate':'Validar el certificado SSL del servidor'; ?></label>
                    <div class="form-text"><?php echo $en?'Leave off on shared hosting with a self-signed certificate.':'Déjalo apagado en hosting compartido con certificado propio.'; ?></div></div>
                <h6 class="mt-4"><?php echo $en?'Sender':'Remitente'; ?></h6>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label"><?php echo $en?'Sender email':'Correo remitente'; ?></label><input name="from_email" class="form-control" value="<?php echo h($c['from_email']); ?>" placeholder="citas@midominio.com"></div>
                    <div class="col-md-6"><label class="form-label"><?php echo $en?'Sender name':'Nombre del remitente'; ?></label><input name="from_name" class="form-control" maxlength="80" value="<?php echo h($c['from_name']); ?>" placeholder="<?php echo $en?'(clinic name)':'(nombre de la clínica)'; ?>"></div>
                    <div class="col-md-6"><label class="form-label"><?php echo $en?'Reply-to email':'Responder a (correo)'; ?></label><input name="reply_email" class="form-control" value="<?php echo h($c['reply_email']); ?>" placeholder="<?php echo $en?'(same as sender)':'(igual al remitente)'; ?>"></div>
                    <div class="col-md-6"><label class="form-label"><?php echo $en?'Reply-to name':'Responder a (nombre)'; ?></label><input name="reply_name" class="form-control" maxlength="80" value="<?php echo h($c['reply_name']); ?>"></div>
                </div>
                <h6 class="mt-4"><?php echo $en?'Footer (optional)':'Pie del correo (opcional)'; ?></h6>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Español</label><textarea name="footer_es" rows="3" class="form-control" placeholder="<?php echo $en?'Empty = default text':'Vacío = texto por defecto'; ?>"><?php echo h($c['footer_es']); ?></textarea></div>
                    <div class="col-md-6"><label class="form-label">English</label><textarea name="footer_en" rows="3" class="form-control"><?php echo h($c['footer_en']); ?></textarea></div>
                </div>
                <div class="mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> <?php echo $en?'Save':'Guardar'; ?></button></div>
            </div></form>

            <form method="POST" class="card shadow-sm mb-3"><div class="card-body">
                <input type="hidden" name="accion" value="whatsapp">
                <h6><i class="bi bi-whatsapp text-success"></i> WhatsApp</h6>
                <div class="row g-2">
                    <div class="col-md-3"><label class="form-label"><?php echo $en?'Default country code':'Código de país por defecto'; ?></label>
                        <div class="input-group"><span class="input-group-text">+</span><input name="wa_country" class="form-control" maxlength="4" value="<?php echo h(wa_pais()); ?>" placeholder="593"></div></div>
                    <div class="col-md-9"><label class="form-label"><?php echo $en?"Clinic WhatsApp number":"Número de WhatsApp de la clínica"; ?></label>
                        <input name="wa_number" class="form-control" value="<?php echo wa_numero() !== '' ? '+'.h(wa_numero()) : ''; ?>" placeholder="+593 99 123 4567"></div>
                </div>
                <div class="form-text mb-2"><?php echo $en
                    ? 'The country code completes patient phones typed without it (e.g. 0991234567 → +593991234567). The clinic number is shown as a contact in appointment emails and in the WhatsApp confirmation message.'
                    : 'El código de país completa los teléfonos de pacientes escritos sin él (ej. 0991234567 → +593991234567). El número de la clínica se muestra como contacto en los correos de cita y en el mensaje de confirmación por WhatsApp.'; ?></div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> <?php echo $en?'Save WhatsApp':'Guardar WhatsApp'; ?></button>
            </div></form>

            <form method="POST" class="card shadow-sm"><div class="card-body">
                <input type="hidden" name="accion" value="probar">
                <h6><?php echo $en?'Send a test email':'Enviar correo de prueba'; ?></h6>
                <div class="row g-2 align-items-end">
                    <div class="col-md-8"><input type="email" name="destino" class="form-control" placeholder="tucorreo@ejemplo.com" required></div>
                    <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-send"></i> <?php echo $en?'Send test':'Enviar prueba'; ?></button></div>
                </div>
                <div class="form-text"><?php echo $en?'Uses the saved settings (save first).':'Usa la configuración guardada (guarda antes de probar).'; ?></div>
                <?php if ($debug !== ''): ?><details class="mt-2"><summary class="small"><?php echo $en?'Technical log':'Registro técnico'; ?></summary><pre class="small bg-light p-2 mt-1" style="max-height:240px;overflow:auto;"><?php echo h($debug); ?></pre></details><?php endif; ?>
            </div></form>
            </div>
            <div class="col-lg-4"><div class="alert alert-info small mb-0"><i class="bi bi-info-circle"></i>
                <?php echo $en
                    ? 'Affects appointment emails (scheduled, changed, rescheduled, cancelled), documents to sign and new-user welcome emails. The password is stored encrypted and never shown again. If email is not configured, nothing is sent and the action (e.g. creating the appointment) still completes.'
                    : 'Afecta los correos de citas (programada, modificada, reagendada, cancelada), documentos para firmar y bienvenida de usuarios nuevos. La contraseña se guarda cifrada y no se vuelve a mostrar. Si el correo no está configurado no se envía nada y la acción (por ejemplo crear la cita) se completa igual.'; ?>
            </div></div></div>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
