<?php
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) {
    session_destroy();
    header("Location: expirada.php");
    exit();
}
require_once(__DIR__ . "/class/permisos.php");
requerir('rep.plantillas');
$rol = strtoupper($_SESSION['rol']);

$q       = trim($_GET['q']   ?? '');
$catFilt = trim($_GET['cat'] ?? '');

// Categorías distintas usadas hoy — para el filtro
$cats = [];
$rc = $conexion->query("SELECT DISTINCT categoria FROM cat_plantillas_nutricion WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria");
if ($rc) while ($r = $rc->fetch_assoc()) $cats[] = $r['categoria'];

$where = []; $params = []; $types = '';
if ($q !== '') { $where[] = "(nombre_plantilla LIKE CONCAT('%', ?, '%') OR categoria LIKE CONCAT('%', ?, '%'))"; $params[] = $q; $params[] = $q; $types .= 'ss'; }
if ($catFilt !== '') { $where[] = "categoria = ?"; $params[] = $catFilt; $types .= 's'; }
$sql = "SELECT id, nombre_plantilla, categoria, LENGTH(cuerpo_html) AS bytes FROM cat_plantillas_nutricion"
     . (count($where) ? "\n WHERE " . implode(' AND ', $where) : '')
     . "\n ORDER BY nombre_plantilla";
if ($types !== '') { $stmt = $conexion->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute(); $res = $stmt->get_result(); }
else               { $res = $conexion->query($sql); }
$total = (int)$conexion->query("SELECT COUNT(*) c FROM cat_plantillas_nutricion")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="images/favicon.png">
    <link rel="apple-touch-icon" href="images/favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php te('tpladm.pageTitle'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <link href="./main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
    <style>
        .placeholder-chip { cursor:pointer; user-select:none; }
        .placeholder-chip:hover { background:#dee2e6; }
        .plist-actions { white-space:nowrap; }
        .placeholder-panel { max-height: 480px; overflow-y:auto; }
        .modal-tpl .modal-body { min-height:60vh; }
    </style>
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
        <div class="app-header__content">
            <div class="app-header-left"></div>
            <div class="app-header-right">
                <div class="header-btn-lg pr-0">
                    <div class="widget-content p-0"><div class="widget-content-wrapper">
                        <div class="widget-content-left ml-3 header-user-info">
                            <div class="widget-heading"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                            <div class="widget-subheading"><?php echo htmlspecialchars($_SESSION['rol'] ?? ''); ?></div>
                        </div>
                        <div class="widget-content-left ms-3">
                            <a href="salir.php" class="btn btn-sm btn-outline-secondary"><?php te('common.close'); ?></a>
                        </div>
                    </div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-main">
        <div class="app-sidebar sidebar-shadow"><?php include("./menu/menu_adm.php"); ?></div>

        <div class="app-main__outer">
            <div class="app-main__inner">

                <div class="app-page-title mb-3">
                    <div class="page-title-wrapper">
                        <div class="page-title-heading">
                            <div class="page-title-icon">
                                <i class="pe-7s-note2 icon-gradient bg-warm-flame"></i>
                            </div>
                            <div>
                                <?php te('tpladm.title'); ?>
                                <div class="page-title-subheading"><?php te('tpladm.subtitle'); ?></div>
                            </div>
                        </div>
                        <div class="page-title-actions">
                            <button class="btn btn-primary" onclick="nuevaPlantilla()">
                                <i class="bi bi-plus-lg me-1"></i><?php te('tpladm.new'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="get" class="row g-2 mb-3">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control" placeholder="<?php te('tpladm.filterPh'); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <select name="cat" class="form-select">
                                    <option value=""><?php te('tpladm.allCategories'); ?></option>
                                    <?php foreach ($cats as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>" <?php if ($c === $catFilt) echo 'selected'; ?>><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-1">
                                <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i><?php te('tpladm.filter'); ?></button>
                                <a href="plantillas_admin.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                            </div>
                        </form>

                        <div class="text-muted small mb-2"><?php echo $total; ?> <?php te('tpladm.templates'); ?></div>

                        <div class="table-responsive">
                            <table class="table align-middle table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th><?php te('tpladm.name'); ?></th>
                                        <th style="width:220px;"><?php te('tpladm.category'); ?></th>
                                        <th class="text-end" style="width:110px;"><?php te('tpladm.size'); ?></th>
                                        <th class="text-end plist-actions" style="width:220px;"><?php te('tpladm.actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($res && $res->num_rows > 0): ?>
                                    <?php while ($p = $res->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($p['nombre_plantilla']); ?></strong></td>
                                        <td><?php echo $p['categoria'] ? '<span class="badge bg-secondary">'.htmlspecialchars($p['categoria']).'</span>' : '<span class="text-muted">—</span>'; ?></td>
                                        <td class="text-end text-muted small"><?php echo number_format((int)$p['bytes']); ?> B</td>
                                        <td class="text-end plist-actions">
                                            <button class="btn btn-sm btn-outline-primary" onclick="previewPlantilla(<?php echo (int)$p['id']; ?>)" title="<?php te('tpladm.preview'); ?>"><i class="bi bi-eye"></i></button>
                                            <button class="btn btn-sm btn-outline-warning" onclick="editarPlantilla(<?php echo (int)$p['id']; ?>)" title="<?php te('common.edit'); ?>"><i class="bi bi-pencil-square"></i></button>
                                            <button class="btn btn-sm btn-outline-secondary" onclick="duplicarPlantilla(<?php echo (int)$p['id']; ?>)" title="<?php te('tpladm.duplicate'); ?>"><i class="bi bi-files"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarPlantilla(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['nombre_plantilla'])); ?>')" title="<?php te('tpladm.delete'); ?>"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?php te('tpladm.empty'); ?></td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL EDITAR / CREAR ═══════════════════════════════════════════ -->
<div class="modal fade modal-tpl" id="modalTpl" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#5a2d82;">
                <h6 class="modal-title text-white mb-0"><i class="bi bi-file-earmark-text me-2"></i><span id="tplModalTitle"><?php te('tpladm.newTitle'); ?></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tplId" value="0">
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small fw-semibold"><?php te('tpladm.name'); ?> *</label>
                        <input type="text" id="tplNombre" class="form-control" maxlength="180" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold"><?php te('tpladm.category'); ?></label>
                        <input type="text" id="tplCategoria" class="form-control" maxlength="80" list="tplCats" placeholder="e.g. Pediatric">
                        <datalist id="tplCats">
                            <?php foreach ($cats as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-9">
                        <label class="form-label small fw-semibold"><?php te('tpladm.body'); ?> *</label>
                        <textarea id="tplCuerpo"></textarea>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-2 placeholder-panel">
                            <div class="small fw-semibold mb-2"><i class="bi bi-braces"></i> <?php te('tpladm.placeholders'); ?></div>
                            <div class="text-muted small mb-2"><?php te('tpladm.placeholderHelp'); ?></div>
                            <div class="d-flex flex-wrap gap-1" id="chipList">
                                <?php
                                $placeholders = [
                                    'fecha_actual','fecha_evaluacion','fecha_cita','hora_inicio','hora_fin',
                                    'paciente_nombre','paciente_dob','edad_paciente','sexo_paciente',
                                    'paciente_cedula','paciente_email','paciente_telefono',
                                    'doctor_nombre','apellido_doctor','titulo_doctor','especialidad',
                                    'firma_nombre','firma_credenciales','firma_npi','firma_licencia','firma_imagen',
                                    'practica_nombre','direccion_1','direccion_2','ciudad','estado','codigo_postal','telefono_clinica',
                                    'tipo_consulta','diagnostico_referencia',
                                    'peso','talla','imc','antropometria',
                                    'bioquimica','hallazgos_fisicos','historial_cliente','plan_nutricional',
                                    'objetivos','recomendaciones','diagnostico','tratamiento',
                                    'diagnostico_nutricional','intervencion','monitoreo',
                                    'historial_nutricional_seguimiento','datos_seguimiento',
                                    'diagnostico_pes_seguimiento','prescripcion_nutricional','plan_accion_seguimiento',
                                    'monitoreo_indicador','monitoreo_meta','monitoreo_progreso',
                                    'req_energia','ingesta_energia','req_proteina','ingesta_proteina','req_fluidos','ingesta_fluidos'
                                ];
                                foreach ($placeholders as $ph): ?>
                                    <span class="badge bg-light text-dark border placeholder-chip" data-ph="<?php echo $ph; ?>"><?php echo '{{' . $ph . '}}'; ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tplErr" class="alert alert-warning py-2 mt-3 d-none"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="previewDesdeEditor()"><i class="bi bi-eye me-1"></i><?php te('tpladm.preview'); ?></button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.cancel'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="tplGuardar" onclick="guardarPlantilla()">
                    <i class="bi bi-check-lg me-1"></i><?php te('common.saveChanges'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL PREVIEW ═════════════════════════════════════════════════ -->
<div class="modal fade" id="modalPreview" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-eye me-2"></i><?php te('tpladm.preview'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="previewBody">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<script type="text/javascript" src="./assets/scripts/main.js"></script>
<script>
var TPL_T = {
    saved:      <?php echo json_encode(t('tpladm.js.saved')); ?>,
    saveErr:    <?php echo json_encode(t('tpladm.js.saveErr')); ?>,
    confirmDel: <?php echo json_encode(t('tpladm.js.confirmDel')); ?>,
    deleted:    <?php echo json_encode(t('tpladm.js.deleted')); ?>,
    empty:      <?php echo json_encode(t('tpladm.js.emptyFields')); ?>,
    connErr:    <?php echo json_encode(t('common.js.connError')); ?>,
    dupOk:      <?php echo json_encode(t('tpladm.js.dupOk')); ?>
};

var _tplEditorReady = false;
function _initEditor(){
    if (_tplEditorReady) return;
    $('#tplCuerpo').summernote({
        height: 420,
        tabsize: 2,
        toolbar: [
            ['style',  ['style']],
            ['font',   ['bold','italic','underline','clear']],
            ['color',  ['color']],
            ['para',   ['ul','ol','paragraph']],
            ['table',  ['table']],
            ['insert', ['link','picture','hr']],
            ['view',   ['fullscreen','codeview','help']]
        ]
    });
    _tplEditorReady = true;
}

function nuevaPlantilla(){
    document.getElementById('tplId').value = 0;
    document.getElementById('tplNombre').value = '';
    document.getElementById('tplCategoria').value = '';
    document.getElementById('tplErr').classList.add('d-none');
    document.getElementById('tplModalTitle').textContent = <?php echo json_encode(t('tpladm.newTitle')); ?>;
    new bootstrap.Modal(document.getElementById('modalTpl')).show();
    setTimeout(function(){ _initEditor(); $('#tplCuerpo').summernote('code', ''); }, 30);
}

function editarPlantilla(id){
    $.getJSON('get_plantilla_json.php', { id: id })
        .done(function(p){
            if (!p || p.error) { alert('Error: ' + (p && p.error)); return; }
            document.getElementById('tplId').value = p.id;
            document.getElementById('tplNombre').value = p.nombre_plantilla || '';
            document.getElementById('tplCategoria').value = p.categoria || '';
            document.getElementById('tplErr').classList.add('d-none');
            document.getElementById('tplModalTitle').textContent = <?php echo json_encode(t('tpladm.editTitle')); ?>;
            new bootstrap.Modal(document.getElementById('modalTpl')).show();
            setTimeout(function(){ _initEditor(); $('#tplCuerpo').summernote('code', p.cuerpo_html || ''); }, 30);
        })
        .fail(function(){ alert(TPL_T.connErr); });
}

function guardarPlantilla(){
    var id     = document.getElementById('tplId').value;
    var nombre = document.getElementById('tplNombre').value.trim();
    var cat    = document.getElementById('tplCategoria').value.trim();
    var cuerpo = $('#tplCuerpo').summernote('code');
    var err = document.getElementById('tplErr');
    err.classList.add('d-none');
    if (!nombre || cuerpo.replace(/<[^>]+>/g,'').trim().length < 5) {
        err.textContent = TPL_T.empty; err.classList.remove('d-none'); return;
    }
    var btn = document.getElementById('tplGuardar');
    btn.disabled = true;
    $.post('class/Plantilla_guardar.php', { id: id, nombre: nombre, categoria: cat, cuerpo: cuerpo }, function(res){
        btn.disabled = false;
        if (res && res.ok) { alert(TPL_T.saved); location.reload(); }
        else { err.textContent = TPL_T.saveErr + ' ' + (res && res.error || ''); err.classList.remove('d-none'); }
    }, 'json').fail(function(){ btn.disabled = false; err.textContent = TPL_T.connErr; err.classList.remove('d-none'); });
}

function duplicarPlantilla(id){
    $.post('class/Plantilla_duplicar.php', { id: id }, function(res){
        if (res && res.ok) { alert(TPL_T.dupOk); location.reload(); }
        else alert(TPL_T.saveErr + ' ' + (res && res.error || ''));
    }, 'json').fail(function(){ alert(TPL_T.connErr); });
}

function eliminarPlantilla(id, nombre){
    if (!confirm(TPL_T.confirmDel + '\n\n' + nombre)) return;
    $.post('class/Plantilla_eliminar.php', { id: id }, function(res){
        res = (res || '').trim();
        if (res === 'OK') { alert(TPL_T.deleted); location.reload(); }
        else alert(TPL_T.saveErr + ' ' + res);
    }).fail(function(){ alert(TPL_T.connErr); });
}

function previewPlantilla(id){
    document.getElementById('previewBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>';
    new bootstrap.Modal(document.getElementById('modalPreview')).show();
    $.get('get_plantilla_html.php', { id: id })
        .done(function(html){ document.getElementById('previewBody').innerHTML = _rellenarDemo(html); })
        .fail(function(xhr){ document.getElementById('previewBody').innerHTML = '<div class="alert alert-danger m-3">HTTP ' + xhr.status + '</div>'; });
}

function previewDesdeEditor(){
    var html = $('#tplCuerpo').summernote('code');
    document.getElementById('previewBody').innerHTML = _rellenarDemo(html);
    new bootstrap.Modal(document.getElementById('modalPreview')).show();
}

// Rellena los placeholders con datos de ejemplo (para el preview visual)
function _rellenarDemo(html){
    var hoy = new Date().toLocaleDateString('en-US');
    var demo = {
        fecha_actual: hoy, fecha_evaluacion: hoy, fecha_cita: hoy,
        hora_inicio: '10:00', hora_fin: '10:30',
        paciente_nombre: 'Jane Doe', paciente_dob: 'January 1, 2000',
        edad_paciente: '25 years', sexo_paciente: 'Female',
        paciente_cedula: '0999999999', paciente_email: 'jane@example.com', paciente_telefono: '(555) 000-0000',
        doctor_nombre: 'Silvia Ross', apellido_doctor: 'Ross', titulo_doctor: 'Dr.', especialidad: '',
        firma_nombre: 'Silvia Ross', firma_credenciales: 'NPI: 1234567890 · License ID: 000000-1',
        firma_npi: '1234567890', firma_licencia: '000000-1', firma_imagen: '',
        practica_nombre: "L'Chaim", direccion_1: '123 Main St', direccion_2: '', ciudad: 'Miami', estado: 'FL', codigo_postal: '33101',
        telefono_clinica: '(305) 000-0000', tipo_consulta: 'Follow-up', diagnostico_referencia: 'Type 2 Diabetes',
        peso: '70 kg', talla: '170 cm', imc: '24.2',
        antropometria: 'Weight: 70 kg, Height: 170 cm, BMI: 24.2',
        bioquimica: '—', hallazgos_fisicos: '—', historial_cliente: '—', plan_nutricional: '—',
        objetivos: '—', recomendaciones: '—', diagnostico: '—', tratamiento: '—',
        diagnostico_nutricional: '—', intervencion: '—', monitoreo: '—',
        historial_nutricional_seguimiento: '—', datos_seguimiento: '—',
        diagnostico_pes_seguimiento: '—', prescripcion_nutricional: '—', plan_accion_seguimiento: '—',
        monitoreo_indicador: '—', monitoreo_meta: '—', monitoreo_progreso: '—',
        req_energia: '2000 kcal', ingesta_energia: '1800 kcal',
        req_proteina: '56 g', ingesta_proteina: '48 g',
        req_fluidos: '2.5 L', ingesta_fluidos: '2.0 L'
    };
    Object.keys(demo).forEach(function(k){
        var re = new RegExp('\\{\\{' + k + '\\}\\}','g');
        html = html.replace(re, demo[k]);
    });
    return html;
}

// Click en chip → insertar placeholder en cursor del editor
document.getElementById('chipList').addEventListener('click', function(ev){
    var chip = ev.target.closest('.placeholder-chip');
    if (!chip) return;
    if (!_tplEditorReady) return;
    var ph = '{{' + chip.getAttribute('data-ph') + '}}';
    $('#tplCuerpo').summernote('insertText', ph);
});
</script>
</body>
</html>
