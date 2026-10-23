<?php
/**
 * class/mailer.php — Configuración de correo saliente por instalación.
 *
 * Los valores viven en app_config (claves 'mail.*'); se editan en mail_config.php
 * (solo SISTEMA). La contraseña SMTP se guarda cifrada (AES-256-CBC). La clave de
 * cifrado sale de 'app_key' en config.local.php (recomendado) o, si no existe, de
 * un valor derivado de la base de datos.
 *
 * Uso en un punto de envío (dentro de try, tras `new PHPMailer(true)`):
 *   mailer_configurar($mail);          // SMTP + remitente + responder-a; lanza si no hay config
 *   $mail->addAddress(...);
 *
 * Si el correo no está configurado o está desactivado, mailer_configurar() lanza una
 * excepción de PHPMailer: los llamadores ya la capturan y solo avisan que el correo
 * no salió (la cita / el usuario ya quedó guardado). Nunca se usan credenciales por defecto.
 */
require_once(__DIR__ . '/conexionBD.php');

function mail_claves() {
    return ['enabled','brand','host','port','secure','user','pass','from_email','from_name',
            'reply_email','reply_name','verify_ssl','footer_es','footer_en'];
}

function mail_cfg_defecto() {
    return ['enabled'=>'1','brand'=>'','host'=>'','port'=>'465','secure'=>'ssl','user'=>'','pass'=>'',
            'from_email'=>'','from_name'=>'','reply_email'=>'','reply_name'=>'','verify_ssl'=>'0',
            'footer_es'=>'','footer_en'=>''];
}

/** Clave de cifrado de la contraseña SMTP. */
function mail_clave_cifrado() {
    $f = __DIR__ . '/../config.local.php';
    $cfg = is_file($f) ? @include $f : [];
    if (is_array($cfg) && !empty($cfg['app_key'])) return hash('sha256', (string)$cfg['app_key'], true);
    $base = (is_array($cfg) ? (($cfg['db_name'] ?? '') . '|' . ($cfg['db_user'] ?? '')) : '') . '|mail-lchain-v1';
    return hash('sha256', $base, true);
}

function mail_cifrar($txt) {
    if ($txt === '') return '';
    $iv = random_bytes(16);
    $ct = openssl_encrypt($txt, 'AES-256-CBC', mail_clave_cifrado(), OPENSSL_RAW_DATA, $iv);
    return $ct === false ? '' : 'enc1:' . base64_encode($iv . $ct);
}

function mail_descifrar($val) {
    if ($val === '' || strpos($val, 'enc1:') !== 0) return '';
    $raw = base64_decode(substr($val, 5), true);
    if ($raw === false || strlen($raw) < 17) return '';
    $pt = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', mail_clave_cifrado(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $pt === false ? '' : $pt;
}

/** Configuración vigente (con la contraseña descifrada). Cacheada por request. */
function mail_cfg($recargar = false) {
    static $cache = null;
    if ($cache !== null && !$recargar) return $cache;
    $cfg = mail_cfg_defecto();
    $c = function_exists('_permisos_conn') ? _permisos_conn() : conectarse();
    if ($c) {
        $rs = @$c->query("SELECT clave, valor FROM app_config WHERE clave LIKE 'mail.%'");
        if ($rs) while ($r = $rs->fetch_assoc()) {
            $k = substr($r['clave'], 5);
            if (array_key_exists($k, $cfg)) $cfg[$k] = (string)$r['valor'];
        }
    }
    $cfg['pass'] = mail_descifrar($cfg['pass']);
    return $cache = $cfg;
}

/** ¿Hay lo mínimo para enviar? (servidor + remitente) */
function mail_configurado() {
    $c = mail_cfg();
    return $c['host'] !== '' && filter_var($c['from_email'], FILTER_VALIDATE_EMAIL);
}

function mail_brand() {
    $b = trim(mail_cfg()['brand']);
    return $b !== '' ? $b : 'Clínica';
}
function mail_from_name()  { $n = trim(mail_cfg()['from_name']);  return $n !== '' ? $n : mail_brand(); }
function mail_reply_name() { $n = trim(mail_cfg()['reply_name']); return $n !== '' ? $n : mail_brand(); }

/** Pie personalizado para el idioma ('es'|'en'), o null para usar el de la plantilla. */
function mail_footer($lang) {
    $t = trim(mail_cfg()['footer_' . ($lang === 'en' ? 'en' : 'es')]);
    return $t !== '' ? nl2br(htmlspecialchars($t, ENT_QUOTES, 'UTF-8')) : null;
}

/** Aplica SMTP + remitente + responder-a a un PHPMailer. Lanza si no se puede enviar. */
function mailer_configurar($mail) {
    $c = mail_cfg();
    if ($c['enabled'] !== '1') throw new \PHPMailer\PHPMailer\Exception('Correo desactivado en esta instalación.');
    if (!mail_configurado())   throw new \PHPMailer\PHPMailer\Exception('Correo no configurado (Panel de Control → Correo).');

    $mail->isSMTP();
    $mail->Host     = $c['host'];
    $mail->Port     = (int)$c['port'] ?: 465;
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'base64';
    if ($c['secure'] === 'ssl')      { $mail->SMTPSecure = 'ssl'; }
    elseif ($c['secure'] === 'tls')  { $mail->SMTPSecure = 'tls'; }
    else                             { $mail->SMTPSecure = ''; $mail->SMTPAutoTLS = false; }
    if ($c['user'] !== '') { $mail->SMTPAuth = true; $mail->Username = $c['user']; $mail->Password = $c['pass']; }
    else                   { $mail->SMTPAuth = false; }
    if ($c['verify_ssl'] !== '1') {
        $mail->SMTPOptions = ['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
    }
    $mail->setFrom($c['from_email'], mail_from_name());
    $mail->addReplyTo(filter_var($c['reply_email'], FILTER_VALIDATE_EMAIL) ? $c['reply_email'] : $c['from_email'], mail_reply_name());
}

/**
 * Guarda valores. $vals: clave => valor (sin prefijo). 'pass' vacío = conservar la actual.
 * Devuelve true/false.
 */
function mail_cfg_guardar($conexion, $vals) {
    @$conexion->query("CREATE TABLE IF NOT EXISTS app_config (
        clave VARCHAR(60) NOT NULL PRIMARY KEY, valor VARCHAR(190) NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // valor debe admitir textos largos (pie de correo)
    $r = @$conexion->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_config' AND COLUMN_NAME='valor'");
    if ($r && ($x = $r->fetch_assoc()) && strtolower($x['DATA_TYPE']) === 'varchar') {
        @$conexion->query("ALTER TABLE app_config MODIFY valor TEXT NULL");
    }
    $st = $conexion->prepare("INSERT INTO app_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), updated_at=NOW()");
    if (!$st) return false;
    $ok = true;
    foreach (mail_claves() as $k) {
        if (!array_key_exists($k, $vals)) continue;
        $v = (string)$vals[$k];
        if ($k === 'pass') { if ($v === '') continue; $v = mail_cifrar($v); }
        $clave = 'mail.' . $k;
        $st->bind_param('ss', $clave, $v);
        $ok = $st->execute() && $ok;
    }
    $st->close();
    mail_cfg(true);
    return $ok;
}
