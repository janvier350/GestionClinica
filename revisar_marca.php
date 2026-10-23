<?php
/**
 * revisar_marca.php — Busca textos de la clínica anterior ("SRoss", "SROSS", "srossnutritions") en los
 * catálogos de la base de datos (documentos para firmar, plantillas, servicios, localidades, bill items, seguros…)
 * y muestra dónde están, con enlace a la pantalla donde se corrigen. SISTEMA-only.
 * Opcional: desactivar (no borrar) los documentos que mencionan SRoss para que no se puedan enviar a pacientes.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/auditoria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') { http_response_code(403); exit('Acceso restringido: sólo SISTEMA.'); }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$TERMINO = 'sross';
$tablas = [   // tabla => [enlace, etiqueta]
    'documentos'               => ['gestionar_documentos.php',   'Documentos para firmar'],
    'cat_plantillas_nutricion' => ['plantillas_admin.php',       'Plantillas de informe'],
    'AG_TIPOCONSULTA'          => ['gestionar_tipos_consulta.php','Tipos de consulta'],
    'ADM_AGENCIA'              => ['gestionar_agencias.php',     'Localidades'],
    'bill_items'               => ['bill_items_admin.php',       'Bill items'],
    'seguros'                  => ['gestionar_seguros.php',      'Aseguradoras'],
    'tipo_seguro'              => ['gestionar_tipos_seguro.php', 'Tipos de seguro'],
    'ncp_diagnosticos'         => ['ncp_diagnosticos_admin.php', 'Diagnósticos NCP/PES'],
    'ENFERMEDADES_DIAGNOSTICO' => ['PNC_CIE-10Crear.php',        'Categorías ICD-10'],
    'app_config'               => ['mail_config.php',            'Configuración (correo, zona horaria…)'],
];
// Columna que identifica cada registro para mostrarlo con nombre (no solo con número).
$etiquetaCol = ['documentos' => 'titulo', 'cat_plantillas_nutricion' => 'nombre_plantilla', 'AG_TIPOCONSULTA' => 'NOMBRES', 'ADM_AGENCIA' => 'DESCRIPCION'];
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'desactivar_docs') {
    $conexion->query("UPDATE documentos SET estado = 0 WHERE estado = 1 AND (titulo LIKE '%$TERMINO%' OR contenido LIKE '%$TERMINO%')");
    $n = (int)$conexion->affected_rows;
    auditar($conexion, 'Documentos', 'editar', 'documentos', null, "Desactivó $n documento(s) que mencionan la clínica anterior");
    $msg = "Se desactivaron $n documento(s). Siguen en la lista (inactivos) para que los corrijas y reactives.";
}

$hallazgos = []; $total = 0;
foreach ($tablas as $t => [$url, $etq]) {
    $tq = $conexion->real_escape_string($t);
    $r = $conexion->query("SELECT COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tq' AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext') ORDER BY ORDINAL_POSITION");
    if (!$r) continue;
    $cols = []; while ($c = $r->fetch_assoc()) $cols[] = $c['COLUMN_NAME'];
    if (!$cols) continue;
    $pk = $conexion->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tq' AND COLUMN_KEY='PRI' LIMIT 1");
    $pkc = ($pk && ($x = $pk->fetch_assoc())) ? $x['COLUMN_NAME'] : $cols[0];
    $items = [];
    foreach ($cols as $col) {
        $lab = isset($etiquetaCol[$t]) ? ", `{$etiquetaCol[$t]}` AS etiqueta" : ", NULL AS etiqueta";
        $q = $conexion->query("SELECT `$pkc` AS id, `$col` AS v$lab FROM `$t` WHERE `$col` LIKE '%$TERMINO%' LIMIT 12");
        if (!$q) continue;
        while ($f = $q->fetch_assoc()) {
            $v = (string)$f['v']; $pos = stripos($v, $TERMINO);
            $snip = trim(preg_replace('/\s+/', ' ', strip_tags(mb_substr($v, max(0, $pos - 40), 100))));
            $items[] = [$f['id'], $col, $snip, $f['etiqueta']];
        }
    }
    if ($items) { $hallazgos[$t] = [$url, $etq, $items]; $total += count($items); }
}
$docsActivos = 0;
$q = @$conexion->query("SELECT COUNT(*) c FROM documentos WHERE estado=1 AND (titulo LIKE '%$TERMINO%' OR contenido LIKE '%$TERMINO%')");
if ($q) $docsActivos = (int)$q->fetch_assoc()['c'];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Revisar textos de la clínica anterior</title><meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:900px;">
<h4>Revisar textos de la clínica anterior</h4>
<p class="text-muted">Busca «SRoss» / «SROSS» / «srossnutritions» en los catálogos. No modifica nada salvo el botón de desactivar documentos.</p>
<?php if ($msg): ?><div class="alert alert-success"><?php echo h($msg); ?></div><?php endif; ?>
<?php if (!$hallazgos): ?><div class="alert alert-success">No se encontraron textos de la clínica anterior en los catálogos revisados.</div><?php endif; ?>
<?php foreach ($hallazgos as $t => [$url, $etq, $items]): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><b><?php echo h($etq); ?></b> <span><code><?php echo h($t); ?></code> · <?php echo count($items); ?> coincidencia(s) · <a href="<?php echo h($url); ?>">Corregir</a></span></div>
<ul class="list-group list-group-flush small"><?php foreach ($items as [$id, $col, $snip, $etq2]): ?><li class="list-group-item py-1"><span class="badge bg-secondary me-1">#<?php echo h($id); ?></span><?php if ($etq2 !== null && $etq2 !== ''): ?><b><?php echo h(mb_strimwidth($etq2, 0, 70, '…')); ?></b> · <?php endif; ?><code><?php echo h($col); ?></code> … <?php echo h($snip); ?> …</li><?php endforeach; ?></ul></div>
<?php endforeach; ?>
<?php if ($docsActivos > 0): ?>
<form method="POST" class="card card-body border-warning" onsubmit="return confirm('¿Desactivar los documentos que mencionan a la clínica anterior? No se borran.');">
  <input type="hidden" name="accion" value="desactivar_docs">
  <div><b><?php echo $docsActivos; ?></b> documento(s) activo(s) mencionan la clínica anterior y <b>podrían enviarse a pacientes</b>.</div>
  <div><button class="btn btn-warning mt-2" type="submit">Desactivar esos documentos (no se borran)</button></div>
</form>
<?php endif; ?>
<a class="btn btn-link mt-3" href="modulos_config.php">Volver</a></div></body></html>
