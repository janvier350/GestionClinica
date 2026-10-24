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
require_once(__DIR__ . "/class/permisos.php");
requerir('cfg.tiposconsulta');

// Columna "Lo atiende" (rol que atiende el servicio: DOCTOR / ENFERMERA / cualquiera). Self-healing.
$rcol = @$conexion->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='AG_TIPOCONSULTA' AND COLUMN_NAME='ROL_ATIENDE'");
if ($rcol && (int)$rcol->fetch_assoc()['c'] === 0) { @$conexion->query("ALTER TABLE AG_TIPOCONSULTA ADD COLUMN ROL_ATIENDE VARCHAR(20) NULL"); }
// Columna PRECIO (valor del servicio para la atención rápida y el cobro)
$rpre = @$conexion->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='AG_TIPOCONSULTA' AND COLUMN_NAME='PRECIO'");
if ($rpre && (int)$rpre->fetch_assoc()['c'] === 0) { @$conexion->query("ALTER TABLE AG_TIPOCONSULTA ADD COLUMN PRECIO DECIMAL(10,2) NULL"); }
$colorDefault = '#3788d8';
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id          = (int)($_POST['id'] ?? 0);
        $nombres     = trim($_POST['nombres'] ?? '');
        $abreviatura = trim($_POST['abreviatura'] ?? '');
        $color       = trim($_POST['color'] ?? $colorDefault);
        $rolAtiende  = in_array($_POST['rol_atiende'] ?? '', ['DOCTOR','ENFERMERA'], true) ? $_POST['rol_atiende'] : '';
        $precioTxt   = trim(str_replace(',', '.', (string)($_POST['precio'] ?? '')));
        $precio      = ($precioTxt !== '' && is_numeric($precioTxt) && (float)$precioTxt >= 0) ? number_format((float)$precioTxt, 2, '.', '') : '';

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = $colorDefault;
        }

        if ($nombres === '') {
            $mensaje = ['err', t('tc.msg.nameRequired')];
        } elseif ($id > 0) {
            $stmt = $conexion->prepare(
                "UPDATE AG_TIPOCONSULTA SET NOMBRES = ?, ABREVIATURA = ?, COLOR = ?, ROL_ATIENDE = NULLIF(?, ''), PRECIO = NULLIF(?, '') WHERE IDTIPOCONSULTA = ?"
            );
            $stmt->bind_param("sssssi", $nombres, $abreviatura, $color, $rolAtiende, $precio, $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = ['ok', t('tc.msg.updated')];
        } else {
            $stmt = $conexion->prepare(
                "INSERT INTO AG_TIPOCONSULTA (NOMBRES, ABREVIATURA, COLOR, ROL_ATIENDE, PRECIO, ESTADO) VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), 'A')"
            );
            $stmt->bind_param("sssss", $nombres, $abreviatura, $color, $rolAtiende, $precio);
            $stmt->execute();
            $stmt->close();
            $mensaje = ['ok', t('tc.msg.created')];
        }
    } elseif ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $conexion->query(
            "UPDATE AG_TIPOCONSULTA SET ESTADO = IF(ESTADO = 'A', 'I', 'A') WHERE IDTIPOCONSULTA = $id"
        );
        $mensaje = ['ok', t('common.statusUpdated')];
    }
}

$tipos = [];
$res = $conexion->query("SELECT IDTIPOCONSULTA, NOMBRES, ABREVIATURA, COLOR, ROL_ATIENDE, PRECIO, ESTADO FROM AG_TIPOCONSULTA ORDER BY NOMBRES");
while ($r = $res->fetch_assoc()) { $tipos[] = $r; }
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
    <title><?php te('menu.consultTypes'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="./main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
    <style>
        .swatch { width:18px; height:18px; border-radius:5px; display:inline-block; vertical-align:middle; border:1px solid rgba(0,0,0,.15); }
        input[type=color] { width:46px; height:38px; padding:2px; }
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
                                <i class="pe-7s-palette icon-gradient bg-plum-plate"></i>
                            </div>
                            <div>
                                <?php te('menu.consultTypes'); ?>
                                <div class="page-title-subheading">
                                    <?php te('tc.subtitle'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="page-title-actions">
                            <button type="button" class="btn btn-primary btn-sm" onclick="nuevoTipo()">
                                <i class="bi bi-plus-circle me-1"></i> <?php te('tc.newBtn'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $mensaje[0] === 'ok' ? 'success' : 'danger'; ?> py-2">
                    <?php echo htmlspecialchars($mensaje[1]); ?>
                </div>
                <?php endif; ?>

                <!-- TABLA -->
                <div class="card shadow-sm">
                    <div class="card-header py-2">
                        <span style="font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#6c757d;font-weight:600;">
                            <i class="bi bi-tags-fill me-1"></i> <?php echo count($tipos); ?> <?php te('tc.countSuffix'); ?>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th><?php te('common.color'); ?></th>
                                        <th><?php te('common.name'); ?></th>
                                        <th><?php te('common.abbreviation'); ?></th>
                                        <th><?php echo current_lang()==='en'?'Handled by':'Lo atiende'; ?></th>
                                        <th class="text-end"><?php echo current_lang()==='en'?'Price':'Valor'; ?></th>
                                        <th class="text-center"><?php te('common.status'); ?></th>
                                        <th class="text-end"><?php te('common.actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($tipos)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4"><?php te('tc.none'); ?></td></tr>
                                <?php else: foreach ($tipos as $t): ?>
                                    <tr>
                                        <td><span class="swatch" style="background:<?php echo htmlspecialchars($t['COLOR'] ?: $colorDefault); ?>"></span></td>
                                        <td><?php echo htmlspecialchars($t['NOMBRES']); ?></td>
                                        <td><?php echo htmlspecialchars($t['ABREVIATURA'] ?? '—'); ?></td>
                                        <td><?php $ra = $t['ROL_ATIENDE'] ?? ''; echo $ra === 'ENFERMERA' ? (current_lang()==='en'?'Nurse':'Enfermera') : ($ra === 'DOCTOR' ? (current_lang()==='en'?'Doctor':'Doctor') : '<span class="text-muted">'.(current_lang()==='en'?'Any':'Cualquiera').'</span>'); ?></td>
                                        <td class="text-end"><?php echo $t['PRECIO'] !== null && $t['PRECIO'] !== '' ? '$'.number_format((float)$t['PRECIO'], 2) : '<span class="text-muted">—</span>'; ?></td>
                                        <td class="text-center">
                                            <?php if ($t['ESTADO'] === 'A'): ?>
                                                <span class="badge bg-success"><?php te('common.active'); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php te('common.inactive'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-warning btn-sm"
                                                onclick='editarTipo(<?php echo json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm(<?php echo json_encode(t('tc.confirmToggle')); ?>);">
                                                <input type="hidden" name="accion" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$t['IDTIPOCONSULTA']; ?>">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                    <i class="bi bi-<?php echo $t['ESTADO'] === 'A' ? 'slash-circle' : 'check-circle'; ?>"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- MODAL CREAR/EDITAR -->
<div class="modal fade" id="modalTipo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTipoTitulo"><?php te('tc.modalNew'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php te('common.close'); ?>"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id" id="ftId" value="0">
                    <div class="mb-3">
                        <label class="form-label"><?php te('common.name'); ?></label>
                        <input type="text" class="form-control" name="nombres" id="ftNombres" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php te('common.abbreviation'); ?></label>
                        <input type="text" class="form-control" name="abreviatura" id="ftAbreviatura" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo current_lang()==='en'?'Price (USD)':'Valor del servicio (USD)'; ?></label>
                        <div class="input-group"><span class="input-group-text">$</span><input type="text" inputmode="decimal" class="form-control" name="precio" id="ftPrecio" placeholder="0.00"></div>
                        <div class="form-text"><?php echo current_lang()==='en'?'Used by Quick check-in to add up the cost.':'Lo usa Atención rápida para sumar el costo al ingresar al paciente.'; ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo current_lang()==='en'?'Handled by':'Lo atiende'; ?></label>
                        <select class="form-select" name="rol_atiende" id="ftRol">
                            <option value=""><?php echo current_lang()==='en'?'Any professional':'Cualquier profesional'; ?></option>
                            <option value="DOCTOR"><?php echo current_lang()==='en'?'Doctor':'Doctor'; ?></option>
                            <option value="ENFERMERA"><?php echo current_lang()==='en'?'Nurse (e.g. IV therapy)':'Enfermera (p. ej. sueroterapia)'; ?></option>
                        </select>
                        <div class="form-text"><?php echo current_lang()==='en'?'When scheduling this service, only professionals with that role are offered.':'Al agendar este servicio solo se ofrecen los profesionales con ese rol.'; ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block"><?php te('common.color'); ?></label>
                        <input type="color" name="color" id="ftColor" value="<?php echo htmlspecialchars($colorDefault); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php te('common.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php te('common.save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript" src="./assets/scripts/main.js"></script>
<script>
const modalTipo = new bootstrap.Modal(document.getElementById('modalTipo'));

function nuevoTipo() {
    document.getElementById('modalTipoTitulo').textContent = <?php echo json_encode(t('tc.modalNew')); ?>;
    document.getElementById('ftId').value = '0';
    document.getElementById('ftNombres').value = '';
    document.getElementById('ftAbreviatura').value = '';
    document.getElementById('ftRol').value = '';
    document.getElementById('ftPrecio').value = '';
    document.getElementById('ftColor').value = '<?php echo htmlspecialchars($colorDefault); ?>';
    modalTipo.show();
}

function editarTipo(t) {
    document.getElementById('modalTipoTitulo').textContent = <?php echo json_encode(t('tc.modalEdit')); ?>;
    document.getElementById('ftId').value = t.IDTIPOCONSULTA;
    document.getElementById('ftNombres').value = t.NOMBRES;
    document.getElementById('ftAbreviatura').value = t.ABREVIATURA || '';
    document.getElementById('ftRol').value = t.ROL_ATIENDE || '';
    document.getElementById('ftPrecio').value = (t.PRECIO !== null && t.PRECIO !== undefined && t.PRECIO !== '') ? Number(t.PRECIO).toFixed(2) : '';
    document.getElementById('ftColor').value = t.COLOR || '<?php echo htmlspecialchars($colorDefault); ?>';
    modalTipo.show();
}
</script>
</body>
</html>
