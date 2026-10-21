<?php
/**
 * zona_horaria.php — Configura la zona horaria de la aplicación (por base de
 * datos). Solo SISTEMA. Guarda en app_config (clave 'timezone'); la lee
 * class/conexionBD.php. Útil para demos o clínicas en otras zonas.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
if (strtoupper($_SESSION["rol"]) !== 'SISTEMA') { header("Location: break.php"); exit(); }

$en = (current_lang() === 'en');

// Tabla de configuración (self-healing)
@$conexion->query("CREATE TABLE IF NOT EXISTS app_config (
    clave VARCHAR(60) NOT NULL PRIMARY KEY,
    valor VARCHAR(190) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Zonas horarias ofrecidas (prioriza las de la región)
$zonas = [
    'America/Guayaquil'               => $en ? 'Ecuador (Guayaquil/Quito)'       : 'Ecuador (Guayaquil/Quito)',
    'America/New_York'                => $en ? 'USA — Eastern (New York)'        : 'EE. UU. — Este (Nueva York)',
    'America/Chicago'                 => $en ? 'USA — Central (Chicago)'         : 'EE. UU. — Centro (Chicago)',
    'America/Denver'                  => $en ? 'USA — Mountain (Denver)'         : 'EE. UU. — Montaña (Denver)',
    'America/Phoenix'                 => $en ? 'USA — Arizona (no DST)'          : 'EE. UU. — Arizona (sin horario de verano)',
    'America/Los_Angeles'             => $en ? 'USA — Pacific (Los Angeles)'     : 'EE. UU. — Oeste (Los Ángeles)',
    'America/Bogota'                  => 'Colombia (Bogotá)',
    'America/Lima'                    => $en ? 'Peru (Lima)'                      : 'Perú (Lima)',
    'America/Mexico_City'             => $en ? 'Mexico (Mexico City)'            : 'México (Ciudad de México)',
    'America/Santiago'                => 'Chile (Santiago)',
    'America/Caracas'                 => 'Venezuela (Caracas)',
    'America/Argentina/Buenos_Aires'  => 'Argentina (Buenos Aires)',
    'America/La_Paz'                  => 'Bolivia (La Paz)',
    'America/Panama'                  => $en ? 'Panama'                           : 'Panamá',
    'America/Santo_Domingo'           => $en ? 'Dominican Republic'              : 'República Dominicana',
    'Europe/Madrid'                   => $en ? 'Spain (Madrid)'                   : 'España (Madrid)',
    'UTC'                             => 'UTC',
];

$valido = DateTimeZone::listIdentifiers();
$msg = ''; $msgTipo = '';

// Valor actual guardado
function tzGuardada($conexion) {
    $r = @$conexion->query("SELECT valor FROM app_config WHERE clave='timezone' LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) { $v = trim((string)$x['valor']); if ($v !== '') return $v; }
    return 'America/New_York';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tz = trim($_POST['timezone'] ?? '');
    if ($tz === '' || !in_array($tz, $valido, true)) {
        $msg = $en ? 'Invalid time zone.' : 'Zona horaria no válida.';
        $msgTipo = 'danger';
    } else {
        $st = $conexion->prepare("INSERT INTO app_config (clave, valor) VALUES ('timezone', ?)
                                  ON DUPLICATE KEY UPDATE valor=VALUES(valor), updated_at=NOW()");
        $st->bind_param('s', $tz); $ok = $st->execute(); $st->close();
        if ($ok) {
            auditar($conexion, 'Configuración', 'editar', 'CONFIG', null, 'Cambió la zona horaria a '.$tz);
            $msg = ($en ? 'Time zone saved: ' : 'Zona horaria guardada: ').$tz;
            $msgTipo = 'success';
        } else {
            $msg = ($en ? 'Could not save.' : 'No se pudo guardar.');
            $msgTipo = 'danger';
        }
    }
}

$tzActual = tzGuardada($conexion);
if (!isset($zonas[$tzActual])) { $zonas = [$tzActual => $tzActual] + $zonas; }

// Hora actual en la zona seleccionada (para confirmar visualmente)
function horaEn($tz) {
    try { return (new DateTime('now', new DateTimeZone($tz)))->format('d/m/Y H:i (T, P)'); }
    catch (Exception $e) { return '—'; }
}
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Time zone':'Zona horaria'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-clock icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Time zone':'Zona horaria'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Set the time zone used across this installation (dates, audit log, etc.).':'Define la zona horaria de esta instalación (fechas, bitácora, etc.).'; ?></div>
                </div>
            </div></div></div>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-<?php echo $msgTipo; ?>"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>

            <div class="row">
            <div class="col-lg-7">
            <div class="card shadow-sm"><div class="card-body">
                <form method="POST" class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold"><?php echo $en?'Time zone':'Zona horaria'; ?></label>
                        <select name="timezone" id="tzSelect" class="form-select">
                            <?php foreach ($zonas as $tz => $label): ?>
                            <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo $tz===$tzActual?'selected':''; ?>>
                                <?php echo htmlspecialchars($label); ?> — <?php echo htmlspecialchars($tz); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-save"></i> <?php echo $en?'Save':'Guardar'; ?></button>
                    </div>
                </form>
                <hr>
                <div class="small text-muted">
                    <div><?php echo $en?'Current setting:':'Configuración actual:'; ?> <b><?php echo htmlspecialchars($tzActual); ?></b></div>
                    <div><?php echo $en?'Time now in this zone:':'Hora ahora en esta zona:'; ?> <b id="tzAhora"><?php echo htmlspecialchars(horaEn($tzActual)); ?></b></div>
                </div>
            </div></div>
            </div>
            <div class="col-lg-5">
                <div class="alert alert-info small mb-0">
                    <i class="bi bi-info-circle"></i>
                    <?php echo $en
                        ? 'The change applies on the next page load. It affects only this installation/database — other copies (e.g. production) keep their own setting.'
                        : 'El cambio se aplica al cargar la siguiente página. Afecta solo a esta instalación/base de datos — otras copias (p. ej. producción) mantienen su propia configuración.'; ?>
                </div>
            </div>
            </div>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Previsualiza la hora de la zona elegida (antes de guardar)
(function(){
    var sel = document.getElementById('tzSelect'), out = document.getElementById('tzAhora');
    if(!sel || !out) return;
    sel.addEventListener('change', function(){
        try {
            var f = new Intl.DateTimeFormat('es', {timeZone: sel.value, dateStyle:'short', timeStyle:'short'});
            out.textContent = f.format(new Date()) + ' — ' + sel.value;
        } catch(e) { out.textContent = sel.value; }
    });
})();
</script>
</body>
</html>
<?php ob_end_flush(); ?>
