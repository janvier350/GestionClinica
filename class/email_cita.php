<?php
/**
 * class/email_cita.php — Constructor bilingüe de los correos de cita.
 *
 * Arma el asunto y el cuerpo (HTML + texto) de los correos de cita en el
 * idioma del paciente. Tipos: 'programada', 'modificada', 'reagendada',
 * 'cancelada'.
 *
 *   $e = build_cita_email('reagendada', $lang, [
 *          'nombre' => ..., 'fecha' => 'Y-m-d', 'hora' => ..., 'horaFin' => ...,
 *          'tipoConsulta' => ..., 'doctorNombre' => ...,
 *          'fechaAnterior' => 'Y-m-d', 'horaAnterior' => ...,
 *        ]);
 *   $e['subject'], $e['html'], $e['text'], $e['fromName'], $e['replyName']
 */
require_once(__DIR__ . '/../lang/i18n.php');
require_once(__DIR__ . '/mailer.php');
require_once(__DIR__ . '/whatsapp.php');   // marca, remitente y pie configurables

if (!function_exists('patient_lang')) {
    // Idioma preferido del paciente ('en'|'es'); por defecto 'es'.
    function patient_lang($conexion, $idPaciente) {
        $idPaciente = (int)$idPaciente;
        $lang = 'es';
        $dbRow = $conexion->query("SELECT DATABASE() AS db");
        if ($dbRow && ($db = $dbRow->fetch_assoc()['db'] ?? '')) {
            $c = $conexion->query(
                "SELECT COUNT(*) c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA='" . $conexion->real_escape_string($db) . "'
                   AND TABLE_NAME='AG_PACIENTE' AND COLUMN_NAME='IDIOMA'"
            );
            $tiene = $c && (int)$c->fetch_assoc()['c'] > 0;
            if ($tiene && $idPaciente) {
                $st = $conexion->prepare("SELECT IDIOMA FROM AG_PACIENTE WHERE IDPACIENTE = ? LIMIT 1");
                $st->bind_param("i", $idPaciente);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                $v = strtolower(trim($row['IDIOMA'] ?? ''));
                if ($v === 'en' || $v === 'es') $lang = $v;
            }
        }
        return $lang;
    }
}

if (!function_exists('fecha_bonita_lang')) {
    // Fecha larga localizada a partir de 'Y-m-d'.
    function fecha_bonita_lang($f, $lang) {
        $o = DateTime::createFromFormat('Y-m-d', $f);
        if (!$o) return $f;
        $w = (int)$o->format('w'); $n = (int)$o->format('n');
        $j = (int)$o->format('j'); $y = $o->format('Y');
        if ($lang === 'en') {
            $days   = array('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday');
            $months = array('','January','February','March','April','May','June','July','August','September','October','November','December');
            return $days[$w] . ', ' . $months[$n] . ' ' . $j . ', ' . $y;
        }
        $dias  = array('domingo','lunes','martes','miércoles','jueves','viernes','sábado');
        $meses = array('','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre');
        return $dias[$w] . ', ' . $j . ' de ' . $meses[$n] . ' de ' . $y;
    }
}

if (!function_exists('build_cita_email')) {
    function build_cita_email($tipo, $lang, $d) {
        $lang = in_array($lang, array('en', 'es'), true) ? $lang : 'es';

        $cfg = array(
            'programada' => array('color' => '#5a2d82', 'light' => '#e0c8f8', 'new' => false, 'prev' => false, 'note' => null),
            'modificada' => array('color' => '#0d6efd', 'light' => '#cfe2ff', 'new' => true,  'prev' => true,  'note' => 'email.modificada.note'),
            'reagendada' => array('color' => '#0f766e', 'light' => '#c7efe8', 'new' => true,  'prev' => true,  'note' => 'email.reagendada.note'),
            'cancelada'  => array('color' => '#b02a37', 'light' => '#f5c2c7', 'new' => false, 'prev' => false, 'note' => 'email.cancelada.note'),
        );
        if (!isset($cfg[$tipo])) $tipo = 'programada';
        $c = $cfg[$tipo];

        $nombre       = $d['nombre']        ?? '';
        $fecha        = $d['fecha']         ?? '';
        $hora         = $d['hora']          ?? '';
        $horaFin      = $d['horaFin']       ?? '';
        $tipoConsulta = $d['tipoConsulta']  ?? '';
        $doctorNombre = $d['doctorNombre']  ?? '';
        $fechaAnt     = $d['fechaAnterior'] ?? '';
        $horaAnt      = $d['horaAnterior']  ?? '';

        $fechaBonita = fecha_bonita_lang($fecha, $lang);
        $horas = $hora . ($horaFin ? ' – ' . $horaFin : '');

        $lblDate = $c['new'] ? tl($lang, 'email.lbl.newDate') : tl($lang, 'email.lbl.date');
        $lblTime = $c['new'] ? tl($lang, 'email.lbl.newTime') : tl($lang, 'email.lbl.time');

        $esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

        // Filas de detalle
        $rows  = "<tr><td style='width:40%;color:#888;'>📅 " . $esc($lblDate) . "</td><td><strong>" . $esc($fechaBonita) . "</strong></td></tr>";
        $rows .= "<tr><td style='color:#888;'>🕐 " . $esc($lblTime) . "</td><td><strong>" . $esc($horas) . "</strong></td></tr>";
        $rows .= "<tr><td style='color:#888;'>🩺 " . $esc(tl($lang, 'email.lbl.type')) . "</td><td><strong>" . $esc($tipoConsulta) . "</strong></td></tr>";
        if ($doctorNombre) {
            $rows .= "<tr><td style='color:#888;'>👨‍⚕️ " . $esc(tl($lang, 'email.lbl.doctor')) . "</td><td><strong>" . $esc($doctorNombre) . "</strong></td></tr>";
        }

        // Fecha anterior (para modificada/reagendada)
        $prevHtml = '';
        if ($c['prev'] && $fechaAnt) {
            $prevHtml = "<tr><td style='padding:0 32px 8px;'>
                <p style='font-size:13px;color:#999;margin:0;'>" . $esc(tl($lang, 'email.prevDate')) . "
                <span style='text-decoration:line-through;'>" . $esc(fecha_bonita_lang($fechaAnt, $lang)) . " " . $esc(tl($lang, 'email.at')) . " " . $esc($horaAnt) . "</span></p></td></tr>";
        }

        // Nota
        $noteHtml = '';
        if ($c['note']) {
            $noteHtml = "<tr><td style='padding:10px 32px 24px;'>
                <p style='font-size:13px;color:#777;margin:0;'>" . $esc(tl($lang, $c['note'])) . "</p></td></tr>";
        }

        $title = tl($lang, "email.$tipo.title");
        $intro = tl($lang, "email.$tipo.intro");
        $brand = mail_brand();

        $html = "<!DOCTYPE html>
<html lang='" . $lang . "'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6f9;padding:30px 0;'>
    <tr><td align='center'>
      <table width='580' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);'>
        <tr>
          <td style='background:" . $c['color'] . ";padding:28px 32px;text-align:center;'>
            <h1 style='color:#ffffff;margin:0;font-size:22px;'>" . $esc($title) . "</h1>
            <p style='color:" . $c['light'] . ";margin:6px 0 0;font-size:13px;'>" . $esc($brand) . "</p>
          </td>
        </tr>
        <tr>
          <td style='padding:28px 32px 10px;'>
            <p style='font-size:15px;color:#333;margin:0;'>" . $esc(tl($lang, 'email.greetingHello')) . " <strong>" . $esc($nombre) . "</strong></p>
            <p style='font-size:14px;color:#555;margin:10px 0 0;'>" . $esc($intro) . "</p>
          </td>
        </tr>
        <tr>
          <td style='padding:16px 32px;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='background:" . $c['light'] . "33;border-radius:6px;border-left:4px solid " . $c['color'] . ";'>
              <tr><td style='padding:16px 20px;'>
                <table width='100%' cellpadding='6' cellspacing='0' style='font-size:14px;color:#333;'>" . $rows . "</table>
              </td></tr>
            </table>
          </td>
        </tr>
        " . $prevHtml . "
        " . $noteHtml . "
        <tr>
          <td style='background:#eef2f7;padding:16px 32px;text-align:center;'>
            " . (wa_numero() !== '' ? "<p style='margin:0 0 8px;font-size:13px;'><a href='https://wa.me/" . wa_numero() . "' style='color:#128c7e;text-decoration:none;font-weight:bold;'>💬 " . $esc(tl($lang, 'email.waContact')) . ": +" . wa_numero() . "</a></p>" : '') . "
            <p style='margin:0;font-size:12px;color:#999;'>" . (mail_footer($lang) ?? tl($lang, 'email.footer')) . "</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";

        // Texto plano
        $text  = tl($lang, 'email.text.greeting') . " $nombre,\n\n";
        $text .= tl($lang, "email.text.$tipo") . "\n";
        $text .= "$lblDate: $fechaBonita\n";
        $text .= "$lblTime: $horas\n";
        $text .= tl($lang, 'email.lbl.type') . ": $tipoConsulta\n";
        if ($doctorNombre) $text .= tl($lang, 'email.lbl.doctor') . ": $doctorNombre\n";
        if ($c['prev'] && $fechaAnt) {
            $text .= "\n(" . tl($lang, 'email.prevDate') . " " . fecha_bonita_lang($fechaAnt, $lang) . " " . tl($lang, 'email.at') . " $horaAnt)\n";
        }
        if ($c['note']) $text .= "\n" . tl($lang, $c['note']) . "\n";
        if (wa_numero() !== '') $text .= "\n" . tl($lang, 'email.waContact') . ': +' . wa_numero() . "\n";
        $text .= "\n" . mail_brand();

        return array(
            'subject'   => tl($lang, "email.$tipo.subject"),
            'html'      => $html,
            'text'      => $text,
            'fromName'  => mail_from_name(),
            'replyName' => mail_reply_name(),
        );
    }
}
