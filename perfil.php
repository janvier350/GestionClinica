<?php
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
$conexion = conectarse();

if (!isset($_SESSION["rol"], $_SESSION["iduser"])) {
    header("Location: break.php");
    exit();
}
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) {
    session_destroy();
    header("Location: expirada.php");
    exit();
}

$idUsuario = (int)$_SESSION['iduser'];

// ¿Existen las columnas opcionales?
$dbName = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
$colExiste = function($col) use ($conexion, $dbName) {
    return (int)$conexion->query(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='ADM_USUARIO' AND COLUMN_NAME='$col'"
    )->fetch_assoc()['c'] > 0;
};
$tieneCorreo  = $colExiste('CORREO');
$tieneNpi     = $colExiste('NPI');
$tieneLicense = $colExiste('LICENSE_ID');
$tieneFirma   = $colExiste('FIRMA_IMG');

$cols = "A.NOMBRES, A.APELLIDOS, A.TELEFONO, A.USUARIO, B.CARGO"
      . ($tieneCorreo  ? ", A.CORREO"     : "")
      . ($tieneNpi     ? ", A.NPI"        : "")
      . ($tieneLicense ? ", A.LICENSE_ID" : "")
      . ($tieneFirma   ? ", A.FIRMA_IMG"  : "");
$stmt = $conexion->prepare(
    "SELECT $cols FROM ADM_USUARIO A
     INNER JOIN ADM_ROL B ON A.IDADM_ROL = B.IDADM_ROL
     WHERE A.IDADM_USUARIO = ? LIMIT 1"
);
$stmt->bind_param("i", $idUsuario);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) { $u = []; }
$nombreCompleto = trim(($u['NOMBRES'] ?? '') . ' ' . ($u['APELLIDOS'] ?? ''));
$iniciales = strtoupper(substr($u['NOMBRES'] ?? '', 0, 1) . substr($u['APELLIDOS'] ?? '', 0, 1));
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
    <title><?php te('profile.title'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="./main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
    <style>
        .avatar-lg {
            width: 72px; height: 72px; border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff; font-weight: 700; font-size: 1.6rem;
            display: flex; align-items: center; justify-content: center;
        }
        .info-label { font-size:.72rem; text-transform:uppercase; color:#888; font-weight:600; }
    </style>
</head>
<body>
<div class="app-container app-theme-white body-tabs-shadow fixed-sidebar fixed-header">

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
                                <div class="btn-group">
                                    <a data-toggle="dropdown" class="p-0 btn" href="#">
                                        <i class="fa fa-angle-down ml-2 opacity-8"></i>
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <a href="salir.php" class="dropdown-item">Cerrar Sesión</a>
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
        <div class="app-sidebar sidebar-shadow">
            <?php include("./menu/menu_adm.php"); ?>
        </div>

        <div class="app-main__outer">
            <div class="app-main__inner">

                <div class="app-page-title mb-3">
                    <div class="page-title-wrapper">
                        <div class="page-title-heading">
                            <div class="page-title-icon">
                                <i class="pe-7s-user icon-gradient bg-plum-plate"></i>
                            </div>
                            <div>
                                <?php te('profile.title'); ?>
                                <div class="page-title-subheading"><?php te('profile.subtitle'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Datos del usuario (editables, excepto username) -->
                    <div class="col-md-5 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="avatar-lg"><?php echo htmlspecialchars($iniciales); ?></div>
                                    <div>
                                        <div class="fw-bold" id="perfilNombreLbl" style="font-size:1.1rem;"><?php echo htmlspecialchars($nombreCompleto); ?></div>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($u['CARGO'] ?? ($_SESSION['rol'] ?? '')); ?></span>
                                    </div>
                                </div>
                                <hr>
                                <form id="formPerfil" onsubmit="return false;">
                                    <div class="mb-2">
                                        <div class="info-label"><?php te('profile.username'); ?></div>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($u['USUARIO'] ?? ($_SESSION['username'] ?? '')); ?>" disabled>
                                        <small class="text-muted"><?php te('profile.usernameLocked'); ?></small>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-6 mb-2">
                                            <div class="info-label"><?php te('pf.firstName'); ?></div>
                                            <input type="text" id="perfNombres" class="form-control" value="<?php echo htmlspecialchars($u['NOMBRES'] ?? ''); ?>">
                                        </div>
                                        <div class="col-6 mb-2">
                                            <div class="info-label"><?php te('pf.lastName'); ?></div>
                                            <input type="text" id="perfApellidos" class="form-control" value="<?php echo htmlspecialchars($u['APELLIDOS'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <div class="info-label"><?php te('profile.phone'); ?></div>
                                        <input type="text" id="perfTelefono" class="form-control" value="<?php echo htmlspecialchars($u['TELEFONO'] ?? ''); ?>">
                                    </div>
                                    <div class="mb-2">
                                        <div class="info-label"><?php te('profile.email'); ?></div>
                                        <input type="email" id="perfCorreo" class="form-control" value="<?php echo htmlspecialchars($tieneCorreo ? ($u['CORREO'] ?? '') : ''); ?>"<?php echo $tieneCorreo ? '' : ' disabled placeholder="—"'; ?>>
                                    </div>
                                    <?php if ($tieneNpi || $tieneLicense): ?>
                                    <hr>
                                    <div class="fw-semibold small mb-2" style="color:#5b6b8c;text-transform:uppercase;letter-spacing:.04em;">
                                        <i class="bi bi-patch-check me-1"></i><?php te('ucreate.credentials'); ?>
                                    </div>
                                    <?php if ($tieneNpi): ?>
                                    <div class="mb-2">
                                        <div class="info-label"><?php te('ucreate.npi'); ?></div>
                                        <input type="text" id="perfNpi" class="form-control" maxlength="20" value="<?php echo htmlspecialchars($u['NPI'] ?? ''); ?>" placeholder="<?php te('ucreate.npiPh'); ?>">
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($tieneLicense): ?>
                                    <div class="mb-2">
                                        <div class="info-label"><?php te('ucreate.license'); ?></div>
                                        <input type="text" id="perfLicense" class="form-control" maxlength="60" value="<?php echo htmlspecialchars($u['LICENSE_ID'] ?? ''); ?>" placeholder="<?php te('ucreate.licensePh'); ?>">
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-primary mt-2" id="btnGuardarPerfil" onclick="guardarPerfil()">
                                        <i class="bi bi-check-lg"></i> <?php te('common.saveChanges'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Cambiar contraseña + Firma para informes -->
                    <div class="col-md-7 mb-3">
                        <div class="card shadow-sm mb-3">
                            <div class="card-header py-2">
                                <i class="bi bi-shield-lock me-1"></i> <?php te('profile.changePassword'); ?>
                            </div>
                            <div class="card-body">
                                <form id="formClave" onsubmit="return false;">
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold"><?php te('profile.currentPassword'); ?></label>
                                        <input type="password" id="claveActual" name="claveActual" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold"><?php te('profile.newPassword'); ?></label>
                                        <input type="password" id="claveNueva" name="claveNueva" class="form-control" minlength="6" required>
                                        <small class="text-muted"><?php te('profile.minChars'); ?></small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold"><?php te('profile.confirmNewPassword'); ?></label>
                                        <input type="password" id="claveConfirmar" class="form-control" minlength="6" required>
                                    </div>
                                    <button type="button" class="btn btn-primary" id="btnGuardarClave" onclick="guardarClave()">
                                        <i class="bi bi-check-lg"></i> <?php te('profile.saveNewPassword'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Firma para informes -->
                        <div class="card shadow-sm">
                            <div class="card-header py-2 d-flex align-items-center justify-content-between">
                                <span><i class="bi bi-pen me-1"></i> <?php te('profile.signature'); ?></span>
                                <?php if ($tieneFirma && !empty($u['FIRMA_IMG'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarFirma()">
                                        <i class="bi bi-trash"></i> <?php te('profile.signatureDelete'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3"><?php te('profile.signatureHelp'); ?></p>

                                <div class="mb-3">
                                    <div class="info-label"><?php te('profile.signatureCurrent'); ?></div>
                                    <div id="firmaPreviewWrap" style="border:1px dashed #ccc;border-radius:6px;padding:8px;background:#fafafa;min-height:110px;display:flex;align-items:center;justify-content:center;">
                                        <?php if ($tieneFirma && !empty($u['FIRMA_IMG'])): ?>
                                            <img id="firmaPreview" src="<?php echo htmlspecialchars($u['FIRMA_IMG']); ?>" alt="firma" style="max-height:120px;max-width:100%;">
                                        <?php else: ?>
                                            <span id="firmaVacia" class="text-muted small"><?php te('profile.signatureNone'); ?></span>
                                            <img id="firmaPreview" alt="firma" style="display:none;max-height:120px;max-width:100%;">
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <ul class="nav nav-pills mb-2" id="firmaTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="tab-firma-up-btn" data-bs-toggle="pill" data-bs-target="#tab-firma-up" type="button" role="tab">
                                            <i class="bi bi-upload"></i> <?php te('profile.signatureTabUp'); ?>
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="tab-firma-dr-btn" data-bs-toggle="pill" data-bs-target="#tab-firma-dr" type="button" role="tab">
                                            <i class="bi bi-brush"></i> <?php te('profile.signatureTabDraw'); ?>
                                        </button>
                                    </li>
                                </ul>
                                <div class="tab-content border rounded p-3">
                                    <div class="tab-pane fade show active" id="tab-firma-up" role="tabpanel">
                                        <label class="form-label small"><?php te('profile.signatureUpload'); ?></label>
                                        <input type="file" id="firmaFile" class="form-control" accept="image/png,image/jpeg">
                                    </div>
                                    <div class="tab-pane fade" id="tab-firma-dr" role="tabpanel">
                                        <canvas id="firmaCanvas" style="border:1px dashed #aaa;border-radius:6px;width:100%;height:150px;touch-action:none;background:#fff;"></canvas>
                                        <div class="d-flex justify-content-between mt-1">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="limpiarCanvasFirma()">
                                                <i class="bi bi-eraser"></i> <?php te('profile.signatureClear'); ?>
                                            </button>
                                            <small class="text-muted"><?php te('profile.signatureDraw'); ?></small>
                                        </div>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-primary mt-3" id="btnGuardarFirma" onclick="guardarFirma()">
                                    <i class="bi bi-check-lg"></i> <?php te('profile.signatureSave'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (strtoupper($_SESSION['rol'] ?? '') === 'DOCTOR'):
                    $misAus = [];
                    if ((int)($conexion->query("SHOW TABLES LIKE 'ausencias_doctor'")->num_rows ?? 0) > 0) {
                        if ($qa = $conexion->prepare("SELECT tipo, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo FROM ausencias_doctor WHERE estado=1 AND IDDOCTOR=? ORDER BY fecha_inicio DESC")) {
                            $qa->bind_param('i', $idUsuario); $qa->execute();
                            $rqa = $qa->get_result(); while ($x = $rqa->fetch_assoc()) $misAus[] = $x; $qa->close();
                        }
                    }
                ?>
                <div class="row"><div class="col-12">
                  <div class="card shadow-sm mb-3">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                      <span><i class="bi bi-umbrella me-1"></i><?php te('aus.title'); ?></span>
                      <a href="ausencias_doctor.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square me-1"></i><?php te('aus.addTitle'); ?></a>
                    </div>
                    <div class="card-body">
                      <?php if (!$misAus): ?>
                        <div class="text-muted small">—</div>
                      <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                          <thead><tr><th><?php te('aus.type'); ?></th><th><?php te('aus.dates'); ?></th><th><?php te('aus.reason'); ?></th></tr></thead>
                          <tbody>
                          <?php foreach ($misAus as $a):
                              $esBlq = ($a['tipo'] === 'bloqueo');
                              $fechas = $esBlq
                                  ? htmlspecialchars($a['fecha_inicio']).' · '.substr($a['hora_inicio'],0,5).'–'.substr($a['hora_fin'],0,5)
                                  : htmlspecialchars($a['fecha_inicio']).($a['fecha_fin'] !== $a['fecha_inicio'] ? ' → '.htmlspecialchars($a['fecha_fin']) : '');
                          ?>
                            <tr>
                              <td><?php if ($esBlq): ?><span class="badge bg-warning text-dark"><?php te('aus.block'); ?></span><?php else: ?><span class="badge bg-info text-dark"><?php te('aus.vacation'); ?></span><?php endif; ?></td>
                              <td class="small"><?php echo $fechas; ?></td>
                              <td class="small text-muted"><?php echo htmlspecialchars($a['motivo'] ?? ''); ?></td>
                            </tr>
                          <?php endforeach; ?>
                          </tbody>
                        </table></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div></div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript" src="./assets/scripts/main.js"></script>
<script>
var T = {
    fillAll:        <?php echo json_encode(t('profile.js.fillAll')); ?>,
    min6:           <?php echo json_encode(t('profile.js.min6')); ?>,
    mismatch:       <?php echo json_encode(t('profile.js.mismatch')); ?>,
    updated:        <?php echo json_encode(t('profile.js.updated')); ?>,
    currentWrong:   <?php echo json_encode(t('profile.js.currentWrong')); ?>,
    sessionExpired: <?php echo json_encode(t('profile.js.sessionExpired')); ?>,
    updateError:    <?php echo json_encode(t('profile.js.updateError')); ?>,
    connError:      <?php echo json_encode(t('common.js.connError')); ?>,
    profileSaved:   <?php echo json_encode(t('profile.js.profileSaved')); ?>,
    profileNameReq: <?php echo json_encode(t('profile.js.nameRequired')); ?>,
    sigSaved:       <?php echo json_encode(t('profile.js.sigSaved')); ?>,
    sigDeleted:     <?php echo json_encode(t('profile.js.sigDeleted')); ?>,
    sigConfirmDel:  <?php echo json_encode(t('profile.js.sigConfirmDel')); ?>,
    sigInvalid:     <?php echo json_encode(t('profile.js.sigInvalid')); ?>,
    sigTooLarge:    <?php echo json_encode(t('profile.js.sigTooLarge')); ?>,
    sigEmpty:       <?php echo json_encode(t('profile.js.sigEmpty')); ?>,
    sigMigration:   <?php echo json_encode(t('profile.js.sigMigration')); ?>,
    sigNone:        <?php echo json_encode(t('profile.signatureNone')); ?>
};

function guardarPerfil() {
    const nombres    = document.getElementById('perfNombres').value.trim();
    const apellidos  = document.getElementById('perfApellidos').value.trim();
    const telefono   = document.getElementById('perfTelefono').value.trim();
    const correoEl   = document.getElementById('perfCorreo');
    const correo     = correoEl && !correoEl.disabled ? correoEl.value.trim() : '';
    const npiEl      = document.getElementById('perfNpi');
    const licenseEl  = document.getElementById('perfLicense');
    const npi        = npiEl ? npiEl.value.trim() : '';
    const license    = licenseEl ? licenseEl.value.trim() : '';

    if (!nombres || !apellidos) { alert(T.profileNameReq); return; }
    const btn = document.getElementById('btnGuardarPerfil');
    btn.disabled = true;
    $.post('guardar_perfil.php', {
        nombres: nombres, apellidos: apellidos, telefono: telefono,
        correo: correo, npi: npi, license_id: license
    }, function(res) {
        res = (res || '').trim();
        if (res === 'OK') {
            alert(T.profileSaved);
            document.getElementById('perfilNombreLbl').textContent = (nombres + ' ' + apellidos).trim();
        } else if (res === 'SIN_SESION') {
            alert(T.sessionExpired); window.location.href = 'index.php';
        } else {
            alert(T.updateError + res);
        }
        btn.disabled = false;
    }).fail(function() { alert(T.connError); btn.disabled = false; });
}

function guardarClave() {
    const actual     = document.getElementById('claveActual').value;
    const nueva       = document.getElementById('claveNueva').value;
    const confirmar  = document.getElementById('claveConfirmar').value;

    if (!actual || !nueva || !confirmar) {
        alert(T.fillAll);
        return;
    }
    if (nueva.length < 6) {
        alert(T.min6);
        return;
    }
    if (nueva !== confirmar) {
        alert(T.mismatch);
        return;
    }

    const btn = document.getElementById('btnGuardarClave');
    btn.disabled = true;

    $.post('cambiar_clave_perfil.php', { claveActual: actual, claveNueva: nueva }, function(res) {
        res = res.trim();
        if (res === 'OK') {
            alert(T.updated);
            document.getElementById('formClave').reset();
        } else if (res === 'CLAVE_ACTUAL_INCORRECTA') {
            alert(T.currentWrong);
        } else if (res === 'CLAVE_CORTA') {
            alert(T.min6);
        } else if (res === 'SIN_SESION') {
            alert(T.sessionExpired);
            window.location.href = 'index.php';
        } else {
            alert(T.updateError + res);
        }
        btn.disabled = false;
    }).fail(function() {
        alert(T.connError);
        btn.disabled = false;
    });
}

// ─── Firma para informes ─────────────────────────────────────────────
(function(){
    var canvas = document.getElementById('firmaCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var dibujando = false, hayFirma = false;
    function ajustar(){
        var ratio = window.devicePixelRatio || 1;
        canvas.width  = canvas.offsetWidth  * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#222';
    }
    function pos(e){
        var r = canvas.getBoundingClientRect();
        var p = e.touches ? e.touches[0] : e;
        return { x: p.clientX - r.left, y: p.clientY - r.top };
    }
    function start(e){ dibujando = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); }
    function move(e){ if(!dibujando) return; var p = pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); hayFirma = true; e.preventDefault(); }
    function end(){ dibujando = false; }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    document.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start);
    canvas.addEventListener('touchmove', move);
    canvas.addEventListener('touchend', end);

    var pillDraw = document.getElementById('tab-firma-dr-btn');
    if (pillDraw) pillDraw.addEventListener('shown.bs.tab', ajustar);

    window.limpiarCanvasFirma = function(){ ctx.clearRect(0,0,canvas.width,canvas.height); hayFirma = false; };
    window._firmaHay       = function(){ return hayFirma; };
    window._firmaDataURL   = function(){ return canvas.toDataURL('image/png'); };
    window._firmaTabActiva = function(){
        var el = document.getElementById('tab-firma-dr-btn');
        return el && el.classList.contains('active');
    };
})();

function _actualizarPreviewFirma(dataURL){
    var img = document.getElementById('firmaPreview');
    var vacio = document.getElementById('firmaVacia');
    if (dataURL) {
        if (img)   { img.src = dataURL; img.style.display = 'inline-block'; }
        if (vacio) { vacio.style.display = 'none'; }
    } else {
        if (img)   { img.removeAttribute('src'); img.style.display = 'none'; }
        if (vacio) { vacio.style.display = 'inline'; vacio.textContent = T.sigNone; }
    }
}

function _postFirma(dataURL) {
    var btn = document.getElementById('btnGuardarFirma');
    btn.disabled = true;
    $.post('guardar_firma.php', { accion: 'guardar', firma: dataURL }, function(res){
        res = (res || '').trim();
        if (res === 'OK') {
            _actualizarPreviewFirma(dataURL);
            alert(T.sigSaved);
            if (!document.querySelector('button[onclick="eliminarFirma()"]')) {
                window.location.reload();
            }
        } else if (res === 'SIN_SESION')         { alert(T.sessionExpired); window.location.href='index.php'; }
          else if (res === 'FALTA_MIGRACION')    { alert(T.sigMigration); }
          else if (res === 'FORMATO_INVALIDO')   { alert(T.sigInvalid); }
          else if (res === 'ARCHIVO_MUY_GRANDE') { alert(T.sigTooLarge); }
          else                                   { alert(T.updateError + res); }
        btn.disabled = false;
    }).fail(function(){ alert(T.connError); btn.disabled = false; });
}

function guardarFirma() {
    if (window._firmaTabActiva && window._firmaTabActiva()) {
        if (!window._firmaHay || !window._firmaHay()) { alert(T.sigEmpty); return; }
        _postFirma(window._firmaDataURL());
        return;
    }
    var f = document.getElementById('firmaFile');
    if (!f || !f.files || !f.files[0]) { alert(T.sigInvalid); return; }
    var file = f.files[0];
    if (!/^image\/(png|jpe?g)$/i.test(file.type)) { alert(T.sigInvalid); return; }
    if (file.size > 1024 * 1024) { alert(T.sigTooLarge); return; }
    var reader = new FileReader();
    reader.onload = function(e){ _postFirma(e.target.result); };
    reader.readAsDataURL(file);
}

function eliminarFirma() {
    if (!confirm(T.sigConfirmDel)) return;
    $.post('guardar_firma.php', { accion: 'eliminar' }, function(res){
        res = (res || '').trim();
        if (res === 'OK') {
            _actualizarPreviewFirma(null);
            alert(T.sigDeleted);
            window.location.reload();
        } else if (res === 'SIN_SESION') { alert(T.sessionExpired); window.location.href='index.php'; }
        else                              { alert(T.updateError + res); }
    }).fail(function(){ alert(T.connError); });
}
</script>
</body>
</html>
