<?php
/**
 * migrar_documentos_lchaim.php
 * Crea los documentos para firmar propios de L'Chaim (en español), SIN tocar los demás:
 *   - Consentimiento informado de atención
 *   - Autorización para el tratamiento de datos personales y de salud
 *   - Consentimiento informado para sueroterapia
 * Idempotente por título. SISTEMA-only, POST-driven.
 * IMPORTANTE: son textos base; la clínica (y su asesor legal) debe revisarlos antes de usarlos con pacientes.
 * Variables: {{paciente}}, {{cedula}}, {{fecha_nacimiento}}, {{fecha}}. Se editan luego en Pacientes → Documentos.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/auditoria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') { http_response_code(403); exit('Acceso restringido: sólo SISTEMA.'); }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$documentos = [
    ['Consentimiento informado de atención — L\'Chaim', <<<'HTML'
<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2b2b2b;line-height:1.5;">
<h4 style="color:#2a7f86;margin:0 0 10px 0;">Consentimiento informado de atención — L'Chaim, salud &amp; vida</h4>
<p><b>Paciente:</b> {{paciente}} &nbsp; <b>Cédula:</b> {{cedula}} &nbsp; <b>Fecha de nacimiento:</b> {{fecha_nacimiento}}</p>
<p>Yo, <b>{{paciente}}</b>, declaro que he sido informado/a por el profesional de la salud de L'Chaim, de forma clara y en un lenguaje que comprendo, sobre:</p>
<ul>
<li>el motivo y la finalidad de la atención, evaluación o procedimiento que se me propone;</li>
<li>los beneficios esperados, los riesgos y las alternativas razonables disponibles;</li>
<li>que puedo hacer preguntas y que mis dudas han sido resueltas.</li>
</ul>
<p>Entiendo que la medicina no es una ciencia exacta, que no se me han garantizado resultados específicos y que debo seguir las indicaciones y recomendaciones que se me entreguen. Me comprometo a informar con veracidad mis antecedentes, alergias, medicamentos y cualquier cambio en mi estado de salud.</p>
<p>Autorizo al equipo de L'Chaim a realizar la atención indicada. Sé que puedo retirar este consentimiento en cualquier momento, sin que ello afecte la calidad de mi atención futura.</p>
<p><b>Fecha:</b> {{fecha}}</p>
<p style="margin-top:28px;">Firma del paciente o representante: ______________________________</p>
</div>
HTML
    ],
    ['Autorización para el tratamiento de datos personales y de salud — L\'Chaim', <<<'HTML'
<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2b2b2b;line-height:1.5;">
<h4 style="color:#2a7f86;margin:0 0 10px 0;">Autorización para el tratamiento de datos personales y de salud — L'Chaim</h4>
<p><b>Paciente:</b> {{paciente}} &nbsp; <b>Cédula:</b> {{cedula}} &nbsp; <b>Fecha de nacimiento:</b> {{fecha_nacimiento}}</p>
<p>De conformidad con la normativa vigente de protección de datos personales, autorizo a <b>L'Chaim, salud &amp; vida</b> a recopilar, registrar, conservar y utilizar mis datos personales y de salud (identificación, contacto, antecedentes, signos vitales, diagnósticos, indicaciones, exámenes y documentos clínicos) para las siguientes finalidades:</p>
<ul>
<li>brindarme atención médica y de enfermería y dar continuidad a mi tratamiento;</li>
<li>compartir mi historia clínica entre los profesionales de la clínica que participen en mi atención;</li>
<li>gestionar citas, recordatorios y comunicaciones por correo electrónico o WhatsApp;</li>
<li>facturación, cobros y obligaciones legales aplicables.</li>
</ul>
<p>Entiendo que mis datos se tratan de forma confidencial, con acceso restringido por roles y registro de accesos, y que no se compartirán con terceros salvo obligación legal o con mi autorización expresa. Puedo ejercer en cualquier momento mis derechos de acceso, rectificación, actualización, eliminación y oposición, solicitándolo a la clínica.</p>
<p><b>Fecha:</b> {{fecha}}</p>
<p style="margin-top:28px;">Firma del titular o representante: ______________________________</p>
</div>
HTML
    ],
    ['Consentimiento informado para sueroterapia — L\'Chaim', <<<'HTML'
<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2b2b2b;line-height:1.5;">
<h4 style="color:#2a7f86;margin:0 0 10px 0;">Consentimiento informado para sueroterapia (terapia intravenosa) — L'Chaim</h4>
<p><b>Paciente:</b> {{paciente}} &nbsp; <b>Cédula:</b> {{cedula}} &nbsp; <b>Fecha de nacimiento:</b> {{fecha_nacimiento}}</p>
<p>Yo, <b>{{paciente}}</b>, he sido informado/a por el personal de L'Chaim sobre la administración de soluciones, vitaminas u otros medicamentos por vía intravenosa indicados por mi médico tratante.</p>
<p>Comprendo que:</p>
<ul>
<li>el procedimiento requiere la colocación de una vía intravenosa y puede causar molestia, hematoma, enrojecimiento o inflamación en el sitio de punción;</li>
<li>pueden presentarse, con baja frecuencia, reacciones adversas o alérgicas, que deben comunicarse de inmediato al personal;</li>
<li>he informado con veracidad mis alergias, enfermedades, embarazo o lactancia y los medicamentos que tomo;</li>
<li>durante la infusión se registrarán mis signos vitales, el lote del producto aplicado y las observaciones de enfermería;</li>
<li>puedo solicitar suspender el procedimiento en cualquier momento.</li>
</ul>
<p>Autorizo la aplicación de la sueroterapia indicada y declaro que mis preguntas fueron respondidas.</p>
<p><b>Fecha:</b> {{fecha}}</p>
<p style="margin-top:28px;">Firma del paciente o representante: ______________________________</p>
</div>
HTML
    ]
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Documentos L'Chaim</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:700px;">
<h4>Crear documentos para firmar de L'Chaim</h4>
<ul><?php foreach ($documentos as $d): ?><li><?php echo h($d[0]); ?></li><?php endforeach; ?></ul>
<p class="text-muted">Son textos base en español. <b>Deben ser revisados por la clínica</b> antes de enviarlos a pacientes. No se modifican los documentos existentes.</p>
<form method="POST"><button class="btn btn-primary" type="submit">Crear documentos</button> <a class="btn btn-link" href="gestionar_documentos.php">Volver</a></form></div></body></html>
    <?php
    exit;
}
$rep = [];
$sel = $conexion->prepare("SELECT id_documento FROM documentos WHERE titulo = ? LIMIT 1");
$ins = $conexion->prepare("INSERT INTO documentos (titulo, contenido, archivo_pdf, estado) VALUES (?, ?, NULL, 1)");
foreach ($documentos as [$titulo, $contenido]) {
    $sel->bind_param('s', $titulo); $sel->execute();
    if ($sel->get_result()->fetch_assoc()) { $rep[] = ['OK', "«$titulo» ya existía (no se modificó)"]; continue; }
    $ins->bind_param('ss', $titulo, $contenido);
    $rep[] = $ins->execute() ? ['NEW', "«$titulo» creado"] : ['ERR', "«$titulo»: ".$conexion->error];
}
$sel->close(); $ins->close();
auditar($conexion, 'Documentos', 'crear', 'documentos', null, 'Creó los documentos base de L\'Chaim');
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Resultado</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:700px;"><h4>Resultado</h4><ul class="list-group">
<?php foreach ($rep as $r): ?><li class="list-group-item py-1"><span class="badge bg-<?php echo $r[0]==='NEW'?'success':($r[0]==='ERR'?'danger':'secondary'); ?> me-2"><?php echo h($r[0]); ?></span><?php echo h($r[1]); ?></li><?php endforeach; ?>
</ul><a class="btn btn-primary mt-3" href="gestionar_documentos.php">Ir a Documentos</a></div></body></html>
