<?php
/**
 * class/whatsapp.php — Configuración de WhatsApp por instalación (app_config: wa.*).
 *   wa.country : código de país por defecto (solo dígitos, ej. 593) para normalizar teléfonos
 *   wa.number  : número de WhatsApp de la clínica (dígitos con código de país)
 * El botón del calendario abre wa.me desde el WhatsApp de quien lo pulsa; este número
 * se muestra como contacto de la clínica en correos y en el mensaje de confirmación.
 */
require_once(__DIR__ . '/conexionBD.php');

function wa_cfg($recargar = false) {
    static $cache = null;
    if ($cache !== null && !$recargar) return $cache;
    $cfg = ['country' => '593', 'number' => ''];
    $c = function_exists('_permisos_conn') ? _permisos_conn() : conectarse();
    if ($c) {
        $rs = @$c->query("SELECT clave, valor FROM app_config WHERE clave IN ('wa.country','wa.number')");
        if ($rs) while ($r = $rs->fetch_assoc()) {
            $k = substr($r['clave'], 3);
            $v = preg_replace('/\D/', '', (string)$r['valor']);
            if ($k === 'country' && $v !== '') $cfg['country'] = $v;
            if ($k === 'number') $cfg['number'] = $v;
        }
    }
    return $cache = $cfg;
}
function wa_pais()   { return wa_cfg()['country']; }
function wa_numero() { return wa_cfg()['number']; }

/** Normaliza un teléfono a formato internacional (solo dígitos). Misma regla que el calendario (JS). */
function wa_normalizar($raw, $pais = null) {
    $pais = $pais ?? wa_pais();
    $t = preg_replace('/\D/', '', (string)$raw);
    if ($t === '') return '';
    if (strpos($t, '00') === 0)      $t = substr($t, 2);
    elseif ($t[0] === '0')           $t = $pais . substr($t, 1);
    elseif (strlen($t) <= 10 && strpos($t, $pais) !== 0) $t = $pais . $t;
    return $t;
}

/** Guarda país y número (el número se normaliza). Devuelve [ok, error|null]. */
function wa_cfg_guardar($conexion, $pais, $numero) {
    $pais = preg_replace('/\D/', '', (string)$pais);
    if ($pais === '' || strlen($pais) > 4) return [false, 'COUNTRY'];
    $num = $numero === '' ? '' : wa_normalizar($numero, $pais);
    if ($num !== '' && (strlen($num) < 8 || strlen($num) > 15)) return [false, 'NUMBER'];
    @$conexion->query("CREATE TABLE IF NOT EXISTS app_config (
        clave VARCHAR(60) NOT NULL PRIMARY KEY, valor VARCHAR(190) NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $conexion->prepare("INSERT INTO app_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), updated_at=NOW()");
    if (!$st) return [false, 'DB'];
    $ok = true;
    foreach (['wa.country' => $pais, 'wa.number' => $num] as $k => $v) { $st->bind_param('ss', $k, $v); $ok = $st->execute() && $ok; }
    $st->close();
    wa_cfg(true);
    return [$ok, $ok ? null : 'DB'];
}
