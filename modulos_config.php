<?php
/**
 * modulos_config.php — Activa/desactiva módulos de esta instalación (feature
 * flags en app_config, claves 'mod.*'). Solo SISTEMA. Ver class/modulos.php.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/modulos.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
if (strtoupper($_SESSION["rol"]) !== 'SISTEMA') { header("Location: break.php"); exit(); }

$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$cat = modulos_catalogo();
$msg = ''; $msgTipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $antes = []; foreach ($cat as $k => $m) $antes[$k] = modulo_activo($k);
    $marcados = $_POST['mod'] ?? [];
    $cambios = []; $fallo = false;
    foreach ($cat as $k => $m) {
        if (!$m[5]) continue;                                   // reservado
        $nuevo = isset($marcados[$k]);
        if ($nuevo !== $antes[$k]) {
            if (modulo_guardar($conexion, $k, $nuevo)) $cambios[] = $k.($nuevo ? ' ON' : ' OFF');
            else $fallo = true;
        }
    }
    if ($cambios) auditar($conexion, 'Configuración', 'editar', 'CONFIG', null, 'Módulos: '.implode(', ', $cambios));
    if ($fallo)         { $msg = $en ? 'Some changes could not be saved.' : 'No se pudieron guardar algunos cambios.'; $msgTipo = 'danger'; }
    elseif ($cambios)   { $msg = $en ? 'Modules updated.' : 'Módulos actualizados.'; $msgTipo = 'success'; }
    else                { $msg = $en ? 'No changes.' : 'Sin cambios.'; $msgTipo = 'info'; }
}
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Modules':'Módulos'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-plugin icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Modules':'Módulos'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Turn features on or off for this installation.':'Activa o desactiva funciones de esta instalación.'; ?></div>
                </div>
            </div></div></div>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-<?php echo $msgTipo; ?>"><?php echo h($msg); ?></div>
            <?php endif; ?>

            <div class="row"><div class="col-lg-8">
            <form method="POST" class="card shadow-sm"><div class="card-body">
                <ul class="list-group list-group-flush">
                <?php foreach ($cat as $k => $m): $disp = $m[5]; ?>
                    <li class="list-group-item d-flex align-items-start justify-content-between gap-3 px-0">
                        <div>
                            <div class="fw-semibold"><?php echo h($en ? $m[1] : $m[0]); ?>
                                <?php if (!$disp): ?><span class="badge bg-secondary ms-1"><?php echo $en?'Coming soon':'Próximamente'; ?></span><?php endif; ?>
                            </div>
                            <div class="small text-muted"><?php echo h($en ? $m[3] : $m[2]); ?></div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="mod[<?php echo h($k); ?>]" value="1"
                                   <?php echo modulo_activo($k) ? 'checked' : ''; ?> <?php echo $disp ? '' : 'disabled'; ?>>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
                <div class="mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> <?php echo $en?'Save':'Guardar'; ?></button></div>
            </div></form>
            </div>
            <div class="col-lg-4">
                <div class="alert alert-info small mb-0"><i class="bi bi-info-circle"></i>
                    <?php echo $en
                        ? 'A disabled module disappears from the menu and its pages are blocked for everyone, including administrators. Data is kept. User permissions still apply on top of enabled modules.'
                        : 'Un módulo desactivado desaparece del menú y sus páginas se bloquean para todos, incluido el administrador. Los datos se conservan. Los permisos por usuario siguen aplicando sobre los módulos activos.'; ?>
                </div>
            </div></div>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
