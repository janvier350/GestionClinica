<?php
/**
 * sala_espera.php — Sala de espera del profesional: pacientes que esperan sus servicios (cola por servicio, o los
 * que lo eligieron a él/ella). "Atender" crea la cita del día y abre la atención; "Terminar" cierra la atención.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/turnos.php");
require_once(__DIR__ . "/class/guia_rapida.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir_modulo('atencion_rapida');
requerir('rapida.atender');
turnos_asegurar_tablas($conexion);
$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$uid = (int)($_SESSION['iduser'] ?? 0); $rol = (string)($_SESSION['rol'] ?? '');

function sala_html($conexion, $en, $uid, $rol) {
    $esp = []; $mios = [];
    foreach (turnos_hoy($conexion) as $t) {
        if ($t['ESTADO'] === 'Espera' && turno_visible_para($t, $uid, $rol)) $esp[] = $t;
        elseif ($t['ESTADO'] === 'Atencion' && ((int)$t['IDUSUARIO_ATIENDE'] === $uid || strtoupper($rol) === 'SISTEMA')) $mios[] = $t;
    }
    ob_start(); ?>
    <h6><?php echo $en?'Waiting for me':'En espera para mí'; ?> <span class="badge bg-warning text-dark" id="nEspera"><?php echo count($esp); ?></span></h6>
    <?php if (!$esp): ?><div class="card shadow-sm mb-3"><div class="card-body text-center text-muted py-4"><?php echo $en?'Nobody waiting.':'No hay pacientes en espera.'; ?></div></div><?php endif; ?>
    <div class="row g-3 mb-3">
    <?php foreach ($esp as $t): ?>
      <div class="col-md-6 col-xl-4"><div class="card shadow-sm h-100 border-warning"><div class="card-body">
        <div class="d-flex justify-content-between"><div class="fs-6 fw-semibold"><?php echo h($t['PACIENTE']); ?></div>
            <span class="small <?php echo (int)$t['MIN_ESPERA'] >= 30 ? 'text-danger fw-bold' : 'text-muted'; ?>"><i class="bi bi-clock"></i> <?php echo h(turno_minutos_txt($t['MIN_ESPERA'])); ?></span></div>
        <div class="small text-muted"><?php echo h($t['CEDULA']); ?> · <?php echo $en?'arrived':'llegó'; ?> <?php echo h(date('H:i', strtotime($t['FECHA_INGRESO']))); ?></div>
        <div class="mt-1"><span class="badge bg-primary"><?php echo h($t['SERVICIOS'] ?: $t['SERVICIO']); ?></span>
            <?php if ($t['IDTRIAJE'] !== null): ?><span class="badge bg-info text-dark"><?php echo $en?'Triaged':'Con triaje'; ?></span><?php endif; ?>
            <?php if ($t['ESPECIALISTA']): ?><span class="badge bg-light text-dark border"><i class="bi bi-person-badge"></i> <?php echo h($t['ESPECIALISTA']); ?></span><?php endif; ?></div>
        <?php if ($t['MOTIVO']): ?><div class="small fst-italic mt-1"><?php echo h($t['MOTIVO']); ?></div><?php endif; ?>
        <button class="btn btn-success w-100 mt-2" onclick="atender(<?php echo (int)$t['IDTURNO']; ?>)"><i class="bi bi-play-fill"></i> <?php echo $en?'Attend':'Atender'; ?></button>
      </div></div></div>
    <?php endforeach; ?>
    </div>
    <h6><?php echo $en?'In my care':'En atención'; ?> <span class="badge bg-primary"><?php echo count($mios); ?></span></h6>
    <?php if (!$mios): ?><div class="card shadow-sm"><div class="card-body text-center text-muted py-3 small">—</div></div><?php endif; ?>
    <div class="row g-3">
    <?php foreach ($mios as $t): ?>
      <div class="col-md-6 col-xl-4"><div class="card shadow-sm h-100 border-primary"><div class="card-body">
        <div class="fs-6 fw-semibold"><?php echo h($t['PACIENTE']); ?></div>
        <div class="small text-muted"><?php echo h($t['SERVICIOS'] ?: $t['SERVICIO']); ?> · <?php echo $en?'since':'desde'; ?> <?php echo $t['FECHA_INICIO'] ? h(date('H:i', strtotime($t['FECHA_INICIO']))) : '—'; ?></div>
        <div class="d-flex gap-2 mt-2">
          <?php if ($t['IDCITA']): ?><a class="btn btn-primary btn-sm flex-fill" href="atencion_paciente.php?idCita=<?php echo (int)$t['IDCITA']; ?>"><i class="bi bi-journal-medical"></i> <?php echo $en?'Open':'Abrir'; ?></a><?php endif; ?>
          <button class="btn btn-outline-success btn-sm flex-fill" onclick="terminar(<?php echo (int)$t['IDTURNO']; ?>)"><i class="bi bi-check2-circle"></i> <?php echo $en?'Finish':'Terminar'; ?></button>
        </div>
      </div></div></div>
    <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}
if (isset($_GET['tablero'])) { header('Content-Type: text/html; charset=utf-8'); echo sala_html($conexion, $en, $uid, $rol); exit; }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Waiting room':'Sala de espera'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-users icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Waiting room':'Sala de espera'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Patients waiting for your services. Press “Attend” to take one.':'Pacientes que esperan sus servicios. Pulsa “Atender” para tomar uno.'; ?></div>
                </div></div></div></div>
            <?php echo guia_rapida_html($en, $rol, 3); ?>
            <div id="sala"><?php echo sala_html($conexion, $en, $uid, $rol); ?></div>
            <div class="small text-muted mt-3"><i class="bi bi-arrow-repeat"></i> <?php echo $en?'Updates every 10 seconds.':'Se actualiza cada 10 segundos.'; ?></div>
        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var EN = <?php echo json_encode($en); ?>, ultimo = parseInt((document.getElementById('nEspera')||{}).textContent || '0', 10), titulo = document.title;
function atender(id){
    var fd = new FormData(); fd.append('accion','tomar'); fd.append('idTurno', id);
    fetch('turno_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        if (j.ok) { location.href = j.url; }
        else { alert(j.error === 'YA_TOMADO' ? (EN?'Someone else already took this patient.':'Otro profesional ya tomó a este paciente.') : (EN?'Could not take the patient: ':'No se pudo tomar al paciente: ') + (j.error||'')); refrescar(); }
    });
}
function terminar(id){ if (!confirm(EN?'Finish this care? The patient moves to payment.':'¿Terminar esta atención? El paciente pasa a cobro.')) return; var fd = new FormData(); fd.append('accion','terminar'); fd.append('idTurno', id); fetch('turno_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(){ refrescar(); }); }
function refrescar(){
    fetch('sala_espera.php?tablero=1', {credentials:'same-origin'}).then(function(r){ return r.text(); }).then(function(h){
        document.getElementById('sala').innerHTML = h;
        var n = parseInt((document.getElementById('nEspera')||{}).textContent || '0', 10);
        if (n > ultimo) { document.title = '🔔 (' + n + ') ' + titulo; try { new (window.AudioContext||window.webkitAudioContext)().resume(); } catch(e) {} } else if (n === 0) { document.title = titulo; }
        ultimo = n;
    });
}
setInterval(refrescar, 10000);
</script>
</body></html>
<?php ob_end_flush(); ?>
