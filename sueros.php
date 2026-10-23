<?php
/**
 * sueros.php — Cola de colocación de sueros (enfermería).
 * Muestra las órdenes pendientes derivadas por los doctores, con el motivo y el
 * triaje del paciente, y permite registrar la aplicación (hora, lote,
 * observaciones, reacciones) o suspender la orden.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/derivacion.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('sueros.aplicar');
derivacion_asegurar_tablas($conexion);

$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$idUser = (int)($_SESSION['iduser'] ?? 0);
$msg = $_SESSION['sueros_msg'] ?? null; unset($_SESSION['sueros_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = suero_registrar($conexion, (int)($_POST['idOrden'] ?? 0),
        ($_POST['resultado'] ?? '') === 'Suspendido' ? 'Suspendido' : 'Aplicado',
        trim($_POST['lote'] ?? ''), trim($_POST['observaciones'] ?? ''), trim($_POST['reacciones'] ?? ''),
        $_POST['fecha_hora'] ?? '', $idUser);
    $_SESSION['sueros_msg'] = $ok
        ? ['success', $en ? 'Saved.' : 'Registro guardado.']
        : ['danger',  $en ? 'Could not save (already recorded?).' : 'No se pudo guardar (¿ya estaba registrada?).'];
    header('Location: sueros.php'); exit();
}

$pend = [];
$q = $conexion->query("SELECT S.IDORDEN, S.PRODUCTO, S.DOSIS, S.VIA, S.INDICACIONES, D.IDDERIVACION, D.MOTIVO, D.FECHA_CREACION, D.IDCITA_ORIGEN,
                              CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA,
                              CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS DERIVA,
                              T.PESO_KG, T.PRESION, T.FC, T.SAT_O2, T.TEMPERATURA, T.GLUCOSA
                         FROM suero_orden S
                         INNER JOIN derivacion D ON D.IDDERIVACION = S.IDDERIVACION
                         INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = D.IDPACIENTE
                         LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = D.IDUSUARIO_ORIGEN
                         LEFT JOIN triaje T ON T.IDCITA = D.IDCITA_ORIGEN
                        WHERE S.ESTADO = 'Pendiente' AND D.TIPO = 'SUERO'
                        ORDER BY D.FECHA_CREACION, S.IDORDEN");
if ($q) while ($r = $q->fetch_assoc()) $pend[] = $r;

$hechos = [];
$q = $conexion->query("SELECT S.PRODUCTO, S.DOSIS, S.ESTADO, S.FECHA_APLICACION, S.LOTE, S.REACCIONES,
                              CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE,
                              CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS APLICA
                         FROM suero_orden S
                         INNER JOIN derivacion D ON D.IDDERIVACION = S.IDDERIVACION
                         INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = D.IDPACIENTE
                         LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = S.IDUSUARIO_APLICA
                        WHERE S.ESTADO <> 'Pendiente' AND DATE(S.FECHA_APLICACION) = CURDATE()
                        ORDER BY S.FECHA_APLICACION DESC");
if ($q) while ($r = $q->fetch_assoc()) $hechos[] = $r;
$ahora = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'IV therapy':'Sueros'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-drop icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'IV therapy':'Sueros'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Pending orders from the doctors; record each application.':'Órdenes pendientes de los doctores; registra cada aplicación.'; ?></div>
                </div>
            </div></div></div>

            <?php if ($msg): ?><div class="alert alert-<?php echo h($msg[0]); ?>"><?php echo h($msg[1]); ?></div><?php endif; ?>

            <?php if (!$pend): ?><div class="card shadow-sm mb-3"><div class="card-body text-center text-muted py-4"><?php echo $en?'No pending orders.':'No hay órdenes pendientes.'; ?></div></div><?php endif; ?>

            <?php foreach ($pend as $o): ?>
            <div class="card shadow-sm mb-3"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between">
                    <div><div class="fs-5 fw-semibold"><?php echo h($o['PACIENTE']); ?> <span class="small text-muted"><?php echo h($o['CEDULA']); ?></span></div>
                        <div class="small text-muted"><?php echo $en?'Ordered by':'Indicado por'; ?> <b><?php echo h($o['DERIVA']); ?></b> · <?php echo h(date('m/d/Y H:i', strtotime($o['FECHA_CREACION']))); ?></div></div>
                    <?php if ($o['PRESION'] !== null || $o['PESO_KG'] !== null): ?>
                    <div class="small text-end text-muted"><?php echo $en?'Triage':'Triaje'; ?>:
                        <?php echo $o['PESO_KG']!==null ? h(rtrim(rtrim($o['PESO_KG'],'0'),'.')).' kg · ' : ''; ?>PA <?php echo h($o['PRESION'] ?: '—'); ?> · FC <?php echo h($o['FC'] ?? '—'); ?> · SatO₂ <?php echo h($o['SAT_O2'] ?? '—'); ?> · T° <?php echo h($o['TEMPERATURA']!==null ? rtrim(rtrim($o['TEMPERATURA'],'0'),'.') : '—'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="alert alert-info py-2 my-2"><b><?php echo h($o['PRODUCTO']); ?></b>
                    <?php echo $o['DOSIS'] ? ' · '.h($o['DOSIS']) : ''; ?><?php echo $o['VIA'] ? ' · '.h($o['VIA']) : ''; ?>
                    <?php if (trim((string)$o['INDICACIONES']) !== ''): ?><div class="small"><?php echo nl2br(h($o['INDICACIONES'])); ?></div><?php endif; ?>
                    <div class="small text-muted mt-1"><?php echo $en?'Reason':'Motivo'; ?>: <?php echo h($o['MOTIVO']); ?></div></div>
                <form method="POST" class="row g-2 align-items-end">
                    <input type="hidden" name="idOrden" value="<?php echo (int)$o['IDORDEN']; ?>">
                    <div class="col-md-3"><label class="form-label small mb-0"><?php echo $en?'Date/time':'Fecha y hora'; ?></label><input type="datetime-local" name="fecha_hora" class="form-control form-control-sm" value="<?php echo h($ahora); ?>"></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?php echo $en?'Lot':'Lote'; ?></label><input name="lote" class="form-control form-control-sm" maxlength="60"></div>
                    <div class="col-md-3"><label class="form-label small mb-0"><?php echo $en?'Observations':'Observaciones'; ?></label><input name="observaciones" class="form-control form-control-sm"></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?php echo $en?'Reactions':'Reacciones'; ?></label><input name="reacciones" class="form-control form-control-sm" placeholder="<?php echo $en?'None':'Ninguna'; ?>"></div>
                    <div class="col-md-2 d-flex gap-1">
                        <button class="btn btn-success btn-sm flex-fill" name="resultado" value="Aplicado" type="submit"><i class="bi bi-check2-circle"></i> <?php echo $en?'Applied':'Aplicado'; ?></button>
                        <button class="btn btn-outline-danger btn-sm" name="resultado" value="Suspendido" type="submit" title="<?php echo $en?'Suspend':'Suspender'; ?>" onclick="return confirm('<?php echo $en?'Suspend this order?':'¿Suspender esta orden?'; ?>');"><i class="bi bi-x-circle"></i></button>
                    </div>
                </form>
            </div></div>
            <?php endforeach; ?>

            <?php if ($hechos): ?>
            <h6 class="mt-4"><?php echo $en?'Recorded today':'Registradas hoy'; ?></h6>
            <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th><?php echo $en?'Time':'Hora'; ?></th><th><?php echo $en?'Patient':'Paciente'; ?></th><th><?php echo $en?'Product':'Suero'; ?></th><th><?php echo $en?'Lot':'Lote'; ?></th><th><?php echo $en?'Status':'Estado'; ?></th><th><?php echo $en?'By':'Por'; ?></th></tr></thead><tbody>
                <?php foreach ($hechos as $x): ?><tr>
                    <td><?php echo h(date('H:i', strtotime($x['FECHA_APLICACION']))); ?></td><td><?php echo h($x['PACIENTE']); ?></td>
                    <td><?php echo h($x['PRODUCTO'].($x['DOSIS']?' · '.$x['DOSIS']:'')); ?></td><td><?php echo h($x['LOTE']); ?></td>
                    <td><span class="badge bg-<?php echo $x['ESTADO']==='Aplicado'?'success':'secondary'; ?>"><?php echo h($x['ESTADO']); ?></span><?php echo $x['REACCIONES'] ? ' <span class="small text-danger">'.h($x['REACCIONES']).'</span>' : ''; ?></td>
                    <td><?php echo h($x['APLICA']); ?></td></tr><?php endforeach; ?>
                </tbody></table></div></div>
            <?php endif; ?>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
