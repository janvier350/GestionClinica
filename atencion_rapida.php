<?php
/**
 * atencion_rapida.php — Recepción: ingreso rápido por cédula + tablero (triaje / espera / atención / por cobrar).
 * El paciente se envía a Triaje o directo a la sala de espera del profesional. Cobro al ingreso o al final.
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
if (!puede('rapida.ingresar') && !puede('rapida.cobrar')) { header("Location: break.php"); exit(); }
turnos_asegurar_tablas($conexion);
$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$pIngresar = puede('rapida.ingresar'); $pCobrar = puede('rapida.cobrar');

function tablero_html($conexion, $en, $pIngresar, $pCobrar) {
    $turnos = turnos_hoy($conexion);
    $col = ['Triaje'=>[], 'Espera'=>[], 'Atencion'=>[], 'Cobrar'=>[], 'Fin'=>[]];
    foreach ($turnos as $t) {
        if ($t['ESTADO'] === 'Cancelado') continue;
        if ($t['ESTADO'] === 'Atendido') { $col[$t['COBRO'] === 'Pendiente' ? 'Cobrar' : 'Fin'][] = $t; }
        else $col[$t['ESTADO']][] = $t;
    }
    $titulos = ['Triaje'=>[$en?'In triage':'En triaje','info'], 'Espera'=>[$en?'Waiting':'En espera','warning'], 'Atencion'=>[$en?'In care':'En atención','primary'], 'Cobrar'=>[$en?'To collect':'Por cobrar','danger']];
    ob_start(); ?>
    <div class="row g-3 align-items-start">
    <?php foreach ($titulos as $k => [$tit, $color]): ?>
      <div class="col-md-3"><div class="card shadow-sm"><div class="card-header py-2 d-flex justify-content-between bg-<?php echo $color; ?> <?php echo in_array($color, ['warning','info']) ? 'text-dark' : 'text-white'; ?>">
        <b><?php echo h($tit); ?></b><span class="badge bg-light text-dark"><?php echo count($col[$k]); ?></span></div>
        <div class="card-body p-2">
        <?php foreach ($col[$k] as $t): ?>
          <div class="border rounded p-2 mb-2 bg-white">
            <div class="d-flex justify-content-between"><b><?php echo h($t['PACIENTE']); ?></b><span class="small text-muted"><?php echo h(date('H:i', strtotime($t['FECHA_INGRESO']))); ?></span></div>
            <div class="small text-muted"><?php echo h($t['CEDULA']); ?></div>
            <div class="small"><?php echo h($t['SERVICIOS'] ?: $t['SERVICIO']); ?></div>
            <?php if ($t['ESPECIALISTA']): ?><div class="small text-muted"><i class="bi bi-person-badge"></i> <?php echo h($t['ESPECIALISTA']); ?></div><?php endif; ?>
            <?php if ($k === 'Atencion' && $t['ATIENDE']): ?><div class="small"><i class="bi bi-person-check"></i> <?php echo h($t['ATIENDE']); ?></div><?php endif; ?>
            <?php if ($t['MOTIVO']): ?><div class="small fst-italic"><?php echo h($t['MOTIVO']); ?></div><?php endif; ?>
            <?php if (!empty($t['OBSERVACION'])): ?><div class="small text-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo h($t['OBSERVACION']); ?></div><?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mt-1">
              <span class="small <?php echo (int)$t['MIN_ESPERA'] >= 30 && in_array($k, ['Triaje','Espera']) ? 'text-danger fw-bold' : 'text-muted'; ?>"><i class="bi bi-clock"></i> <?php echo h(turno_minutos_txt($t['MIN_ESPERA'])); ?></span>
              <span>
                <?php if ($t['COBRO'] === 'Cobrado'): ?><span class="badge bg-success">$<?php echo number_format((float)$t['MONTO'], 2); ?> ✓</span>
                <?php elseif ($pCobrar && $t['ESTADO'] !== 'Cancelado'): ?><button class="btn btn-sm <?php echo $k==='Cobrar' ? 'btn-danger' : 'btn-outline-success'; ?> py-0" onclick="abrirCobro(<?php echo (int)$t['IDTURNO']; ?>, <?php echo json_encode($t['PACIENTE']); ?>, <?php echo json_encode((float)$t['MONTO']); ?>)"><i class="bi bi-cash-coin"></i> <?php echo $en?'Collect':'Cobrar'; ?> $<?php echo number_format((float)$t['MONTO'], 2); ?></button><?php endif; ?>
                <?php if ($pIngresar && in_array($k, ['Triaje','Espera'])): ?><button class="btn btn-sm btn-outline-secondary py-0" title="<?php echo $en?'Cancel':'Cancelar'; ?>" onclick="cancelarTurno(<?php echo (int)$t['IDTURNO']; ?>)"><i class="bi bi-x"></i></button><?php endif; ?>
              </span>
            </div>
          </div>
        <?php endforeach; if (!$col[$k]): ?><div class="text-center text-muted small py-3">—</div><?php endif; ?>
        </div></div></div>
    <?php endforeach; ?>
    </div>
    <?php if ($col['Fin']): ?><details class="mt-3"><summary class="small fw-semibold" style="cursor:pointer"><?php echo $en?'Finished today':'Finalizados hoy'; ?> (<?php echo count($col['Fin']); ?>)</summary>
      <div class="table-responsive"><table class="table table-sm small mb-0"><tbody>
      <?php foreach (array_reverse($col['Fin']) as $t): ?><tr><td><?php echo h(date('H:i', strtotime($t['FECHA_INGRESO']))); ?></td><td><?php echo h($t['PACIENTE']); ?></td><td><?php echo h($t['SERVICIOS'] ?: $t['SERVICIO']); ?></td><td class="text-end">$<?php echo number_format((float)$t['MONTO'], 2); ?> · <?php echo h($t['METODO']); ?></td></tr><?php endforeach; ?>
      </tbody></table></div></details><?php endif; ?>
    <?php return ob_get_clean();
}

if (isset($_GET['tablero'])) { header('Content-Type: text/html; charset=utf-8'); echo tablero_html($conexion, $en, $pIngresar, $pCobrar); exit; }

// Catálogos del formulario
$servicios = []; $q = $conexion->query("SELECT IDTIPOCONSULTA id, NOMBRES nombre, COALESCE(PRECIO,0) precio, COALESCE(ROL_ATIENDE,'') rol FROM AG_TIPOCONSULTA WHERE ESTADO='A' ORDER BY NOMBRES");
if ($q) while ($r = $q->fetch_assoc()) $servicios[] = $r;
$especialistas = []; $q = $conexion->query("SELECT U.IDADM_USUARIO id, CONCAT(U.NOMBRES,' ',U.APELLIDOS) nombre, R.CARGO rol FROM ADM_USUARIO U INNER JOIN ADM_ROL R ON R.IDADM_ROL=U.IDADM_ROL WHERE U.ESTADO='A' AND R.CARGO IN ('DOCTOR','ENFERMERA') ORDER BY R.CARGO, U.NOMBRES");
if ($q) while ($r = $q->fetch_assoc()) $especialistas[] = $r;
$aseguradoras = []; $q = @$conexion->query("SELECT Empresa_seguro n FROM seguros WHERE estado = 1 ORDER BY Empresa_seguro");
if ($q) while ($r = $q->fetch_assoc()) $aseguradoras[] = $r['n'];
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Quick check-in':'Atención rápida'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-timer icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Quick check-in':'Atención rápida'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Check in, wait, care, report, payment.':'Ingreso, espera, atención, reporte y cobro.'; ?></div>
                </div></div></div></div>

            <?php echo guia_rapida_html($en, $_SESSION['rol'] ?? '', 1); ?>

            <?php if ($pIngresar): ?>
            <div class="card shadow-sm mb-3"><div class="card-body">
                <div id="ingMsg"></div>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label small mb-0"><?php echo $en?'ID number *':'Número de documento (cédula) *'; ?></label>
                        <div class="input-group"><input id="ingCedula" class="form-control" autocomplete="off" inputmode="numeric" maxlength="20" autofocus>
                            <button class="btn btn-primary" type="button" id="btnConsultar"><?php echo $en?'Look up':'Consultar'; ?></button></div>
                        <div id="cedulaInfo" class="small mt-1"></div></div>
                    <div class="col-md-8" id="bloquePaciente">
                        <label class="form-label small mb-0"><?php echo $en?'Patient *':'Paciente *'; ?></label>
                        <div id="pacEncontrado" class="alert alert-success py-2 mb-0 d-none"></div>
                        <div class="row g-2" id="pacNuevo">
                            <div class="col-md-5"><input id="ingNombres" class="form-control" placeholder="<?php echo $en?'First name(s)':'Nombres'; ?>"></div>
                            <div class="col-md-4"><input id="ingApellidos" class="form-control" placeholder="<?php echo $en?'Last name(s)':'Apellidos'; ?>"></div>
                            <div class="col-md-3"><input id="ingTelefono" class="form-control" placeholder="<?php echo $en?'Phone':'Teléfono'; ?>" inputmode="tel"></div>
                        </div></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-5"><label class="form-label small mb-1"><?php echo $en?'Service(s) *':'Servicio(s) *'; ?> <span class="text-muted">— <?php echo $en?'you can pick several; the cost adds up':'puede elegir varios; el costo se suma solo'; ?></span></label>
                        <div class="d-flex flex-wrap gap-2" id="servicios">
                        <?php foreach ($servicios as $s): ?>
                            <input type="checkbox" class="btn-check svc" id="svc<?php echo (int)$s['id']; ?>" value="<?php echo (int)$s['id']; ?>" data-precio="<?php echo h($s['precio']); ?>">
                            <label class="btn btn-outline-primary btn-sm" for="svc<?php echo (int)$s['id']; ?>"><?php echo h($s['nombre']); ?> <span class="badge bg-light text-dark">$<?php echo number_format((float)$s['precio'], 2); ?></span></label>
                        <?php endforeach; if (!$servicios): ?><span class="text-danger small"><?php echo $en?'No active services: create them in Visit types.':'No hay servicios activos: créalos en Tipos de consulta.'; ?></span><?php endif; ?>
                        </div></div>
                    <div class="col-md-4"><label class="form-label small mb-1"><?php echo $en?'Specialist':'Especialista'; ?></label>
                        <select id="ingEspecialista" class="form-select"><option value="0"><?php echo $en?'Any available (queue by service)':'Cualquiera disponible (cola por servicio)'; ?></option>
                        <?php foreach ($especialistas as $e): ?><option value="<?php echo (int)$e['id']; ?>"><?php echo h($e['nombre']); ?><?php echo $e['rol']==='ENFERMERA' ? ' ('.($en?'nurse':'enfermera').')' : ''; ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label small mb-1"><?php echo $en?'Cost *':'Costo *'; ?></label>
                        <div class="input-group"><span class="input-group-text">$</span><input id="ingMonto" class="form-control" inputmode="decimal" value="0.00"></div></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-4"><input id="ingMotivo" class="form-control form-control-sm" maxlength="255" placeholder="<?php echo $en?'Reason (optional)':'Motivo (opcional)'; ?>"></div>
                    <div class="col-md-3"><input id="ingDerivador" class="form-control form-control-sm" maxlength="120" placeholder="<?php echo $en?'Referring doctor (optional)':'Médico derivador (opcional)'; ?>"></div>
                    <div class="col-md-3"><select id="ingAseguradora" class="form-select form-select-sm"><option value="">Particular</option><?php foreach ($aseguradoras as $a): ?><option><?php echo h($a); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2"><input id="ingEmpresa" class="form-control form-control-sm" maxlength="120" placeholder="<?php echo $en?'Company':'Empresa'; ?>"></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-10"><textarea id="ingObs" class="form-control form-control-sm" rows="2" maxlength="600" placeholder="<?php echo $en?'Notes for triage / doctors (optional): allergies, medication, recommendations…':'Observaciones para triaje y doctores (opcional): alergias, medicación, recomendaciones…'; ?>"></textarea></div>
                    <div class="col-md-2 d-flex align-items-center"><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" id="ingAlerta"><label class="form-check-label" for="ingAlerta" title="<?php echo $en?'Saves it in the patient file so it always shows':'Se guarda en la ficha del paciente para que siempre se vea'; ?>"><?php echo $en?'Save as permanent patient alert':'Guardar como alerta permanente del paciente'; ?></label></div></div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
                    <?php if ($pCobrar): ?><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="ingCobrar"><label class="form-check-label" for="ingCobrar"><?php echo $en?'Collect now':'Cobrar ahora'; ?></label></div>
                    <select id="ingMetodo" class="form-select form-select-sm" style="max-width:150px"><option>Efectivo</option><option>Tarjeta</option><option>Transferencia</option><option>Otro</option></select><?php endif; ?>
                    <button type="button" class="btn btn-info text-dark" onclick="guardarIngreso('triaje')"><i class="bi bi-heart-pulse"></i> <?php echo $en?'Save and send to Triage':'Guardar y enviar a Triaje'; ?></button>
                    <button type="button" class="btn btn-primary" onclick="guardarIngreso('atencion')"><i class="bi bi-skip-forward-fill"></i> <?php echo $en?'Save and send to Care':'Guardar y pasar a Atención'; ?></button>
                    <button type="button" class="btn btn-outline-secondary" onclick="limpiarForm()"><?php echo $en?'Clear':'Limpiar'; ?></button>
                </div>
            </div></div>
            <?php endif; ?>

            <div id="tablero"><?php echo tablero_html($conexion, $en, $pIngresar, $pCobrar); ?></div>
            <div class="small text-muted mt-2"><i class="bi bi-arrow-repeat"></i> <?php echo $en?'Updates every 15 seconds.':'Se actualiza cada 15 segundos.'; ?></div>

        </div></div>
    </div>
</div>

<div class="modal fade" id="modalCobro" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cash-coin"></i> <?php echo $en?'Collect':'Cobrar'; ?> — <span id="cobPaciente"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" id="cobTurno">
        <div class="mb-2"><label class="form-label small mb-0"><?php echo $en?'Amount':'Monto'; ?></label><div class="input-group"><span class="input-group-text">$</span><input id="cobMonto" class="form-control" inputmode="decimal"></div></div>
        <div class="mb-2"><label class="form-label small mb-0"><?php echo $en?'Payment method':'Forma de pago'; ?></label><select id="cobMetodo" class="form-select"><option>Efectivo</option><option>Tarjeta</option><option>Transferencia</option><option>Otro</option></select></div>
        <div class="mb-2"><label class="form-label small mb-0"><?php echo $en?'Reference (optional)':'Referencia (opcional)'; ?></label><input id="cobRef" class="form-control" maxlength="100"></div>
        <div id="cobMsg" class="small text-danger"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo $en?'Cancel':'Cancelar'; ?></button><button class="btn btn-success" onclick="confirmarCobro()"><i class="bi bi-check2"></i> <?php echo $en?'Confirm payment':'Confirmar cobro'; ?></button></div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var EN = <?php echo json_encode($en); ?>, idPacienteSel = 0, montoManual = false;
var ERR = {SERVICIO_REQUERIDO: EN?'Pick at least one service.':'Elige al menos un servicio.', NOMBRE_REQUERIDO: EN?'Enter first and last name.':'Escribe nombres y apellidos.',
    YA_EN_ESPERA: EN?'This patient is already in the waiting room today.':'Este paciente ya está en la sala de espera hoy.', DUPLICADO: EN?'A patient with that phone already exists: look them up by ID.':'Ya existe un paciente con ese teléfono: búscalo por su cédula.',
    SIN_PERMISO: EN?'No permission.':'Sin permiso.', ESPECIALISTA_INVALIDO: EN?'Invalid specialist.':'Especialista no válido.', MONTO_INVALIDO: EN?'Invalid amount.':'Monto no válido.', YA_COBRADO: EN?'Already collected.':'Ya estaba cobrado.'};
function $(id){ return document.getElementById(id); }
function msg(t, tipo){ var m = $('ingMsg'); if (m) m.innerHTML = t ? '<div class="alert alert-'+tipo+' py-2">'+t+'</div>' : ''; }
function sumar(){ var s = 0; document.querySelectorAll('.svc:checked').forEach(function(c){ s += parseFloat(c.dataset.precio || 0); }); if (!montoManual) $('ingMonto').value = s.toFixed(2); }
document.querySelectorAll('.svc').forEach(function(c){ c.addEventListener('change', function(){ montoManual = false; sumar(); }); });
if ($('ingMonto')) $('ingMonto').addEventListener('input', function(){ montoManual = true; });
function consultar(){
    var ced = $('ingCedula').value.trim(); if (!ced) return;
    fetch('turno_accion.php?accion=buscar&cedula='+encodeURIComponent(ced), {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        var info = $('cedulaInfo'); info.className = 'small mt-1'; info.textContent = '';
        if (!j.ok) { info.className += ' text-danger'; info.textContent = ERR[j.error] || j.error; return; }
        if (j.paciente) {
            idPacienteSel = j.paciente.id; $('pacEncontrado').textContent = (j.paciente.nombres+' '+j.paciente.apellidos).trim() + (j.paciente.telefono ? ' · '+j.paciente.telefono : '');
            $('pacEncontrado').classList.remove('d-none'); $('pacNuevo').classList.add('d-none');
            if (j.en_espera) { info.className += ' text-danger'; info.textContent = ERR.YA_EN_ESPERA; }
        } else {
            idPacienteSel = 0; $('pacEncontrado').classList.add('d-none'); $('pacNuevo').classList.remove('d-none');
            info.className += ' text-muted'; info.textContent = EN ? 'New patient: enter the name.' : 'Paciente nuevo: escribe el nombre.';
            if (j.diez_digitos && !j.cedula_valida) { info.className = 'small mt-1 text-warning'; info.textContent = (EN ? 'Warning: the ID number does not look valid (check the digits). ' : 'Atención: la cédula no parece válida (revisa los dígitos). ') + info.textContent; }
            $('ingNombres').focus();
        }
    });
}
if ($('btnConsultar')) { $('btnConsultar').onclick = consultar; $('ingCedula').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); consultar(); } }); $('ingCedula').addEventListener('input', function(){ idPacienteSel = 0; $('pacEncontrado').classList.add('d-none'); $('pacNuevo').classList.remove('d-none'); }); }
function limpiarForm(){ ['ingCedula','ingNombres','ingApellidos','ingTelefono','ingMotivo','ingDerivador','ingEmpresa','ingObs'].forEach(function(i){ $(i).value=''; }); $('ingAlerta').checked=false; $('ingAseguradora').value=''; $('ingEspecialista').value='0'; document.querySelectorAll('.svc').forEach(function(c){ c.checked=false; }); montoManual=false; sumar(); idPacienteSel=0; $('pacEncontrado').classList.add('d-none'); $('pacNuevo').classList.remove('d-none'); $('cedulaInfo').textContent=''; msg('',''); if ($('ingCobrar')) $('ingCobrar').checked=false; $('ingCedula').focus(); }
function guardarIngreso(destino){
    var fd = new FormData(); fd.append('accion','crear'); fd.append('destino', destino);
    fd.append('cedula', $('ingCedula').value); fd.append('idPaciente', idPacienteSel); fd.append('nombres', $('ingNombres').value); fd.append('apellidos', $('ingApellidos').value); fd.append('telefono', $('ingTelefono').value);
    document.querySelectorAll('.svc:checked').forEach(function(c){ fd.append('servicios[]', c.value); });
    fd.append('idEspecialista', $('ingEspecialista').value); fd.append('monto', $('ingMonto').value); fd.append('motivo', $('ingMotivo').value);
    fd.append('observacion', $('ingObs').value); if ($('ingAlerta').checked) fd.append('alerta_permanente', '1');
    fd.append('derivador', $('ingDerivador').value); fd.append('aseguradora', $('ingAseguradora').value); fd.append('empresa', $('ingEmpresa').value);
    if ($('ingCobrar') && $('ingCobrar').checked) { fd.append('cobrar_ahora', '1'); fd.append('metodo', $('ingMetodo').value); }
    fetch('turno_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        if (j.ok) { var t = (destino === 'triaje' ? (EN?'Sent to triage.':'Enviado a triaje.') : (EN?'Sent to the waiting room.':'Enviado a la sala de espera.')); if (j.aviso === 'CEDULA_INVALIDA') t += (EN?' (ID number looks invalid)':' (la cédula parece inválida)'); if (j.aviso_cobro) t += ' — '+(ERR[j.aviso_cobro]||j.aviso_cobro);
            limpiarForm(); msg(t, 'success'); refrescar(true); }
        else msg(ERR[j.error] || ((EN?'Could not save: ':'No se pudo guardar: ') + j.error), 'danger');
    }).catch(function(){ msg(EN?'Connection error.':'Error de conexión.', 'danger'); });
}
function cancelarTurno(id){ if (!confirm(EN?'Cancel this check-in?':'¿Cancelar este ingreso?')) return; var fd = new FormData(); fd.append('accion','cancelar'); fd.append('idTurno', id); fetch('turno_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(){ refrescar(true); }); }
function abrirCobro(id, nombre, monto){ $('cobTurno').value = id; $('cobPaciente').textContent = nombre; $('cobMonto').value = Number(monto).toFixed(2); $('cobRef').value=''; $('cobMsg').textContent=''; new bootstrap.Modal($('modalCobro')).show(); }
function confirmarCobro(){
    var fd = new FormData(); fd.append('accion','cobrar'); fd.append('idTurno', $('cobTurno').value); fd.append('monto', $('cobMonto').value); fd.append('metodo', $('cobMetodo').value); fd.append('referencia', $('cobRef').value);
    fetch('turno_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        if (j.ok) { bootstrap.Modal.getInstance($('modalCobro')).hide(); refrescar(true); } else $('cobMsg').textContent = ERR[j.error] || j.error;
    });
}
function refrescar(forzar){
    if (!forzar && document.querySelector('.modal.show')) return;
    fetch('atencion_rapida.php?tablero=1', {credentials:'same-origin'}).then(function(r){ return r.text(); }).then(function(h){ $('tablero').innerHTML = h; });
}
setInterval(function(){ refrescar(false); }, 15000);
sumar();
</script>
</body></html>
<?php ob_end_flush(); ?>
