<?php
/**
 * registro_paciente.php — Hoja imprimible del paciente con el formato de la ficha física:
 *   REGISTRO DEL PACIENTE (datos, signos vitales, bloques de atención) y HOJA DE SIGNOS VITALES.
 * Uso: registro_paciente.php?idPaciente=ID  (Imprimir → guardar como PDF).
 * Requiere el módulo 'registro_atencion' y el permiso atencion.ver_registro. Registra el acceso en auditoría.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/registro_atencion.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION['rol'])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('atencion.ver_registro');
registro_asegurar_tablas($conexion);

$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rp_num($v){ if ($v === null || $v === '') return ''; $v = (string)$v; return strpos($v, '.') !== false ? rtrim(rtrim($v, '0'), '.') : $v; }
function rp_fecha($d){ return $d ? date('m/d/Y', strtotime($d)) : ''; }

$idPac = (int)($_GET['idPaciente'] ?? 0);
$st = $conexion->prepare("SELECT NOMBRES, APELLIDOS, CEDULA, TELEFONO, EMAIL, ADDRESS, FECHANACIMIENTO FROM AG_PACIENTE WHERE IDPACIENTE = ? LIMIT 1");
$st->bind_param('i', $idPac); $st->execute();
$p = $st->get_result()->fetch_assoc(); $st->close();
if (!$p) { http_response_code(404); exit($en ? 'Patient not found.' : 'Paciente no encontrado.'); }
$edad = '';
if (!empty($p['FECHANACIMIENTO']) && $p['FECHANACIMIENTO'] !== '0000-00-00') { try { $edad = (string)date_diff(new DateTime($p['FECHANACIMIENTO']), new DateTime('today'))->y; } catch (Exception $e) {} }

// Signos vitales (triaje) de todas las citas del paciente, del más antiguo al más reciente
$vitales = [];
$q = $conexion->query("SELECT T.PESO_KG, T.TALLA_CM, T.PRESION, T.FC, T.SAT_O2, T.TEMPERATURA, T.GLUCOSA, A.FECHA_CITA, TC.NOMBRES AS SERVICIO
                         FROM triaje T INNER JOIN AG_CITA A ON A.IDCITA = T.IDCITA
                         LEFT JOIN AG_TIPOCONSULTA TC ON TC.IDTIPOCONSULTA = A.IDTIPOCONSULTA
                        WHERE T.IDPACIENTE = $idPac ORDER BY A.FECHA_CITA, T.IDTRIAJE");
// Un triaje copiado a la cita de un profesional derivado repite la misma medición del mismo día: se muestra una sola vez.
$vistos = [];
if ($q) while ($r = $q->fetch_assoc()) {
    $k = implode('|', [$r['FECHA_CITA'], $r['PESO_KG'], $r['TALLA_CM'], $r['PRESION'], $r['FC'], $r['SAT_O2'], $r['TEMPERATURA'], $r['GLUCOSA']]);
    if (isset($vistos[$k])) continue;
    $vistos[$k] = true; $vitales[] = $r;
}

// Registros de atención (bloques Servicio/Fecha/Tratante + Indicaciones/Cantidad/Recomendaciones)
$bloques = [];
$q = $conexion->query("SELECT IDREGISTRO, SERVICIO, FECHA, TRATANTE FROM atencion_registro WHERE IDPACIENTE = $idPac ORDER BY FECHA, IDREGISTRO");
if ($q) while ($r = $q->fetch_assoc()) {
    $r['items'] = [];
    $qi = $conexion->query("SELECT INDICACION, CANTIDAD, RECOMENDACION FROM atencion_registro_item WHERE IDREGISTRO = ".(int)$r['IDREGISTRO']." ORDER BY ORDEN, IDITEM");
    if ($qi) while ($i = $qi->fetch_assoc()) $r['items'][] = $i;
    $bloques[] = $r;
}
auditar($conexion, 'Atención', 'ver', 'AG_PACIENTE', $idPac, 'Abrió la hoja del paciente (registro imprimible)');

require_once(__DIR__ . "/class/mailer.php");
$brand = mail_brand();
$logo  = is_file(__DIR__ . '/images/logo_clinica.png') ? 'images/logo_clinica.png' : '';
$ult4  = array_slice($vitales, -4);
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $en?'Patient record':'Registro del paciente'; ?> — <?php echo h($p['NOMBRES'].' '.$p['APELLIDOS']); ?></title>
<style>
  *{box-sizing:border-box} body{font-family:Arial,Helvetica,sans-serif;color:#000;margin:0;background:#eee}
  .barra{background:#23324d;color:#fff;padding:8px 16px;display:flex;gap:10px;align-items:center}
  .barra button,.barra a{background:#fff;color:#23324d;border:0;border-radius:4px;padding:6px 12px;font-size:14px;cursor:pointer;text-decoration:none}
  .hoja{width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:12mm 12mm;border:1px solid #000;outline:1px solid #000;outline-offset:3px}
  .enc{display:flex;align-items:center;gap:18px;margin-bottom:10px} .enc img{height:70px}
  .enc .marca{font-size:20px;font-weight:bold;color:#2a7f86} .enc h1{flex:1;text-align:center;font-size:26px;margin:0;letter-spacing:.5px}
  table{border-collapse:collapse;width:100%} td,th{border:1px solid #000;padding:5px 7px;font-size:12px;vertical-align:top}
  th{text-align:center;background:#fff;font-weight:bold}
  .datos{border:1px solid #000;padding:8px 10px;margin-bottom:12px;font-size:13px;line-height:1.9}
  .datos b{display:inline-block} .linea{border-bottom:1px solid #000;display:inline-block;min-width:120px;padding:0 4px}
  .bloque{margin-bottom:14px;page-break-inside:avoid} .cel{height:26px} .nota{font-size:11px;color:#555}
  .corte{page-break-before:always}
  @media print{ body{background:#fff} .barra{display:none} .hoja{margin:0;border:0;outline:0;width:auto;min-height:0;padding:8mm} }
</style></head><body>
<div class="barra"><button onclick="window.print()">🖨 <?php echo $en?'Print / Save as PDF':'Imprimir / Guardar PDF'; ?></button><a href="javascript:window.close()"><?php echo $en?'Close':'Cerrar'; ?></a><span style="margin-left:auto;font-size:12px;opacity:.85"><?php echo h($p['NOMBRES'].' '.$p['APELLIDOS']); ?></span></div>

<div class="hoja">
  <div class="enc"><?php if ($logo): ?><img src="<?php echo h($logo); ?>" alt=""><?php else: ?><div class="marca"><?php echo h($brand); ?></div><?php endif; ?><h1><?php echo $en?'PATIENT RECORD':'REGISTRO DEL PACIENTE'; ?></h1></div>
  <div class="datos">
    <div><b><?php echo $en?'DATE':'FECHA'; ?>:</b> <span class="linea" style="min-width:400px"><?php echo rp_fecha(date('Y-m-d')); ?></span></div>
    <div><b><?php echo $en?'FULL NAME':'NOMBRE COMPLETO'; ?>:</b> <span class="linea" style="min-width:330px"><?php echo h($p['NOMBRES'].' '.$p['APELLIDOS']); ?></span> <b><?php echo $en?'AGE':'EDAD'; ?>:</b> <span class="linea" style="min-width:50px"><?php echo h($edad); ?></span></div>
    <div><b><?php echo $en?'ID':'CÉDULA'; ?>:</b> <span class="linea" style="min-width:230px"><?php echo h($p['CEDULA']); ?></span> <b><?php echo $en?'PHONE':'TELÉFONO'; ?>:</b> <span class="linea" style="min-width:160px"><?php echo h($p['TELEFONO']); ?></span></div>
    <div><b><?php echo $en?'ADDRESS':'DIRECCIÓN'; ?>:</b> <span class="linea" style="min-width:470px"><?php echo h($p['ADDRESS']); ?></span></div>
    <div><b><?php echo $en?'EMAIL':'CORREO ELECTRÓNICO'; ?>:</b> <span class="linea" style="min-width:380px"><?php echo h($p['EMAIL']); ?></span></div>
  </div>

  <table style="margin-bottom:14px"><thead><tr>
    <th><?php echo $en?'HEIGHT':'TALLA'; ?></th><th><?php echo $en?'WEIGHT':'PESO'; ?></th><th><?php echo $en?'BLOOD PRESSURE':'PRESIÓN ART.'; ?></th><th>FC / SAT O₂</th><th><?php echo $en?'TEMPERATURE':'TEMPERATURA'; ?></th><th><?php echo $en?'GLUCOSE':'GLUCOSA'; ?></th></tr></thead><tbody>
    <?php for ($i = 0; $i < 4; $i++): $v = $ult4[$i] ?? null; ?><tr class="cel">
      <td><?php echo $v ? h(rp_num($v['TALLA_CM'])).($v['TALLA_CM']!==null?' cm':'') : '&nbsp;'; ?></td>
      <td><?php echo $v ? h(rp_num($v['PESO_KG'])).($v['PESO_KG']!==null?' kg':'') : ''; ?></td>
      <td><?php echo $v ? h($v['PRESION']) : ''; ?></td>
      <td><?php echo $v ? h(trim(rp_num($v['FC']).($v['SAT_O2']!==null?' / '.rp_num($v['SAT_O2']).'%':''))) : ''; ?></td>
      <td><?php echo $v ? h(rp_num($v['TEMPERATURA'])).($v['TEMPERATURA']!==null?' °C':'') : ''; ?></td>
      <td><?php echo $v ? h(rp_num($v['GLUCOSA'])) : ''; ?></td></tr><?php endfor; ?></tbody></table>

  <?php foreach ($bloques as $b): ?>
  <div class="bloque"><table>
    <tr><th style="width:56%"><?php echo $en?'SERVICE':'SERVICIO'; ?></th><th style="width:17%"><?php echo $en?'DATE':'FECHA'; ?></th><th><?php echo $en?'PROVIDER':'TRATANTE'; ?></th></tr>
    <tr class="cel"><td><?php echo h($b['SERVICIO']); ?></td><td><?php echo rp_fecha($b['FECHA']); ?></td><td><?php echo h($b['TRATANTE']); ?></td></tr>
    <tr><th><?php echo $en?'INSTRUCTIONS':'INDICACIONES'; ?></th><th><?php echo $en?'QUANTITY':'CANTIDAD'; ?></th><th><?php echo $en?'RECOMMENDATIONS':'RECOMENDACIONES'; ?></th></tr>
    <?php foreach ($b['items'] as $it): ?><tr><td><?php echo nl2br(h($it['INDICACION'])); ?></td><td><?php echo h($it['CANTIDAD']); ?></td><td><?php echo nl2br(h($it['RECOMENDACION'])); ?></td></tr><?php endforeach; ?>
    <?php if (!$b['items']): ?><tr class="cel"><td>&nbsp;</td><td></td><td></td></tr><?php endif; ?>
  </table></div>
  <?php endforeach; ?>
  <?php if (!$bloques): ?><p class="nota"><?php echo $en?'No visit records yet.':'Aún no hay registros de atención.'; ?></p><?php endif; ?>
</div>

<div class="hoja corte">
  <div class="enc"><?php if ($logo): ?><img src="<?php echo h($logo); ?>" alt=""><?php else: ?><div class="marca"><?php echo h($brand); ?></div><?php endif; ?><h1><?php echo $en?'VITAL SIGNS SHEET':'HOJA DE SIGNOS VITALES'; ?></h1></div>
  <table><thead><tr>
    <th><?php echo $en?'SERVICE':'SERVICIO'; ?></th><th><?php echo $en?'DATE':'FECHA'; ?></th><th><?php echo $en?'HEIGHT':'TALLA'; ?></th><th><?php echo $en?'WEIGHT':'PESO'; ?></th>
    <th><?php echo $en?'BLOOD PRESSURE':'PRESIÓN ART.'; ?></th><th>FC / SAT O₂</th><th>TEMP°</th><th><?php echo $en?'GLUCOSE':'GLUCOSA'; ?></th></tr></thead><tbody>
    <?php foreach ($vitales as $v): ?><tr>
      <td><?php echo h($v['SERVICIO']); ?></td><td><?php echo rp_fecha($v['FECHA_CITA']); ?></td>
      <td><?php echo h(rp_num($v['TALLA_CM'])); ?></td><td><?php echo h(rp_num($v['PESO_KG'])); ?></td><td><?php echo h($v['PRESION']); ?></td>
      <td><?php echo h(trim(rp_num($v['FC']).($v['SAT_O2']!==null?' / '.rp_num($v['SAT_O2']).'%':''))); ?></td><td><?php echo h(rp_num($v['TEMPERATURA'])); ?></td><td><?php echo h(rp_num($v['GLUCOSA'])); ?></td></tr><?php endforeach; ?>
    <?php for ($i = count($vitales); $i < 30; $i++): ?><tr class="cel"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
  </tbody></table>
</div>
</body></html>
