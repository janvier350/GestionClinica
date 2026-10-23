<?php
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
$conexion = conectarse();

if (!isset($_SESSION["rol"])) {
    header("Location: break.php");
    exit();
}
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) {
    session_destroy();
    header("Location: expirada.php");
    exit();
}

$q = trim($_GET['q'] ?? '');
$qEsc = $conexion->real_escape_string($q);

$where = "WHERE P.ESTADO = 'A'";
if ($q !== '') {
    $where .= " AND (P.NOMBRES LIKE '%$qEsc%'
                  OR P.APELLIDOS LIKE '%$qEsc%'
                  OR P.CEDULA   LIKE '%$qEsc%'
                  OR P.TELEFONO LIKE '%$qEsc%')";
}

$sql = "SELECT
            P.IDPACIENTE,
            P.NOMBRES,
            P.APELLIDOS,
            P.CEDULA,
            P.TELEFONO,
            P.EMAIL,
            COUNT(DISTINCT C.IDCITA)       AS TOTAL_CITAS,
            SUM(C.ESTADO_CITA = 'A')       AS ATENDIDAS,
            MAX(C.FECHA_CITA)              AS ULTIMA_CITA
        FROM AG_PACIENTE P
        LEFT JOIN AG_CITA C ON C.IDPACIENTE = P.IDPACIENTE AND C.ESTADO = 'A'
        $where
        GROUP BY P.IDPACIENTE
        ORDER BY P.APELLIDOS, P.NOMBRES";

$result = $conexion->query($sql);
$totalRows = $result ? $result->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <!-- Favicon de la app -->
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="images/favicon.png">
    <link rel="apple-touch-icon" href="images/favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php te('plist.title'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="./main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
    <style>
        .avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff; font-weight: 700; font-size: .85rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .badge-atendida { background: #6f42c1; }
        #modalHistorial .table th { font-size: .78rem; text-transform: uppercase; color: #888; }
        #modalHistorial .table td { font-size: .85rem; vertical-align: middle; }
        .stat-pill {
            display: inline-flex; align-items: center; gap: 4px;
            background: #f0f4ff; border-radius: 20px;
            padding: 2px 10px; font-size: .8rem; font-weight: 600;
        }
    </style>
</head>
<body>
<div class="app-container app-theme-white body-tabs-shadow fixed-sidebar fixed-header">

    <!-- HEADER -->
    <div class="app-header header-shadow">
        <div class="app-header__logo">
            <div class="logo-src"></div>
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
        <div class="app-header__menu">
            <button type="button" class="btn-icon btn-icon-only btn btn-primary btn-sm mobile-toggle-header-nav">
                <span class="btn-icon-wrapper"><i class="fa fa-ellipsis-v fa-w-6"></i></span>
            </button>
        </div>
        <div class="app-header__content">
            <div class="app-header-left"></div>
            <div class="app-header-right">
                <div class="header-btn-lg pr-0">
                    <div class="widget-content p-0">
                        <div class="widget-content-wrapper">
                            <div class="widget-content-left ml-3 header-user-info">
                                <div class="widget-heading"><?php echo htmlspecialchars($_SESSION['nombres'] ?? ''); ?></div>
                                <div class="widget-subheading"><?php echo htmlspecialchars($_SESSION['rol'] ?? ''); ?></div>
                            </div>
                            <div class="widget-content-left ms-3">
                                <div class="btn-group">
                                    <a data-toggle="dropdown" class="p-0 btn" href="#">
                                        <i class="fa fa-angle-down ml-2 opacity-8"></i>
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <a href="salir.php" class="dropdown-item"><?php te('menu.logout'); ?></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-main">
        <!-- SIDEBAR -->
        <div class="app-sidebar sidebar-shadow">
            <?php include("./menu/menu_adm.php"); ?>
        </div>

        <div class="app-main__outer">
            <div class="app-main__inner">

                <!-- TÍTULO -->
                <div class="app-page-title mb-3">
                    <div class="page-title-wrapper">
                        <div class="page-title-heading">
                            <div class="page-title-icon">
                                <i class="pe-7s-users icon-gradient bg-plum-plate"></i>
                            </div>
                            <div>
                                <?php te('plist.title'); ?>
                                <div class="page-title-subheading">
                                    <?php te('plist.subtitle'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="page-title-actions">
                            <a href="PNC_PacienteCrear.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-person-plus-fill me-1"></i> <?php te('plist.new'); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- BUSCADOR -->
                <div class="card shadow-sm mb-3">
                    <div class="card-body py-2">
                        <form method="GET" class="row g-2 align-items-center">
                            <div class="col">
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="bi bi-search text-muted"></i>
                                    </span>
                                    <input type="text" name="q" class="form-control border-start-0"
                                           placeholder="<?php te('plist.searchPh'); ?>"
                                           value="<?php echo htmlspecialchars($q); ?>" autofocus>
                                </div>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary"><?php te('common.search'); ?></button>
                                <?php if ($q !== ''): ?>
                                    <a href="listado_pacientes.php" class="btn btn-outline-secondary ms-1"><?php te('plist.clear'); ?></a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- TABLA -->
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <span style="font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#6c757d;font-weight:600;">
                            <i class="bi bi-people-fill me-1"></i>
                            <?php echo $totalRows; ?> <?php echo $totalRows !== 1 ? t('plist.patients') : t('plist.patient'); ?>
                            <?php if ($q !== ''): ?>
                                — <?php echo $totalRows !== 1 ? t('plist.results') : t('plist.result'); ?> <?php te('plist.for'); ?>
                                "<strong><?php echo htmlspecialchars($q); ?></strong>"
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:36px;"></th>
                                        <th><?php te('plist.th.patient'); ?></th>
                                        <th><?php te('pf.id'); ?></th>
                                        <th><?php te('pf.phone'); ?></th>
                                        <th><?php te('pf.email'); ?></th>
                                        <th class="text-center"><?php te('plist.th.appointments'); ?></th>
                                        <th class="text-center"><?php te('plist.th.attended'); ?></th>
                                        <th><?php te('plist.th.lastAppointment'); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($p = $result->fetch_assoc()):
                                        $iniciales = strtoupper(
                                            substr($p['NOMBRES']   ?? '', 0, 1) .
                                            substr($p['APELLIDOS'] ?? '', 0, 1)
                                        );
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="avatar"><?php echo htmlspecialchars($iniciales); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold" style="font-size:.9rem;">
                                                <?php echo htmlspecialchars($p['APELLIDOS'] . ', ' . $p['NOMBRES']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo htmlspecialchars($p['CEDULA'] ?? '—'); ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($p['TELEFONO'] ?? '—'); ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo htmlspecialchars($p['EMAIL'] ?? '—'); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary"><?php echo (int)$p['TOTAL_CITAS']; ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int)$p['ATENDIDAS'] > 0): ?>
                                                <span class="badge badge-atendida"><?php echo (int)$p['ATENDIDAS']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($p['ULTIMA_CITA']): ?>
                                                <small><?php echo date('m/d/Y', strtotime($p['ULTIMA_CITA'])); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted"><?php te('plist.noAppointments'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3" style="white-space:nowrap;">
                                            <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                                    onclick="editarPaciente(<?php echo $p['IDPACIENTE']; ?>)"
                                                    title="<?php te('plist.editTitle'); ?>">
                                                <i class="bi bi-pencil-square"></i> <?php te('common.edit'); ?>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary py-0 px-2"
                                                    onclick="verHistorial(<?php echo $p['IDPACIENTE']; ?>, '<?php echo htmlspecialchars(addslashes($p['APELLIDOS'] . ', ' . $p['NOMBRES'])); ?>')"
                                                    title="<?php te('plist.historyTitle'); ?>">
                                                <i class="bi bi-clock-history"></i> <?php te('plist.historyBtn'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-person-x fs-2 d-block mb-2"></i>
                                            <?php echo $q !== '' ? t('plist.noneFoundPre') . ' "' . htmlspecialchars($q) . '".' : t('plist.noneRegistered'); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div><!-- /app-main__inner -->
        </div><!-- /app-main__outer -->
    </div><!-- /app-main -->
</div><!-- /app-container -->

<!-- ══ MODAL EDITAR PACIENTE ════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditarPaciente" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#5a2d82;">
                <h6 class="modal-title text-white mb-0">
                    <i class="bi bi-pencil-square me-2"></i><?php te('plist.editTitleModal'); ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formEditarPaciente">
                    <input type="hidden" id="epId" name="idPaciente">

                    <!-- Datos personales -->
                    <h6 class="text-muted mb-2"><i class="bi bi-person-vcard"></i> <?php te('pf.patientData'); ?></h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.firstName'); ?> *</label>
                            <input type="text" id="epNombres" name="nombres" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.lastName'); ?> *</label>
                            <input type="text" id="epApellidos" name="apellidos" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.id'); ?></label>
                            <input type="text" id="epCedula" name="cedula" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.phone'); ?></label>
                            <input type="text" id="epTelefono" name="telefono" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.dob'); ?></label>
                            <input type="date" id="epFecNac" name="fecNac" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.email'); ?></label>
                            <input type="email" id="epEmail" name="email" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.sex'); ?></label>
                            <input type="text" id="epSex" name="sex" class="form-control" placeholder="M / F">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.gender'); ?></label>
                            <input type="text" id="epGender" name="gender" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.address'); ?></label>
                            <input type="text" id="epAddress" name="address" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.city'); ?></label>
                            <input type="text" id="epCity" name="city" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.state'); ?></label>
                            <input type="text" id="epState" name="state" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.zip'); ?></label>
                            <input type="text" id="epZip" name="zip" class="form-control" maxlength="15">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold"><i class="bi bi-translate"></i> <?php te('pf.language'); ?></label>
                            <select id="epIdioma" name="idioma" class="form-select">
                                <option value="es"><?php te('lang.spanish'); ?></option>
                                <option value="en"><?php te('lang.english'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold"><i class="bi bi-clipboard2-pulse"></i> ICD-10</label>
                            <select id="epIcd10" name="idicd10" class="form-select">
                                <option value="">—</option>
                            </select>
                            <small class="text-muted">Puedes escribir código o descripción para filtrar.</small>
                        </div>
                    </div>

                    <!-- Notas -->
                    <h6 class="text-muted mb-2"><i class="bi bi-journal-text"></i> <?php te('pf.notes'); ?></h6>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?php te('pf.alert'); ?>
                        </label>
                        <textarea id="epAlerta" name="alerta" class="form-control" rows="2"
                                  placeholder="<?php te('pf.alertPh'); ?>"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?php te('pf.importantNotes'); ?></label>
                        <textarea id="epNotes" name="notes" class="form-control" rows="3"
                                  placeholder="<?php te('pf.importantPh'); ?>"></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small fw-semibold"><?php te('pf.billingNotes'); ?></label>
                        <textarea id="epAddNotes" name="addNotes" class="form-control" rows="2"
                                  placeholder="<?php te('pf.billingPh'); ?>"></textarea>
                    </div>
                    <div id="epAlertaAviso" class="small text-muted d-none mt-1">
                        <i class="bi bi-info-circle"></i> <?php te('plist.alertNotSavedPre'); ?> <strong><?php te('pf.alert'); ?></strong> <?php te('plist.alertNotSavedMid'); ?>
                        <a href="migrar_notas_paciente.php" target="_blank"><?php te('plist.notesMigration'); ?></a>.
                    </div>
                </form>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.cancel'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="epGuardar" onclick="guardarPaciente()">
                    <i class="bi bi-check-lg"></i> <?php te('common.saveChanges'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL HISTORIAL ══════════════════════════════════════════════════ -->
<div class="modal fade" id="modalHistorial" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#1a1a2e;">
                <h6 class="modal-title text-white mb-0">
                    <i class="bi bi-person-lines-fill me-2"></i>
                    <span id="modalPacienteNombre"></span>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="modalHistorialBody">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border spinner-border-sm me-2"></div> <?php te('common.loading'); ?>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL INFORME ════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalInforme" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i><?php te('plist.reportTitle'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="cuerpoInforme">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
            <div class="modal-footer py-2">
                <button id="btnEditarInforme" type="button" class="btn btn-outline-warning btn-sm" onclick="editarInforme()">
                    <i class="bi bi-pencil-square"></i> <?php te('common.edit'); ?>
                </button>
                <button id="btnGuardarInforme" type="button" class="btn btn-success btn-sm d-none" onclick="guardarInformeEditado()">
                    <i class="bi bi-check-lg"></i> <?php te('common.saveChanges'); ?>
                </button>
                <button id="btnCancelarInforme" type="button" class="btn btn-outline-secondary btn-sm d-none" onclick="cancelarEdicionInforme()">
                    <?php te('plist.reportCancel'); ?>
                </button>
                <button type="button" onclick="exportarInformePDF()" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-filetype-pdf"></i> <?php te('plist.exportPdf'); ?>
                </button>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> <?php te('common.print'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/modal_stack.js?v=<?php echo @filemtime(__DIR__.'/js/modal_stack.js'); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script type="text/javascript" src="./assets/scripts/main.js"></script>
<script>
var T = {
    loadError:      <?php echo json_encode(t('plist.js.loadError')); ?>,
    loadHttp:       <?php echo json_encode(t('plist.js.loadHttp')); ?>,
    nameRequired:   <?php echo json_encode(t('plist.js.nameRequired')); ?>,
    updatedNoAlert: <?php echo json_encode(t('plist.js.updatedNoAlert')); ?>,
    incomplete:     <?php echo json_encode(t('plist.js.incomplete')); ?>,
    saveError:      <?php echo json_encode(t('plist.js.saveError')); ?>,
    connError:      <?php echo json_encode(t('common.js.connError')); ?>,
    loading:        <?php echo json_encode(t('common.loading')); ?>,
    historyErrPre:  <?php echo json_encode(t('plist.js.historyErrPre')); ?>,
    historyErrTail: <?php echo json_encode(t('plist.js.historyErrTail')); ?>,
    reportErrPre:   <?php echo json_encode(t('plist.js.reportErrPre')); ?>,
    reportSaved:    <?php echo json_encode(t('plist.js.reportSaved')); ?>,
    reportSaveErr:  <?php echo json_encode(t('plist.js.reportSaveErr')); ?>,
    reportEmpty:    <?php echo json_encode(t('plist.js.reportEmpty')); ?>,
    confirmCancelEdit:<?php echo json_encode(t('plist.js.confirmCancelEdit')); ?>
};

let epModal = null;
let icd10Lista = null; // cache: [{id, codigo, descripcion}, ...]

function _cargarIcd10(cb) {
    if (icd10Lista) { cb(icd10Lista); return; }
    $.getJSON('get_icd10_list.php')
        .done(function(data){ icd10Lista = Array.isArray(data) ? data : []; cb(icd10Lista); })
        .fail(function(){ icd10Lista = []; cb(icd10Lista); });
}

function _poblarSelectIcd10(selectId, valorActual) {
    _cargarIcd10(function(rows){
        var $sel = $('#' + selectId);
        $sel.empty().append('<option value="">—</option>');
        rows.forEach(function(r){
            $sel.append('<option value="' + r.id + '">' + r.codigo + ' — ' + r.descripcion + '</option>');
        });
        if (valorActual) $sel.val(String(valorActual));
        if ($sel.data('select2')) $sel.select2('destroy');
        $sel.select2({
            dropdownParent: $sel.closest('.modal').length ? $sel.closest('.modal') : $(document.body),
            width: '100%',
            placeholder: 'Buscar código o descripción…',
            allowClear: true
        });
    });
}

function editarPaciente(idPaciente) {
    if (!epModal) epModal = new bootstrap.Modal(document.getElementById('modalEditarPaciente'));

    // Limpiar y bloquear mientras carga
    document.getElementById('formEditarPaciente').reset();
    document.getElementById('epId').value = idPaciente;

    $.getJSON('get_paciente.php', { id: idPaciente })
        .done(function(p) {
            if (p.error) { alert(T.loadError + p.error); return; }
            document.getElementById('epNombres').value   = p.NOMBRES   || '';
            document.getElementById('epApellidos').value = p.APELLIDOS || '';
            document.getElementById('epCedula').value    = p.CEDULA    || '';
            document.getElementById('epTelefono').value  = p.TELEFONO  || '';
            document.getElementById('epFecNac').value    = p.FECHANACIMIENTO || '';
            document.getElementById('epEmail').value     = p.EMAIL     || '';
            document.getElementById('epSex').value       = p.SEX       || '';
            document.getElementById('epGender').value    = p.GENDER    || '';
            document.getElementById('epAddress').value   = p.ADDRESS   || '';
            document.getElementById('epCity').value      = p.CITY      || '';
            document.getElementById('epState').value     = p.STATE     || '';
            document.getElementById('epZip').value       = p.ZIP       || '';
            document.getElementById('epIdioma').value    = (p.IDIOMA === 'en') ? 'en' : 'es';
            document.getElementById('epAlerta').value    = p.ALERTA    || '';
            document.getElementById('epNotes').value     = p.NOTES     || '';
            document.getElementById('epAddNotes').value  = p.ADDNOTES  || '';

            // ICD-10: si la columna existe, poblar el select con el catálogo y marcar el valor actual
            _poblarSelectIcd10('epIcd10', p.IDICD10 || '');

            // Si la columna ALERTA aún no existe, avisar que ese campo no se guardará
            const aviso = document.getElementById('epAlertaAviso');
            aviso.classList.toggle('d-none', p._tieneAlerta !== false);

            epModal.show();
        })
        .fail(function(xhr) {
            alert(T.loadHttp + xhr.status + ').');
        });
}
// Disponible para el botón "Editar paciente" del buscador global.
window.editarPacientePorId = editarPaciente;

function guardarPaciente() {
    const nombres   = document.getElementById('epNombres').value.trim();
    const apellidos = document.getElementById('epApellidos').value.trim();
    if (!nombres || !apellidos) {
        alert(T.nameRequired);
        return;
    }

    const btn = document.getElementById('epGuardar');
    btn.disabled = true;

    $.post('editar_paciente.php', $('#formEditarPaciente').serialize(), function(res) {
        res = res.trim();
        if (res === 'OK' || res === 'OK_SIN_ALERTA') {
            if (res === 'OK_SIN_ALERTA') {
                alert(T.updatedNoAlert);
            }
            location.reload();
        } else if (res === 'DATOS_INCOMPLETOS') {
            alert(T.incomplete);
            btn.disabled = false;
        } else {
            alert(T.saveError + res);
            btn.disabled = false;
        }
    }).fail(function() {
        alert(T.connError);
        btn.disabled = false;
    });
}

function verHistorial(idPaciente, nombre) {
    document.getElementById('modalPacienteNombre').textContent = nombre;
    document.getElementById('modalHistorialBody').innerHTML =
        '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> ' + T.loading + '</div>';

    new bootstrap.Modal(document.getElementById('modalHistorial')).show();

    $.get('get_historial_paciente.php', { id: idPaciente })
        .done(function(html) {
            document.getElementById('modalHistorialBody').innerHTML = html;
        })
        .fail(function(xhr) {
            document.getElementById('modalHistorialBody').innerHTML =
                '<div class="alert alert-danger m-3">' + T.historyErrPre + xhr.status + T.historyErrTail + '</div>';
        });
}

let informeIdActual = null;
let informeHtmlOriginal = '';

function _resetBotonesInforme() {
    document.getElementById('btnEditarInforme').classList.remove('d-none');
    document.getElementById('btnGuardarInforme').classList.add('d-none');
    document.getElementById('btnCancelarInforme').classList.add('d-none');
    var body = document.getElementById('cuerpoInforme');
    body.contentEditable = 'false';
    body.style.outline = '';
    body.style.background = '';
}

function exportarInformePDF() {
    if (!informeIdActual) return;
    window.open('informe_pdf.php?id=' + encodeURIComponent(informeIdActual), '_blank');
}

function verInforme(idHistorial) {
    informeIdActual = idHistorial;
    informeHtmlOriginal = '';
    _resetBotonesInforme();
    document.getElementById('cuerpoInforme').innerHTML =
        '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>';
    new bootstrap.Modal(document.getElementById('modalInforme')).show();
    $.get('get_informe_html.php', { id: idHistorial })
        .done(function(html) {
            document.getElementById('cuerpoInforme').innerHTML = html;
            informeHtmlOriginal = html;
        })
        .fail(function(xhr) {
            document.getElementById('cuerpoInforme').innerHTML =
                '<div class="alert alert-danger m-3">' + T.reportErrPre + xhr.status + ').</div>';
        });
}

function editarInforme() {
    var body = document.getElementById('cuerpoInforme');
    if (!body || !informeIdActual) return;
    body.contentEditable = 'true';
    body.style.outline = '2px dashed #ffc107';
    body.style.background = '#fffdf5';
    body.focus();
    document.getElementById('btnEditarInforme').classList.add('d-none');
    document.getElementById('btnGuardarInforme').classList.remove('d-none');
    document.getElementById('btnCancelarInforme').classList.remove('d-none');
}

function cancelarEdicionInforme() {
    if (!confirm(T.confirmCancelEdit)) return;
    document.getElementById('cuerpoInforme').innerHTML = informeHtmlOriginal;
    _resetBotonesInforme();
}

function guardarInformeEditado() {
    var body = document.getElementById('cuerpoInforme');
    if (!body || !informeIdActual) return;
    var contenido = body.innerHTML;
    if (contenido.replace(/<[^>]+>/g,'').trim().length < 5) { alert(T.reportEmpty); return; }
    var btn = document.getElementById('btnGuardarInforme');
    btn.disabled = true;
    $.post('actualizar_atencion.php', { idHistorial: informeIdActual, informe: contenido }, function(res){
        btn.disabled = false;
        res = (res || '').trim();
        if (res === 'OK') {
            informeHtmlOriginal = contenido;
            _resetBotonesInforme();
            alert(T.reportSaved);
        } else {
            alert(T.reportSaveErr + res);
        }
    }).fail(function(){ btn.disabled = false; alert(T.connError); });
}

// Al cerrar el modal, deja todo listo para la próxima apertura
document.addEventListener('DOMContentLoaded', function(){
    var m = document.getElementById('modalInforme');
    if (m) m.addEventListener('hidden.bs.modal', _resetBotonesInforme);
});
</script>
</body>
</html>
