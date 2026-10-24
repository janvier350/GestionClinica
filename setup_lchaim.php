<?php
/**
 * setup_lchaim.php — Preparación inicial de una instalación para L'Chaim (SISTEMA, idempotente):
 *   1. Nombre de la clínica (correos, login, documentos).
 *   2. Servicios nuevos: Sueroterapia (lo atiende enfermería) y Ecografía.
 *   3. Localidad "Kennedy" (y, opcionalmente, desactivar las demás).
 *   4. Módulos: activa Triaje, Derivación/Sueros, Exámenes y Registro de atención; apaga Nutrición.
 * Nada se elimina; cada paso se puede desmarcar.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/modulos.php");
require_once(__DIR__ . "/class/mailer.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') { http_response_code(403); exit('Acceso restringido: sólo SISTEMA.'); }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$brandActual = mail_brand();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Preparar L'Chaim</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:720px;">
<h4>Preparar la instalación para L'Chaim</h4>
<p class="text-muted">Idempotente: si algo ya existe, no se duplica. No se elimina nada.</p>
<form method="POST" class="card card-body">
  <div class="mb-3"><label class="form-label">Nombre de la clínica</label><input name="brand" class="form-control" value="L'Chaim" maxlength="80">
    <div class="form-text">Hoy: <b><?php echo h($brandActual); ?></b>. Se usa en correos, login y documentos.</div></div>
  <div class="form-check"><input class="form-check-input" type="checkbox" name="servicios" id="s1" checked><label class="form-check-label" for="s1">Crear servicios <b>Sueroterapia</b> (lo atiende la enfermera) y <b>Ecografía</b></label></div>
  <div class="form-check ms-4"><input class="form-check-input" type="checkbox" name="consulta" id="s1b" checked><label class="form-check-label" for="s1b">Crear también <b>Consulta general</b> si no existe</label></div>
  <div class="form-check ms-4"><input class="form-check-input" type="checkbox" name="desactivar_heredados" id="s1c"><label class="form-check-label" for="s1c">Desactivar los servicios heredados en inglés (Aged care, Follow-up, Group Services, Initial, Telehealth consultation) — no se borran</label></div>
  <div class="form-check"><input class="form-check-input" type="checkbox" name="sede" id="s2" checked><label class="form-check-label" for="s2">Crear la localidad <b>Kennedy</b></label></div>
  <div class="form-check ms-4"><input class="form-check-input" type="checkbox" name="desactivar_otras" id="s3"><label class="form-check-label" for="s3">Desactivar las demás localidades (no se borran)</label></div>
  <div class="form-check"><input class="form-check-input" type="checkbox" name="modulos" id="s4" checked><label class="form-check-label" for="s4">Activar Triaje, Derivación/Sueros, Exámenes y Registro de atención; apagar Nutrición</label></div>
  <button class="btn btn-primary mt-3" type="submit">Preparar</button> <a class="btn btn-link" href="modulos_config.php">Volver</a>
</form></div></body></html>
    <?php
    exit;
}

$rep = [];
// 1) Nombre
$brand = trim($_POST['brand'] ?? '');
if ($brand !== '') { mail_cfg_guardar($conexion, ['brand' => $brand]); $rep[] = ['OK', "Nombre de la clínica: $brand"]; }

// 2) Servicios
if (isset($_POST['servicios'])) {
    $r = @$conexion->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='AG_TIPOCONSULTA' AND COLUMN_NAME='ROL_ATIENDE'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) @$conexion->query("ALTER TABLE AG_TIPOCONSULTA ADD COLUMN ROL_ATIENDE VARCHAR(20) NULL");
    $lista = [['Sueroterapia','SUE','#00a3a3','ENFERMERA'], ['Ecografía','ECO','#7e57c2','']];
    if (isset($_POST['consulta'])) array_unshift($lista, ['Consulta general','CG','#3788d8','']);
    foreach ($lista as [$nom,$abr,$col,$rol]) {
        $q = $conexion->prepare("SELECT IDTIPOCONSULTA, ESTADO FROM AG_TIPOCONSULTA WHERE LOWER(TRIM(NOMBRES)) = LOWER(?) LIMIT 1");
        $q->bind_param('s', $nom); $q->execute(); $f = $q->get_result()->fetch_assoc(); $q->close();
        if ($f) {
            $u = $conexion->prepare("UPDATE AG_TIPOCONSULTA SET ESTADO='A', ROL_ATIENDE=NULLIF(?,'') WHERE IDTIPOCONSULTA=?"); $id = (int)$f['IDTIPOCONSULTA']; $u->bind_param('si', $rol, $id); $u->execute(); $u->close();
            $rep[] = ['OK', "Servicio «$nom» ya existía (activo".($rol ? ", lo atiende $rol" : '').')'];
        } else {
            $i = $conexion->prepare("INSERT INTO AG_TIPOCONSULTA (NOMBRES, ABREVIATURA, COLOR, ROL_ATIENDE, ESTADO) VALUES (?, ?, ?, NULLIF(?,''), 'A')");
            $i->bind_param('ssss', $nom, $abr, $col, $rol); $i->execute(); $i->close();
            $rep[] = ['NEW', "Servicio «$nom» creado".($rol ? " (lo atiende $rol)" : '')];
        }
    }
}
if (isset($_POST['desactivar_heredados'])) {
    $her = ['Aged care consultation','Follow-up consultation','Group Services','Initial consultation','Telehealth consultation'];
    $dst = $conexion->prepare("UPDATE AG_TIPOCONSULTA SET ESTADO='I' WHERE LOWER(TRIM(NOMBRES)) = LOWER(?) AND ESTADO='A'");
    $n = 0; foreach ($her as $h2) { $dst->bind_param('s', $h2); $dst->execute(); $n += $dst->affected_rows; } $dst->close();
    $rep[] = ['OK', "Servicios heredados desactivados: $n (siguen en Tipos de consulta, inactivos)"];
}
// 3) Localidad
if (isset($_POST['sede'])) {
    $q = $conexion->query("SELECT IDAGENCIA FROM ADM_AGENCIA WHERE LOWER(TRIM(DESCRIPCION)) = 'kennedy' LIMIT 1");
    if ($q && ($f = $q->fetch_assoc())) { $kid = (int)$f['IDAGENCIA']; $conexion->query("UPDATE ADM_AGENCIA SET ESTADO=1 WHERE IDAGENCIA=$kid"); $rep[] = ['OK', 'Localidad «Kennedy» ya existía (activa)']; }
    else {
        $kid = (int)$conexion->query("SELECT COALESCE(MAX(IDAGENCIA),0)+1 n FROM ADM_AGENCIA")->fetch_assoc()['n'];
        $conexion->query("INSERT INTO ADM_AGENCIA (IDAGENCIA, DESCRIPCION, ESTADO) VALUES ($kid, 'Kennedy', 1)");
        $rep[] = ['NEW', 'Localidad «Kennedy» creada — completa su dirección en Localidades'];
    }
    if (isset($_POST['desactivar_otras'])) {
        $conexion->query("UPDATE ADM_AGENCIA SET ESTADO=0 WHERE IDAGENCIA <> $kid");
        $rep[] = ['OK', 'Las demás localidades quedaron inactivas (no se borraron)'];
    }
}
// 4) Módulos
if (isset($_POST['modulos'])) {
    foreach (['triaje'=>true,'derivacion'=>true,'examenes'=>true,'registro_atencion'=>true,'nutricion'=>false] as $m => $on) {
        modulo_guardar($conexion, $m, $on); $rep[] = ['OK', "Módulo $m: ".($on ? 'activado' : 'apagado')];
    }
}
auditar($conexion, 'Configuración', 'editar', 'CONFIG', null, 'Ejecutó la preparación para L\'Chaim');
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Resultado</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:720px;"><h4>Resultado</h4><ul class="list-group">
<?php foreach ($rep as $r): ?><li class="list-group-item py-1"><span class="badge bg-<?php echo $r[0]==='NEW'?'success':'secondary'; ?> me-2"><?php echo h($r[0]); ?></span><?php echo h($r[1]); ?></li><?php endforeach; ?>
</ul><a class="btn btn-primary mt-3" href="gestionar_agencias.php">Ir a Localidades</a> <a class="btn btn-link" href="SCH_Calendar.php">Calendario</a></div></body></html>
