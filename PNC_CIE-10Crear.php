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
requerir('cfg.icd10');

// Filtro de búsqueda
$q         = trim($_GET['q'] ?? '');
$catFiltro = trim($_GET['cat'] ?? '');

// Categorías disponibles (para el filtro y el select del formulario)
$categorias = [];
$rc = $conexion->query("SELECT ID_ENFERMEDAD, CODIGO, NOMBRE, DESCRIPCION FROM ENFERMEDADES_DIAGNOSTICO ORDER BY CODIGO");
if ($rc) while ($cat = $rc->fetch_assoc()) $categorias[] = $cat;

// Lista de códigos ICD-10 con filtro
$where = [];
$params = [];
$types  = '';
if ($q !== '') {
    // Calificar con E. porque ambas tablas (E y C) tienen CODIGO/DESCRIPCION:
    // sin el alias, MySQL devuelve "columna ambigua" y la página daba error 500.
    $where[] = "(E.CODIGO LIKE CONCAT('%', ?, '%') OR E.DESCRIPCION LIKE CONCAT('%', ?, '%'))";
    $params[] = $q; $params[] = $q; $types .= 'ss';
}
if ($catFiltro !== '' && ctype_digit($catFiltro)) {
    $where[] = "E.ID_ENFERMEDAD = ?";
    $params[] = (int)$catFiltro; $types .= 'i';
}
$sqlList = "SELECT E.ID_ENFE_DIAG_COD, E.ID_ENFERMEDAD, E.CODIGO, E.DESCRIPCION,
                   C.CODIGO AS CAT_CODIGO, C.NOMBRE AS CAT_NOMBRE, C.DESCRIPCION AS CAT_DESC
              FROM ENFE_DIAG_COD E
              LEFT JOIN ENFERMEDADES_DIAGNOSTICO C ON C.ID_ENFERMEDAD = E.ID_ENFERMEDAD"
         . (count($where) ? "\n             WHERE " . implode(' AND ', $where) : '')
         . "\n         ORDER BY E.ID_ENFE_DIAG_COD DESC";
if ($types !== '') {
    $stmt = $conexion->prepare($sqlList);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $resList = $stmt->get_result();
    } else {
        $resList = false;   // evita un fatal si la consulta falla
    }
} else {
    $resList = $conexion->query($sqlList);
}
$totalCount = (int)$conexion->query("SELECT COUNT(*) c FROM ENFE_DIAG_COD")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="images/favicon.png">
    <link rel="apple-touch-icon" href="images/favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php te('icd.pageTitle'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="./main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
    <style>
        .badge-cat  { background:#eef1f6; color:#33475b; font-weight:600; }
        .icd-tabla th, .icd-tabla td { font-size:.88rem; }
        .icd-tabla .col-actions { white-space:nowrap; }
        .icd-desc { color:#4b5563; }
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
                    <div class="widget-content p-0">
                        <div class="widget-content-wrapper">
                            <div class="widget-content-left ml-3 header-user-info">
                                <div class="widget-heading"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                                <div class="widget-subheading"><?php echo htmlspecialchars($_SESSION['rol'] ?? ''); ?></div>
                            </div>
                            <div class="widget-content-left ms-3">
                                <a href="salir.php" class="btn btn-sm btn-outline-secondary"><?php te('common.close'); ?></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-main">
        <div class="app-sidebar sidebar-shadow">
            <?php include("./menu/menu_adm.php"); ?>
        </div>

        <div class="app-main__outer">
            <div class="app-main__inner">

                <div class="app-page-title mb-3">
                    <div class="page-title-wrapper">
                        <div class="page-title-heading">
                            <div class="page-title-icon">
                                <i class="pe-7s-note2 icon-gradient bg-warm-flame"></i>
                            </div>
                            <div>
                                <?php te('icd.title'); ?>
                                <div class="page-title-subheading"><?php te('icd.subtitle'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                    $ok      = isset($_GET['ok']);
                    $deleted = isset($_GET['deleted']);
                    $errCode = $_GET['err'] ?? '';
                    if ($ok) echo '<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>' . htmlspecialchars(t('icd.js.saved')) . '</div>';
                    if ($deleted) echo '<div class="alert alert-info py-2"><i class="bi bi-trash me-1"></i>' . htmlspecialchars(t('icd.js.deleted')) . '</div>';
                    if ($errCode === 'dup') {
                        $codDup = htmlspecialchars($_GET['codigo'] ?? '');
                        echo '<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>' . htmlspecialchars(t('icd.js.dup')) . ' <strong>' . $codDup . '</strong></div>';
                    } elseif ($errCode === 'empty') {
                        echo '<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>' . htmlspecialchars(t('icd.js.empty')) . '</div>';
                    } elseif ($errCode === 'inuse') {
                        $cnt = (int)($_GET['count'] ?? 0);
                        echo '<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>' . htmlspecialchars(t('icd.js.inuse')) . ' (' . $cnt . ')</div>';
                    } elseif ($errCode !== '') {
                        echo '<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i>' . htmlspecialchars($errCode) . '</div>';
                    }
                ?>

                <ul class="nav nav-pills mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-icd-crear" type="button" role="tab">
                            <i class="bi bi-plus-circle me-1"></i><?php te('icd.tab.register'); ?>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-icd-lista" type="button" role="tab">
                            <i class="bi bi-list-ul me-1"></i><?php te('icd.tab.list'); ?> <span class="badge bg-secondary ms-1"><?php echo $totalCount; ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- REGISTER -->
                    <div class="tab-pane fade show active" id="tab-icd-crear" role="tabpanel">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3"><?php te('icd.formTitle'); ?></h5>
                                <form id="formIcd" method="post" action="class/Insert_CieCode.php" novalidate>
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold"><?php te('icd.code'); ?> *</label>
                                            <input type="text" class="form-control" id="cieCode" name="cieCode" placeholder="E11.9" maxlength="20" required>
                                            <div class="form-text"><?php te('icd.codeHelp'); ?></div>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label small fw-semibold"><?php te('icd.description'); ?> *</label>
                                            <input type="text" class="form-control" id="description" name="description" placeholder="Type 2 diabetes mellitus" maxlength="255" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-semibold"><?php te('icd.category'); ?></label>
                                            <select name="category" id="category" class="form-select">
                                                <?php foreach ($categorias as $cat): ?>
                                                    <option value="<?php echo (int)$cat['ID_ENFERMEDAD']; ?>">
                                                        <?php echo htmlspecialchars(($cat['CODIGO'] ? '(' . $cat['CODIGO'] . ') ' : '') . ($cat['DESCRIPCION'] ?: $cat['NOMBRE'])); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div id="icdInlineErr" class="alert alert-warning py-2 mt-3 d-none"></div>
                                    <button type="submit" class="btn btn-primary mt-3">
                                        <i class="bi bi-check-lg me-1"></i><?php te('icd.save'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- LIST -->
                    <div class="tab-pane fade" id="tab-icd-lista" role="tabpanel">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <form method="get" class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                                            <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="<?php te('icd.filterPh'); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="cat" class="form-select">
                                            <option value=""><?php te('icd.allCategories'); ?></option>
                                            <?php foreach ($categorias as $cat): ?>
                                                <option value="<?php echo (int)$cat['ID_ENFERMEDAD']; ?>" <?php if ($catFiltro !== '' && (int)$catFiltro === (int)$cat['ID_ENFERMEDAD']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars(($cat['CODIGO'] ? '(' . $cat['CODIGO'] . ') ' : '') . ($cat['DESCRIPCION'] ?: $cat['NOMBRE'])); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-flex gap-1">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bi bi-funnel me-1"></i><?php te('icd.filter'); ?>
                                        </button>
                                        <a href="PNC_CIE-10Crear.php" class="btn btn-outline-secondary" title="<?php te('icd.reset'); ?>">
                                            <i class="bi bi-x-lg"></i>
                                        </a>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-striped align-middle icd-tabla mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:110px;"><?php te('icd.code'); ?></th>
                                                <th><?php te('icd.description'); ?></th>
                                                <th style="width:220px;"><?php te('icd.category'); ?></th>
                                                <th class="col-actions text-end" style="width:120px;"><?php te('icd.actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if ($resList && $resList->num_rows > 0): ?>
                                            <?php while ($r = $resList->fetch_assoc()):
                                                $rowJson = htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($r['CODIGO']); ?></span></td>
                                                <td class="icd-desc"><?php echo htmlspecialchars($r['DESCRIPCION']); ?></td>
                                                <td>
                                                    <?php if ($r['CAT_CODIGO'] || $r['CAT_DESC'] || $r['CAT_NOMBRE']): ?>
                                                        <span class="badge badge-cat">
                                                            <?php echo htmlspecialchars(($r['CAT_CODIGO'] ? '(' . $r['CAT_CODIGO'] . ') ' : '') . ($r['CAT_DESC'] ?: $r['CAT_NOMBRE'])); ?>
                                                        </span>
                                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                </td>
                                                <td class="col-actions text-end">
                                                    <button class="btn btn-sm btn-outline-warning" title="<?php te('common.edit'); ?>"
                                                            onclick='abrirEditar(<?php echo $rowJson; ?>)'>
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" title="<?php te('icd.delete'); ?>"
                                                            onclick="confirmarEliminarCie(<?php echo (int)$r['ID_ENFE_DIAG_COD']; ?>, '<?php echo htmlspecialchars(addslashes($r['CODIGO'])); ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-3 d-block mb-2"></i><?php te('icd.empty'); ?>
                                            </td></tr>
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
    </div>
</div>

<!-- ══ MODAL EDITAR ICD-10 ═════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditarIcd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#5a2d82;">
                <h6 class="modal-title text-white mb-0"><i class="bi bi-pencil-square me-2"></i><?php te('icd.editTitle'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formEditarIcd">
                    <input type="hidden" id="eId" name="id">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?php te('icd.code'); ?> *</label>
                        <input type="text" id="eCieCode" name="cieCode" class="form-control" maxlength="20" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?php te('icd.description'); ?> *</label>
                        <input type="text" id="eDescription" name="description" class="form-control" maxlength="255" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?php te('icd.category'); ?></label>
                        <select id="eCategory" name="category" class="form-select">
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo (int)$cat['ID_ENFERMEDAD']; ?>">
                                    <?php echo htmlspecialchars(($cat['CODIGO'] ? '(' . $cat['CODIGO'] . ') ' : '') . ($cat['DESCRIPCION'] ?: $cat['NOMBRE'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="eErr" class="alert alert-warning py-2 mt-2 d-none"></div>
                </form>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.cancel'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="eGuardar" onclick="guardarEdicionIcd()">
                    <i class="bi bi-check-lg me-1"></i><?php te('common.saveChanges'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript" src="./assets/scripts/main.js"></script>
<script>
var ICD_T = {
    dup:         <?php echo json_encode(t('icd.js.dup')); ?>,
    empty:       <?php echo json_encode(t('icd.js.empty')); ?>,
    confirmDel:  <?php echo json_encode(t('icd.js.confirmDel')); ?>,
    saveErr:     <?php echo json_encode(t('icd.js.saveErr')); ?>,
    connErr:     <?php echo json_encode(t('common.js.connError')); ?>
};

// Si la URL trae ?err=dup abrimos la pestaña Register y mostramos el aviso inline
(function(){
    var params = new URLSearchParams(window.location.search);
    if (params.get('err') === 'dup' || params.get('err') === 'empty') {
        var tab = document.querySelector('[data-bs-target="#tab-icd-crear"]');
        if (tab) new bootstrap.Tab(tab).show();
    }
    if (params.get('q') || params.get('cat')) {
        var tab = document.querySelector('[data-bs-target="#tab-icd-lista"]');
        if (tab) new bootstrap.Tab(tab).show();
    }
})();

function abrirEditar(row){
    document.getElementById('eId').value          = row.ID_ENFE_DIAG_COD;
    document.getElementById('eCieCode').value     = row.CODIGO;
    document.getElementById('eDescription').value = row.DESCRIPCION;
    document.getElementById('eCategory').value    = row.ID_ENFERMEDAD || '';
    document.getElementById('eErr').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('modalEditarIcd')).show();
}

function guardarEdicionIcd(){
    var btn = document.getElementById('eGuardar');
    btn.disabled = true;
    var err = document.getElementById('eErr');
    err.classList.add('d-none');

    $.post('class/Update_CieCode.php', $('#formEditarIcd').serialize(), function(res){
        btn.disabled = false;
        res = (res || '').trim();
        if (res === 'OK') { location.reload(); return; }
        if (res === 'DUP') { err.textContent = ICD_T.dup; err.classList.remove('d-none'); return; }
        if (res === 'DATOS_INCOMPLETOS') { err.textContent = ICD_T.empty; err.classList.remove('d-none'); return; }
        err.textContent = ICD_T.saveErr + ' ' + res; err.classList.remove('d-none');
    }).fail(function(){ btn.disabled = false; err.textContent = ICD_T.connErr; err.classList.remove('d-none'); });
}

function confirmarEliminarCie(id, codigo){
    if (!confirm(ICD_T.confirmDel + ' ' + codigo + '?')) return;
    window.location.href = 'class/Delete_CieCode.php?id=' + id;
}

// Validación cliente-side simple (no vacío, longitud) — el servidor valida duplicados
document.getElementById('formIcd').addEventListener('submit', function(ev){
    var c = document.getElementById('cieCode').value.trim();
    var d = document.getElementById('description').value.trim();
    var err = document.getElementById('icdInlineErr');
    if (!c || !d) {
        ev.preventDefault();
        err.textContent = ICD_T.empty;
        err.classList.remove('d-none');
    }
});
</script>
</body>
</html>
