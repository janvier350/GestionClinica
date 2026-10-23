<?php
/**
 * migrar_icd10_lchaim.php
 * Ingresa el listado de CÓDIGOS NUTRICIONALES (ICD-10) entregado por la clínica
 * (CODIGOS_NUTRICIONALES.pdf) en ENFERMEDADES_DIAGNOSTICO + ENFE_DIAG_COD.
 *
 *  - NO duplica: un código que ya existe (sin distinguir mayúsculas/espacios) se omite
 *    y se informa. Opcionalmente se puede actualizar su descripción con la del PDF.
 *  - Dentro del propio PDF, R63.8 aparece dos veces: se toma la primera.
 *  - Idempotente. SISTEMA-only, POST-driven. Muestra un reporte por código.
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/auditoria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') { http_response_code(403); exit('Acceso restringido: sólo SISTEMA.'); }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$categorias = [
    'LCH01' => ['nombre' => 'Códigos nutricionales', 'desc' => 'Asesoramiento dietético, sobrepeso/obesidad, desnutrición y alteraciones del peso'],
    'LCH02' => ['nombre' => 'Enfermedades metabólicas y endocrinas', 'desc' => 'Diabetes, dislipidemias, tiroides y deficiencias vitamínicas'],
    'LCH03' => ['nombre' => 'Enfermedades cardiovasculares y renales', 'desc' => 'Hipertensión, enfermedad renal crónica y trastornos electrolíticos'],
    'LCH04' => ['nombre' => 'Enfermedades digestivas y hepáticas', 'desc' => 'Hígado, estómago, intestino, vesícula y páncreas'],
    'LCH05' => ['nombre' => 'Alimentación, síntomas y problemas nutricionales', 'desc' => 'Síntomas de la ingesta, hábitos y clasificación del IMC'],
    'LCH06' => ['nombre' => 'Otras condiciones asociadas', 'desc' => 'Anemias, oncología, hepatitis, osteoporosis, sueño y embarazo']
];

/** [categoría, código, descripción] */
$codigos = [
    ['LCH01', 'Z71.3', 'Asesoramiento y vigilancia dietética'],
    ['LCH01', 'Z71.2', 'Consulta para explicación de resultados de exámenes'],
    ['LCH01', 'E66.3', 'Sobrepeso'],
    ['LCH01', 'E66.9', 'Obesidad, no especificada'],
    ['LCH01', 'E44.1', 'Desnutrición proteico-calórica leve'],
    ['LCH01', 'E44.0', 'Desnutrición proteico-calórica moderada'],
    ['LCH01', 'E43', 'Desnutrición proteico-calórica severa'],
    ['LCH01', 'E46', 'Desnutrición proteico-calórica no especificada'],
    ['LCH01', 'R63.4', 'Pérdida anormal de peso'],
    ['LCH01', 'R63.5', 'Aumento anormal de peso'],
    ['LCH01', 'R63.6', 'Insuficiente ingesta de alimentos y agua'],
    ['LCH01', 'R63.8', 'Otros síntomas y signos relacionados con la ingesta de alimentos y líquidos'],
    ['LCH02', 'E11.9', 'Diabetes mellitus tipo 2 sin complicaciones'],
    ['LCH02', 'E10.9', 'Diabetes mellitus tipo 1 sin complicaciones'],
    ['LCH02', 'E14.9', 'Diabetes mellitus no especificada, sin complicaciones'],
    ['LCH02', 'E16.2', 'Hipoglucemia, no especificada'],
    ['LCH02', 'E78.0', 'Hipercolesterolemia puro'],
    ['LCH02', 'E78.1', 'Hipertrigliceridemia pura'],
    ['LCH02', 'E78.2', 'Hiperlipidemia mixta'],
    ['LCH02', 'E78.5', 'Hiperlipidemia no especificada'],
    ['LCH02', 'E03.9', 'Hipotiroidismo, no especificado'],
    ['LCH02', 'E05.9', 'Hipertiroidismo, no especificado'],
    ['LCH02', 'E04.9', 'Bocio no tóxico, no especificado'],
    ['LCH02', 'E88.8', 'Otros trastornos metabólicos especificados'],
    ['LCH02', 'E55.9', 'Deficiencia de vitamina D, no especificada'],
    ['LCH02', 'E53.8', 'Deficiencia de otras vitaminas del grupo B'],
    ['LCH02', 'E61.1', 'Deficiencia de hierro'],
    ['LCH02', 'E50.9', 'Deficiencia de vitamina A, no especificada'],
    ['LCH02', 'E51.9', 'Deficiencia de tiamina, no especificada'],
    ['LCH02', 'E54', 'Deficiencia de ácido ascórbico (vitamina C)'],
    ['LCH02', 'E56.9', 'Deficiencia de vitaminas, no especificada'],
    ['LCH03', 'I10', 'Hipertensión esencial (primaria)'],
    ['LCH03', 'I11.9', 'Enfermedad cardíaca hipertensiva sin insuficiencia cardíaca'],
    ['LCH03', 'N18.1', 'Enfermedad renal crónica, estadio 1'],
    ['LCH03', 'N18.2', 'Enfermedad renal crónica, estadio 2'],
    ['LCH03', 'N18.3', 'Enfermedad renal crónica, estadio 3'],
    ['LCH03', 'N18.4', 'Enfermedad renal crónica, estadio 4'],
    ['LCH03', 'N18.5', 'Enfermedad renal crónica, estadio 5'],
    ['LCH03', 'N18.6', 'Enfermedad renal terminal'],
    ['LCH03', 'N18.9', 'Enfermedad renal crónica, no especificada'],
    ['LCH03', 'N17.9', 'Insuficiencia renal aguda, no especificada'],
    ['LCH03', 'E87.5', 'Hiperpotasemia'],
    ['LCH03', 'E87.6', 'Hipopotasemia'],
    ['LCH03', 'E87.1', 'Hipoosmolaridad e hiponatremia'],
    ['LCH03', 'E87.0', 'Hiperosmolaridad e hipernatremia'],
    ['LCH04', 'K76.0', 'Hígado graso, no clasificado en otra parte'],
    ['LCH04', 'K75.8', 'Otras enfermedades inflamatorias especificadas del hígado'],
    ['LCH04', 'K74.6', 'Otras cirrosis del hígado y cirrosis no especificada'],
    ['LCH04', 'K29.5', 'Gastritis crónica, no especificada'],
    ['LCH04', 'K29.7', 'Gastritis, no especificada'],
    ['LCH04', 'K21.9', 'Enfermedad por reflujo gastroesofágico sin esofagitis'],
    ['LCH04', 'K59.0', 'Estreñimiento'],
    ['LCH04', 'K58.9', 'Síndrome del intestino irritable, sin especificación'],
    ['LCH04', 'K90.0', 'Enfermedad celíaca'],
    ['LCH04', 'K90.9', 'Malabsorción intestinal, no especificada'],
    ['LCH04', 'K30', 'Dispepsia funcional'],
    ['LCH04', 'K52.9', 'Gastroenteritis y colitis no infecciosas, no especificadas'],
    ['LCH04', 'K80.2', 'Cálculo de la vesícula biliar sin colecistitis'],
    ['LCH04', 'K85.9', 'Pancreatitis aguda, no especificada'],
    ['LCH04', 'K86.1', 'Otras pancreatitis crónicas'],
    ['LCH05', 'R63.0', 'Anorexia o pérdida del apetito'],
    ['LCH05', 'R63.3', 'Dificultades de alimentación'],
    ['LCH05', 'R63.8', 'Otros síntomas y signos relacionados con la ingesta'],
    ['LCH05', 'R11.0', 'Náuseas'],
    ['LCH05', 'R11.2', 'Náuseas y vómitos, no especificados'],
    ['LCH05', 'R19.4', 'Cambios en los hábitos intestinales'],
    ['LCH05', 'R19.5', 'Otras anomalías fecales'],
    ['LCH05', 'R13.1', 'Disfagia'],
    ['LCH05', 'Z72.4', 'Dieta y hábitos alimentarios inapropiados'],
    ['LCH05', 'Z68.1', 'Índice de masa corporal (IMC) bajo en adultos, en la clasificación ICD-10-CM'],
    ['LCH05', 'Z68.2', 'IMC de adulto en rango normal, en la clasificación ICD-10-CM'],
    ['LCH05', 'Z68.3', 'IMC de adulto correspondiente a sobrepeso, en la clasificación ICD-10-CM'],
    ['LCH05', 'Z68.4', 'IMC de adulto correspondiente a obesidad, en la clasificación ICD-10-CM'],
    ['LCH06', 'D50.9', 'Anemia por deficiencia de hierro, no especificada'],
    ['LCH06', 'D64.9', 'Anemia, no especificada'],
    ['LCH06', 'C50.9', 'Tumor maligno de mama, no especificado'],
    ['LCH06', 'C18.9', 'Tumor maligno de colon, no especificado'],
    ['LCH06', 'C34.9', 'Tumor maligno de bronquios o pulmón, no especificado'],
    ['LCH06', 'B18.1', 'Hepatitis viral crónica B sin agente delta'],
    ['LCH06', 'B18.2', 'Hepatitis viral crónica C'],
    ['LCH06', 'M81.0', 'Osteoporosis relacionada con la edad, sin fractura patológica actual'],
    ['LCH06', 'G47.0', 'Trastornos del inicio y mantenimiento del sueño'],
    ['LCH06', 'O24.4', 'Diabetes mellitus gestacional'],
    ['LCH06', 'O99.0', 'Anemia que complica el embarazo, parto y puerperio'],
    ['LCH06', 'O99.2', 'Enfermedades endocrinas, nutricionales y metabólicas que complican el embarazo, parto y puerperio']
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $unicos = []; foreach ($codigos as $c) $unicos[strtolower($c[1])] = 1;
    ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importar códigos nutricionales</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:720px;">
<h4>Importar códigos nutricionales (ICD-10)</h4>
<p class="text-muted">Agrega <b><?php echo count($unicos); ?></b> códigos del listado de la clínica en <?php echo count($categorias); ?> categorías.
Los que ya existen <b>no se duplican</b> (se omiten y se informan).</p>
<form method="POST">
  <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="actualizar" id="act"><label class="form-check-label" for="act">También actualizar la descripción de los códigos que ya existen con el texto de este listado</label></div>
  <button class="btn btn-primary" type="submit">Importar</button> <a class="btn btn-link" href="PNC_CIE-10Crear.php">Volver</a></form></div></body></html>
    <?php
    exit;
}

$actualizar = isset($_POST['actualizar']);
$catId = [];
$chk = $conexion->prepare("SELECT ID_ENFERMEDAD FROM ENFERMEDADES_DIAGNOSTICO WHERE NOMBRE = ? LIMIT 1");
$ins = $conexion->prepare("INSERT INTO ENFERMEDADES_DIAGNOSTICO (CODIGO, NOMBRE, DESCRIPCION) VALUES (?, ?, ?)");
foreach ($categorias as $cod => $c) {
    $chk->bind_param('s', $c['nombre']); $chk->execute();
    if ($row = $chk->get_result()->fetch_assoc()) { $catId[$cod] = (int)$row['ID_ENFERMEDAD']; continue; }
    $ins->bind_param('sss', $cod, $c['nombre'], $c['desc']);
    if ($ins->execute()) $catId[$cod] = (int)$conexion->insert_id;
}
$chk->close(); $ins->close();

$busca = $conexion->prepare("SELECT ID_ENFE_DIAG_COD, DESCRIPCION FROM ENFE_DIAG_COD WHERE LOWER(TRIM(CODIGO)) = LOWER(TRIM(?)) LIMIT 1");
$nuevo = $conexion->prepare("INSERT INTO ENFE_DIAG_COD (ID_ENFERMEDAD, CODIGO, DESCRIPCION) VALUES (?, ?, ?)");
$upd   = $conexion->prepare("UPDATE ENFE_DIAG_COD SET DESCRIPCION = ? WHERE ID_ENFE_DIAG_COD = ?");
$vistos = []; $rep = []; $nNuevos = 0; $nExist = 0; $nAct = 0; $nDupPdf = 0; $nErr = 0;
foreach ($codigos as [$cat, $codigo, $desc]) {
    $k = strtolower(trim($codigo));
    if (isset($vistos[$k])) { $rep[] = ['DUP', "$codigo — repetido en el listado (se tomó la primera aparición)"]; $nDupPdf++; continue; }
    $vistos[$k] = 1;
    $busca->bind_param('s', $codigo); $busca->execute();
    if ($f = $busca->get_result()->fetch_assoc()) {
        if ($actualizar && trim($f['DESCRIPCION']) !== trim($desc)) {
            $upd->bind_param('si', $desc, $f['ID_ENFE_DIAG_COD']); $upd->execute();
            $rep[] = ['UPD', "$codigo — ya existía; descripción actualizada"]; $nAct++;
        } else { $rep[] = ['OK', "$codigo — ya existía (no se duplicó)"]; }
        $nExist++; continue;
    }
    $cid = (string)($catId[$cat] ?? 0);
    $nuevo->bind_param('iss', $catId[$cat], $codigo, $desc);
    if ($nuevo->execute()) { $rep[] = ['NEW', "$codigo — $desc"]; $nNuevos++; } else { $rep[] = ['ERR', "$codigo — ".$conexion->error]; $nErr++; }
}
$busca->close(); $nuevo->close(); $upd->close();
auditar($conexion, 'Configuración', 'crear', 'ENFE_DIAG_COD', null, "Importó códigos nutricionales: $nNuevos nuevos, $nExist ya existían".($actualizar ? ", $nAct actualizados" : ''));
$cls = ['NEW'=>'success','OK'=>'secondary','UPD'=>'info','DUP'=>'warning','ERR'=>'danger'];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Resultado</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:820px;">
<h4>Resultado de la importación</h4>
<div class="alert alert-<?php echo $nErr ? 'danger' : 'success'; ?>">
  <b><?php echo $nNuevos; ?></b> códigos nuevos · <b><?php echo $nExist; ?></b> ya existían (no se duplicaron)
  <?php if ($actualizar): ?> · <b><?php echo $nAct; ?></b> actualizados<?php endif; ?> · <b><?php echo $nDupPdf; ?></b> repetidos en el listado · <b><?php echo $nErr; ?></b> errores</div>
<ul class="list-group">
<?php foreach ($rep as $r): ?><li class="list-group-item py-1 small"><span class="badge bg-<?php echo $cls[$r[0]]; ?> me-2"><?php echo h($r[0]); ?></span><?php echo h($r[1]); ?></li><?php endforeach; ?>
</ul>
<a class="btn btn-primary mt-3" href="PNC_CIE-10Crear.php">Ir al catálogo ICD-10</a></div></body></html>
