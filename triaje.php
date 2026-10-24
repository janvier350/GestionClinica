<?php
/**
 * triaje.php — Estación de triaje (enfermería / asistente).
 *  - Lista de citas de hoy con su estado de triaje.
 *  - Formulario de signos vitales + motivo de consulta ligado a la cita.
 *  - "Paciente sin cita": crea una cita de hoy (a la hora actual) con el
 *    profesional elegido, SIN exigir ICD-10/dirección, y abre su triaje.
 * Al guardar, la cita queda 'Triada' y aparece lista para el profesional.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/triaje.php");
require_once(__DIR__ . "/class/pacientes_util.php");
require_once(__DIR__ . "/class/guia_rapida.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('triaje.registrar');
triaje_asegurar_tabla($conexion);

$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$idUser = (int)($_SESSION['iduser'] ?? 0);

$msg = $_SESSION['triaje_msg'] ?? null; unset($_SESSION['triaje_msg']);
function flash_y_volver($tipo, $texto, $url) {
    $_SESSION['triaje_msg'] = [$tipo, $texto];
    header('Location: ' . $url); exit();
}

// ── POST: guardar triaje ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $idCita = (int)($_POST['idCita'] ?? 0);
    $d = [
        'peso_kg' => $_POST['peso'] ?? '', 'talla_cm' => $_POST['talla'] ?? '', 'presion' => $_POST['presion'] ?? '',
        'fc' => $_POST['fc'] ?? '', 'sat_o2' => $_POST['sat_o2'] ?? '', 'temperatura' => $_POST['temperatura'] ?? '',
        'glucosa' => $_POST['glucosa'] ?? '', 'motivo' => $_POST['motivo'] ?? '',
    ];
    $volverAtt = (($_POST['from'] ?? '') === 'att');
    $destino = $volverAtt ? 'atencion_paciente.php?idCita='.$idCita : 'triaje.php';
    if ($idCita > 0 && triaje_guardar($conexion, $idCita, $d, $idUser)) {
        flash_y_volver('success', $en ? 'Triage saved. The patient is ready for the professional.' : 'Triaje guardado. El paciente queda listo para el profesional.', $destino);
    }
    flash_y_volver('danger', $en ? 'Could not save the triage.' : 'No se pudo guardar el triaje.', 'triaje.php'.($idCita?'?idCita='.$idCita:''));
}

// ── POST: paciente sin cita → crea cita de hoy y abre su triaje ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'sin_cita') {
    $idPac  = (int)($_POST['idPaciente'] ?? 0);
    $idDoc  = (int)($_POST['idDoctor'] ?? 0);
    $idTipo = (int)($_POST['idTipo'] ?? 0);
    $err = '';
    if ($idDoc <= 0 || $idTipo <= 0) $err = $en ? 'Choose the professional and the visit type.' : 'Elige el profesional y el tipo de consulta.';
    if (!$err && $idPac <= 0) {                       // paciente nuevo, datos mínimos
        $rn = paciente_crear_minimo($conexion, $_POST['nombres'] ?? '', $_POST['apellidos'] ?? '', $_POST['cedula'] ?? '',
                                    $_POST['telefono'] ?? '', $_POST['feNac'] ?? '', $_POST['sex'] ?? '', 'triaje, sin cita');
        if ($rn['ok']) { $idPac = $rn['id']; }
        elseif ($rn['error'] === 'DUPLICADO') $err = $en ? 'A patient with that ID or phone already exists. Search for them instead.' : 'Ya existe un paciente con esa cédula o teléfono. Búscalo en lugar de crearlo.';
        elseif ($rn['error'] === 'DATOS_INCOMPLETOS') $err = $en ? 'New patient: first name, last name and birth date are required.' : 'Paciente nuevo: nombres, apellidos y fecha de nacimiento son obligatorios.';
        else $err = $en ? 'Could not create the patient.' : 'No se pudo crear el paciente.';
    }
    if (!$err) {
        $hoy = date('Y-m-d'); $ini = date('H:i'); $fin = date('H:i', strtotime($ini) + 3600);
        $ic = $conexion->prepare("INSERT INTO AG_CITA (IDPACIENTE, IDTIPOCONSULTA, IDDOCTOR, IDUSUARIO, FECHA_CITA, HORA_INICIO, HORA_FIN, ESTADO_CITA, ESTADO, COMENTARIO)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente', 'A', 'Sin cita previa (triaje)')");
        $ic->bind_param('iiiisss', $idPac, $idTipo, $idDoc, $idUser, $hoy, $ini, $fin);
        if ($ic->execute()) {
            $idNueva = (int)$conexion->insert_id;
            auditar($conexion, 'Agenda', 'crear', 'AG_CITA', $idNueva, 'Cita sin previo aviso creada desde triaje, '.$hoy.' '.$ini);
            flash_y_volver('info', $en ? 'Walk-in appointment created. Record the vital signs.' : 'Cita sin previo aviso creada. Registra los signos vitales.', 'triaje.php?idCita='.$idNueva);
        }
        $err = $en ? 'Could not create the appointment.' : 'No se pudo crear la cita.';
    }
    flash_y_volver('danger', $err, 'triaje.php?sin_cita=1');
}

// ── Datos para la vista ─────────────────────────────────────────────────
$idCitaSel = (int)($_GET['idCita'] ?? 0);
$from      = (($_GET['from'] ?? '') === 'att') ? 'att' : '';
$sinCita   = !empty($_GET['sin_cita']);
$cita = null; $tri = null; $obsRecepcion = '';
if ($idCitaSel > 0) {
    $q = $conexion->query("SELECT A.IDCITA, A.FECHA_CITA, A.HORA_INICIO, A.ESTADO_CITA, A.IDPACIENTE,
                                  CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA, P.FECHANACIMIENTO,
                                  CONCAT(D.NOMBRES,' ',D.APELLIDOS) AS DOCTOR
                             FROM AG_CITA A
                             INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = A.IDPACIENTE
                             LEFT JOIN ADM_USUARIO D ON D.IDADM_USUARIO = A.IDDOCTOR
                            WHERE A.IDCITA = $idCitaSel AND A.ESTADO='A' LIMIT 1");
    $cita = $q ? $q->fetch_assoc() : null;
    if ($cita) {
        $tri = triaje_obtener($conexion, $idCitaSel);
        $qo = @$conexion->query("SELECT OBSERVACION FROM turno WHERE IDCITA = $idCitaSel AND OBSERVACION IS NOT NULL LIMIT 1");
        $obsRecepcion = ($qo && ($xo = $qo->fetch_assoc())) ? $xo['OBSERVACION'] : '';
    }
}

$hoyLista = [];
$hoyStr    = date('Y-m-d');
$fechaSel  = $_GET['fecha'] ?? $hoyStr;
$fd = DateTime::createFromFormat('Y-m-d', $fechaSel);
if (!$fd || $fd->format('Y-m-d') !== $fechaSel) $fechaSel = $hoyStr;
$busca     = trim($_GET['q'] ?? '');
$esHoy     = ($fechaSel === $hoyStr);
$fPrev     = date('Y-m-d', strtotime($fechaSel . ' -1 day'));
$fNext     = date('Y-m-d', strtotime($fechaSel . ' +1 day'));
if (!$cita && !$sinCita) {
    // Con búsqueda: cualquier cita desde hace 7 días hasta 90 días adelante; sin búsqueda: la fecha elegida.
    $sql = "SELECT A.IDCITA, A.FECHA_CITA, A.HORA_INICIO, A.ESTADO_CITA, A.COMENTARIO,
                   CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA,
                   CONCAT(D.NOMBRES,' ',D.APELLIDOS) AS DOCTOR,
                   T.IDTRIAJE, T.MOTIVO,
                   (SELECT TU.OBSERVACION FROM turno TU WHERE TU.IDCITA = A.IDCITA AND TU.OBSERVACION IS NOT NULL LIMIT 1) AS OBS_RECEPCION
              FROM AG_CITA A
              INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = A.IDPACIENTE
              LEFT JOIN ADM_USUARIO D ON D.IDADM_USUARIO = A.IDDOCTOR
              LEFT JOIN triaje T ON T.IDCITA = A.IDCITA
             WHERE A.ESTADO='A'
               AND (A.ESTADO_CITA IS NULL OR A.ESTADO_CITA NOT IN ('Cancelada','Cancelado','Cancelación Tardía','Cancelado por Profesional','No Asistió'))";
    if ($busca !== '') {
        $like = '%' . $busca . '%';
        $sql .= " AND A.FECHA_CITA BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 90 DAY)
                  AND (P.NOMBRES LIKE ? OR P.APELLIDOS LIKE ? OR P.CEDULA LIKE ? OR CONCAT(P.NOMBRES,' ',P.APELLIDOS) LIKE ?)
                  ORDER BY A.FECHA_CITA, A.HORA_INICIO";
        $st = $conexion->prepare($sql);
        $st->bind_param('ssssss', $hoyStr, $hoyStr, $like, $like, $like, $like);
    } else {
        $sql .= " AND A.FECHA_CITA = ? ORDER BY (T.IDTRIAJE IS NOT NULL), A.HORA_INICIO";
        $st = $conexion->prepare($sql);
        $st->bind_param('s', $fechaSel);
    }
    if ($st && $st->execute()) { $q = $st->get_result(); while ($r = $q->fetch_assoc()) $hoyLista[] = $r; $st->close(); }
}

$doctores = []; $tipos = [];
if ($sinCita) {
    $q = $conexion->query("SELECT U.IDADM_USUARIO id, CONCAT(U.NOMBRES,' ',U.APELLIDOS) nombre FROM ADM_USUARIO U
                            INNER JOIN ADM_ROL R ON R.IDADM_ROL = U.IDADM_ROL WHERE R.CARGO='DOCTOR' AND U.ESTADO='A' ORDER BY U.NOMBRES");
    if ($q) while ($r = $q->fetch_assoc()) $doctores[] = $r;
    $q = $conexion->query("SELECT IDTIPOCONSULTA id, NOMBRES nombre FROM AG_TIPOCONSULTA WHERE ESTADO='A' ORDER BY NOMBRES");
    if ($q) while ($r = $q->fetch_assoc()) $tipos[] = $r;
}
function tv($tri, $k) {
    if (!$tri || $tri[$k] === null) return '';
    $v = (string)$tri[$k];
    if (strpos($v, '.') !== false) $v = rtrim(rtrim($v, '0'), '.');   // 36.50 → 36.5 ; 100 queda 100
    return h($v);
}
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Triage':'Triaje'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-like2 icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Triage':'Triaje'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Vital signs and reason for visit, before the professional sees the patient.':'Signos vitales y motivo de consulta, antes de que el profesional atienda.'; ?></div>
                </div>
            </div>
            <div class="page-title-actions">
                <?php if ($cita || $sinCita): ?><a href="triaje.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> <?php echo $en?'Back to the list':'Volver a la lista'; ?></a>
                <?php else: ?><a href="triaje.php?sin_cita=1" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> <?php echo $en?'Patient without appointment':'Paciente sin cita'; ?></a><?php endif; ?>
            </div></div></div>

            <?php if ($msg): ?><div class="alert alert-<?php echo h($msg[0]); ?>"><?php echo h($msg[1]); ?></div><?php endif; ?>

<?php if (!$cita && !$sinCita): ?>
            <?php if (modulo_activo('atencion_rapida')) echo guia_rapida_html($en, $_SESSION['rol'] ?? '', 2); ?>
            <form method="GET" class="card shadow-sm mb-3"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="triaje.php?fecha=<?php echo h($fPrev); ?>" title="<?php echo $en?'Previous day':'Día anterior'; ?>"><i class="bi bi-chevron-left"></i></a>
                <input type="date" name="fecha" value="<?php echo h($fechaSel); ?>" class="form-control form-control-sm" style="max-width:160px;" onchange="this.form.submit()">
                <a class="btn btn-sm btn-outline-secondary" href="triaje.php?fecha=<?php echo h($fNext); ?>" title="<?php echo $en?'Next day':'Día siguiente'; ?>"><i class="bi bi-chevron-right"></i></a>
                <a class="btn btn-sm <?php echo $esHoy && $busca === '' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="triaje.php"><?php echo $en?'Today':'Hoy'; ?></a>
                <input type="text" name="q" value="<?php echo h($busca); ?>" class="form-control form-control-sm ms-md-auto" style="max-width:260px;" placeholder="<?php echo $en?'Search patient (any date)…':'Buscar paciente (cualquier fecha)…'; ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($busca !== ''): ?><a class="btn btn-sm btn-link" href="triaje.php"><?php echo $en?'Clear':'Limpiar'; ?></a><?php endif; ?>
            </div>
            <div class="px-3 pb-2 small text-muted">
                <?php if ($busca !== ''): ?><?php echo $en?'Results for':'Resultados para'; ?> “<?php echo h($busca); ?>” — <?php echo $en?'last 7 days to next 90 days':'últimos 7 días y próximos 90 días'; ?>.
                <?php else: ?><?php echo $en?'Showing':'Mostrando'; ?> <b><?php echo h(date('m/d/Y', strtotime($fechaSel))); ?></b><?php echo $esHoy ? ($en?' (today)':' (hoy)') : ''; ?> · <?php echo $en?'System date':'Fecha del sistema'; ?>: <?php echo h(date('m/d/Y H:i')); ?> (<?php echo h(date_default_timezone_get()); ?>)<?php endif; ?>
            </div></form>
<?php endif; ?>

<?php if ($cita): ?>
            <div class="card shadow-sm mb-3"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between">
                    <div><div class="fs-5 fw-semibold"><?php echo h($cita['PACIENTE']); ?></div>
                        <div class="text-muted small">
                            <?php echo h($cita['CEDULA'] ?: '—'); ?>
                            <?php if ($cita['FECHANACIMIENTO'] && $cita['FECHANACIMIENTO'] !== '0000-00-00'): ?> · <?php echo (int)date_diff(date_create($cita['FECHANACIMIENTO']), date_create('today'))->y; ?> <?php echo $en?'yrs':'años'; ?><?php endif; ?>
                        </div></div>
                    <div class="text-end small text-muted">
                        <div><?php echo $en?'Professional':'Profesional'; ?>: <b><?php echo h($cita['DOCTOR']); ?></b></div>
                        <div><?php echo h(date('m/d/Y', strtotime($cita['FECHA_CITA']))); ?> <?php echo h(substr($cita['HORA_INICIO'],0,5)); ?> · <?php echo h(estado_label($cita['ESTADO_CITA'])); ?></div>
                    </div>
                </div>
            </div></div>

            <?php if ($obsRecepcion !== ''): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill"></i> <b><?php echo $en?'Note from reception':'Nota de recepción'; ?>:</b> <?php echo h($obsRecepcion); ?></div><?php endif; ?>
            <form method="POST" class="card shadow-sm"><div class="card-body">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="idCita" value="<?php echo (int)$cita['IDCITA']; ?>">
                <input type="hidden" name="from" value="<?php echo h($from); ?>">
                <div class="row g-3">
                    <div class="col-6 col-md-3"><label class="form-label"><?php echo $en?'Weight (kg)':'Peso (kg)'; ?></label><input type="text" inputmode="decimal" name="peso" class="form-control" value="<?php echo tv($tri,'PESO_KG'); ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label"><?php echo $en?'Height (cm)':'Talla (cm)'; ?></label><input type="text" inputmode="decimal" name="talla" class="form-control" value="<?php echo tv($tri,'TALLA_CM'); ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label"><?php echo $en?'Blood pressure':'Presión arterial'; ?></label><input type="text" name="presion" maxlength="20" placeholder="120/80" class="form-control" value="<?php echo $tri ? h($tri['PRESION']) : ''; ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label"><?php echo $en?'Heart rate (bpm)':'FC (lpm)'; ?></label><input type="text" inputmode="numeric" name="fc" class="form-control" value="<?php echo tv($tri,'FC'); ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label">SatO₂ (%)</label><input type="text" inputmode="numeric" name="sat_o2" class="form-control" value="<?php echo tv($tri,'SAT_O2'); ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label"><?php echo $en?'Temperature (°C)':'Temperatura (°C)'; ?></label><input type="text" inputmode="decimal" name="temperatura" class="form-control" value="<?php echo tv($tri,'TEMPERATURA'); ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label"><?php echo $en?'Glucose (mg/dL)':'Glucosa (mg/dL)'; ?></label><input type="text" inputmode="decimal" name="glucosa" class="form-control" value="<?php echo tv($tri,'GLUCOSA'); ?>"></div>
                    <div class="col-12"><label class="form-label"><?php echo $en?'Reason for visit':'Motivo de consulta'; ?></label><textarea name="motivo" rows="3" class="form-control"><?php echo $tri ? h($tri['MOTIVO']) : ''; ?></textarea></div>
                </div>
                <div class="mt-3 d-flex align-items-center gap-3">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> <?php echo $en?'Save triage':'Guardar triaje'; ?></button>
                    <?php if ($tri): ?><span class="small text-muted"><?php echo $en?'Last recorded by':'Registrado por'; ?> <?php echo h($tri['REGISTRADO_POR']); ?> · <?php echo h(date('m/d/Y H:i', strtotime($tri['FECHA_MODIFICACION'] ?: $tri['FECHA_REGISTRO']))); ?></span><?php endif; ?>
                </div>
            </div></form>

<?php elseif ($sinCita): ?>
            <form method="POST" class="card shadow-sm"><div class="card-body">
                <input type="hidden" name="accion" value="sin_cita">
                <input type="hidden" name="idPaciente" id="idPaciente" value="0">
                <h6 class="mb-2"><?php echo $en?'1. Patient':'1. Paciente'; ?></h6>
                <input type="text" id="buscaPac" class="form-control mb-1" autocomplete="off" placeholder="<?php echo $en?'Search by name or ID (min. 2 letters)…':'Buscar por nombre o cédula (mín. 2 letras)…'; ?>">
                <div id="resPac" class="list-group mb-2"></div>
                <div id="pacSel" class="alert alert-success py-2 d-none"></div>
                <div id="nuevoPac">
                    <div class="small text-muted mb-1"><?php echo $en?'Not found? Enter the minimum data to create the patient:':'¿No aparece? Ingresa los datos mínimos para crearlo:'; ?></div>
                    <div class="row g-2">
                        <div class="col-md-3"><input name="nombres" class="form-control" placeholder="<?php echo $en?'First name':'Nombres'; ?>"></div>
                        <div class="col-md-3"><input name="apellidos" class="form-control" placeholder="<?php echo $en?'Last name':'Apellidos'; ?>"></div>
                        <div class="col-md-2"><input name="cedula" class="form-control" placeholder="<?php echo $en?'ID':'Cédula'; ?>"></div>
                        <div class="col-md-2"><input name="telefono" class="form-control" placeholder="<?php echo $en?'Phone':'Teléfono'; ?>"></div>
                        <div class="col-md-2"><input type="date" name="feNac" class="form-control" title="<?php echo $en?'Birth date':'Fecha de nacimiento'; ?>"></div>
                        <div class="col-md-2"><select name="sex" class="form-select"><option value="Male"><?php echo $en?'Male':'Masculino'; ?></option><option value="Fame"><?php echo $en?'Female':'Femenino'; ?></option></select></div>
                    </div>
                </div>
                <hr>
                <h6 class="mb-2"><?php echo $en?'2. Who will see them':'2. Quién lo atenderá'; ?></h6>
                <div class="row g-2">
                    <div class="col-md-6"><select name="idDoctor" class="form-select" required><option value=""><?php echo $en?'Professional…':'Profesional…'; ?></option>
                        <?php foreach ($doctores as $dd): ?><option value="<?php echo (int)$dd['id']; ?>"><?php echo h($dd['nombre']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><select name="idTipo" class="form-select" required><option value=""><?php echo $en?'Visit type…':'Tipo de consulta…'; ?></option>
                        <?php foreach ($tipos as $tt): ?><option value="<?php echo (int)$tt['id']; ?>"><?php echo h($tt['nombre']); ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-text"><?php echo $en?'An appointment for today at the current time is created. ICD-10 and address are not required here.':'Se crea una cita para hoy a la hora actual. Aquí no se exige ICD-10 ni dirección.'; ?></div>
                <div class="mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-arrow-right-circle"></i> <?php echo $en?'Create and record triage':'Crear y registrar triaje'; ?></button></div>
            </div></form>
            <script>
            (function(){
                var q=document.getElementById('buscaPac'), res=document.getElementById('resPac'), id=document.getElementById('idPaciente'),
                    sel=document.getElementById('pacSel'), nuevo=document.getElementById('nuevoPac'), t=null;
                function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
                q.addEventListener('input', function(){
                    clearTimeout(t); var v=q.value.trim();
                    if(v.length<2){ res.innerHTML=''; return; }
                    t=setTimeout(function(){
                        fetch('buscar_pacientes.php?q='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(a){
                            res.innerHTML=''; a.forEach(function(p){
                                var b=document.createElement('button'); b.type='button'; b.className='list-group-item list-group-item-action';
                                b.innerHTML='<b>'+esc(p.nombre)+'</b> <span class="text-muted small">'+esc(p.cedula||'')+' '+esc(p.telefono||'')+'</span>';
                                b.onclick=function(){ id.value=p.id; sel.textContent=p.nombre; sel.classList.remove('d-none'); nuevo.classList.add('d-none'); res.innerHTML=''; q.value=''; };
                                res.appendChild(b);
                            });
                        });
                    },250);
                });
                sel.addEventListener('click', function(){ id.value=0; sel.classList.add('d-none'); nuevo.classList.remove('d-none'); });
                sel.title='Click para quitar';
            })();
            </script>

<?php else: ?>
            <div class="card shadow-sm"><div class="card-body p-0">
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead><tr><?php if ($busca !== ''): ?><th><?php echo $en?'Date':'Fecha'; ?></th><?php endif; ?><th><?php echo $en?'Time':'Hora'; ?></th><th><?php echo $en?'Patient':'Paciente'; ?></th><th><?php echo $en?'Professional':'Profesional'; ?></th><th><?php echo $en?'Reason':'Motivo'; ?></th><th><?php echo $en?'Status':'Estado'; ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($hoyLista as $r): $hecho = $r['IDTRIAJE'] !== null; ?>
                        <tr>
                            <?php if ($busca !== ''): ?><td><?php echo h(date('m/d/Y', strtotime($r['FECHA_CITA']))); ?></td><?php endif; ?>
                            <td><?php echo h(substr($r['HORA_INICIO'],0,5)); ?></td>
                            <td><b><?php echo h($r['PACIENTE']); ?></b><div class="small text-muted"><?php echo h($r['CEDULA']); ?></div>
                                <?php if (!empty($r['OBS_RECEPCION'])): ?><div class="small text-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo h($r['OBS_RECEPCION']); ?></div><?php endif; ?></td>
                            <td><?php echo h($r['DOCTOR']); ?></td>
                            <td class="small"><?php echo h(mb_strimwidth((string)$r['MOTIVO'], 0, 60, '…')); ?></td>
                            <td><?php if ($hecho): ?><span class="badge bg-info text-dark"><?php echo $en?'Triaged':'Triada'; ?></span><?php else: ?><span class="badge bg-warning text-dark"><?php echo $en?'Waiting':'Por triar'; ?></span><?php endif; ?></td>
                            <td class="text-end"><a class="btn btn-sm <?php echo $hecho?'btn-outline-primary':'btn-primary'; ?>" href="triaje.php?idCita=<?php echo (int)$r['IDCITA']; ?>"><?php echo $hecho ? ($en?'Edit':'Editar') : ($en?'Record triage':'Registrar triaje'); ?></a></td>
                        </tr>
                    <?php endforeach; if (!$hoyLista): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4"><?php echo $en?'No appointments for this date.':'No hay citas para esta fecha.'; ?></td></tr>
                    <?php endif; ?>
                    </tbody></table></div>
            </div></div>
<?php endif; ?>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
