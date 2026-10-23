<?php
/**
 * examenes.php — Exámenes de todos los pacientes: pendientes por realizar y realizados.
 * Permite marcarlos como realizados (con resultado/observación) o cancelarlos.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/enfermeria.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('examenes.registrar');
enfermeria_asegurar_tablas($conexion);
$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$estado = $_GET['estado'] ?? 'Pendiente';
if (!in_array($estado, ['Pendiente','Realizado','Cancelado','Todos'], true)) $estado = 'Pendiente';
$tipoF = $_GET['tipo'] ?? '';
$busca = trim($_GET['q'] ?? '');
$sql = "SELECT E.*, CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA,
               TRIM(CONCAT(COALESCE(U.NOMBRES,''),' ',COALESCE(U.APELLIDOS,''))) AS SOLICITA
          FROM examen_orden E INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = E.IDPACIENTE
          LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = E.IDUSUARIO_SOLICITA WHERE 1=1";
$types = ''; $vals = [];
if ($estado !== 'Todos') { $sql .= " AND E.ESTADO = ?"; $types .= 's'; $vals[] = $estado; }
if (in_array($tipoF, examen_tipos(), true)) { $sql .= " AND E.TIPO = ?"; $types .= 's'; $vals[] = $tipoF; }
if ($busca !== '') { $like = '%'.$busca.'%'; $sql .= " AND (P.NOMBRES LIKE ? OR P.APELLIDOS LIKE ? OR P.CEDULA LIKE ? OR E.EXAMEN LIKE ?)"; $types .= 'ssss'; array_push($vals, $like, $like, $like, $like); }
$sql .= " ORDER BY (E.ESTADO='Pendiente') DESC, E.FECHA_SOLICITUD DESC LIMIT 300";
$st = $conexion->prepare($sql); if ($types !== '') $st->bind_param($types, ...$vals);
$lista = []; if ($st && $st->execute()) { $rs = $st->get_result(); while ($r = $rs->fetch_assoc()) $lista[] = $r; $st->close(); }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Exams':'Exámenes'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-note2 icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Exams':'Exámenes'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Pending and completed exams (lab, ultrasound, imaging).':'Exámenes pendientes y realizados (laboratorio, ecografía, imagen).'; ?></div>
                </div></div></div></div>
            <form method="GET" class="card shadow-sm mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
                <select name="estado" class="form-select form-select-sm" style="max-width:150px" onchange="this.form.submit()">
                    <?php foreach (['Pendiente'=>($en?'Pending':'Pendientes'),'Realizado'=>($en?'Completed':'Realizados'),'Cancelado'=>($en?'Cancelled':'Cancelados'),'Todos'=>($en?'All':'Todos')] as $k=>$l): ?><option value="<?php echo h($k); ?>" <?php echo $estado===$k?'selected':''; ?>><?php echo h($l); ?></option><?php endforeach; ?></select>
                <select name="tipo" class="form-select form-select-sm" style="max-width:150px" onchange="this.form.submit()"><option value=""><?php echo $en?'All types':'Todos los tipos'; ?></option>
                    <?php foreach (examen_tipos() as $tp): ?><option <?php echo $tipoF===$tp?'selected':''; ?>><?php echo h($tp); ?></option><?php endforeach; ?></select>
                <input name="q" value="<?php echo h($busca); ?>" class="form-control form-control-sm ms-md-auto" style="max-width:260px" placeholder="<?php echo $en?'Search patient or exam…':'Buscar paciente o examen…'; ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
            </div></form>
            <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th><?php echo $en?'Requested':'Solicitado'; ?></th><th><?php echo $en?'Patient':'Paciente'; ?></th><th><?php echo $en?'Exam':'Examen'; ?></th><th><?php echo $en?'Type':'Tipo'; ?></th><th><?php echo $en?'Status':'Estado'; ?></th><th><?php echo $en?'Result':'Resultado'; ?></th><th></th></tr></thead><tbody>
                <?php foreach ($lista as $e): $cl = $e['ESTADO']==='Pendiente' ? 'warning text-dark' : ($e['ESTADO']==='Realizado' ? 'success' : 'secondary'); ?>
                <tr><td><?php echo h(date('m/d/Y', strtotime($e['FECHA_SOLICITUD']))); ?><div class="small text-muted"><?php echo h($e['SOLICITA']); ?></div></td>
                    <td><b><?php echo h($e['PACIENTE']); ?></b> <span class="small text-muted"><?php echo h($e['CEDULA']); ?></span></td>
                    <td><?php echo h($e['EXAMEN']); ?><?php echo $e['INDICACION'] ? '<div class="small text-muted">'.h($e['INDICACION']).'</div>' : ''; ?></td>
                    <td><?php echo h($e['TIPO']); ?></td>
                    <td><span class="badge bg-<?php echo $cl; ?>"><?php echo h($e['ESTADO']); ?></span><?php echo $e['FECHA_REALIZADO'] ? '<div class="small text-muted">'.h(date('m/d/Y H:i', strtotime($e['FECHA_REALIZADO']))).'</div>' : ''; ?></td>
                    <td class="small"><?php echo nl2br(h($e['RESULTADO'])); ?></td>
                    <td class="text-end text-nowrap"><?php if ($e['ESTADO']==='Pendiente'): ?>
                        <button class="btn btn-sm btn-outline-success" onclick="exAccion(<?php echo (int)$e['IDEXAMEN']; ?>,'realizar')"><i class="bi bi-check2"></i></button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="exAccion(<?php echo (int)$e['IDEXAMEN']; ?>,'cancelar')"><i class="bi bi-x"></i></button><?php endif; ?></td></tr>
                <?php endforeach; if (!$lista): ?><tr><td colspan="7" class="text-center text-muted py-4"><?php echo $en?'Nothing to show.':'No hay exámenes para mostrar.'; ?></td></tr><?php endif; ?>
                </tbody></table></div></div>
        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exAccion(id, acc) {
    var en = <?php echo json_encode($en); ?>, res = '';
    if (acc === 'realizar') { res = prompt(en ? 'Result / observation (optional):' : 'Resultado u observación (opcional):', ''); if (res === null) return; }
    else if (!confirm(en ? 'Cancel this exam?' : '¿Cancelar este examen?')) return;
    var fd = new FormData(); fd.append('accion', acc); fd.append('idExamen', id); fd.append('resultado', res);
    fetch('examen_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ if (j.ok) location.reload(); else alert(en ? 'Could not update.' : 'No se pudo actualizar.'); });
}
</script>
</body></html>
<?php ob_end_flush(); ?>
