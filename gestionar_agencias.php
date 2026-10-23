<?php
/**
 * gestionar_agencias.php — Localidades / sedes (ADM_AGENCIA): nombre, dirección y teléfono de la clínica.
 * Alta, edición, activar/desactivar y eliminar (solo si ninguna cita ni seguro la usa). Solo SISTEMA.
 * Las localidades activas aparecen al agendar citas ("Ubicación").
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/permisos.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('cfg.agencias');
$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$msg = null;

/** ¿Cuántos registros usan la localidad? (citas + seguros) */
function agencia_usos($c, $id) {
    $id = (int)$id; $n = 0;
    $r = @$c->query("SELECT COUNT(*) c FROM AG_CITA WHERE IDAGENCIA = $id"); if ($r) $n += (int)$r->fetch_assoc()['c'];
    $r = @$c->query("SELECT COUNT(*) c FROM seguros WHERE Id_agencia = $id"); if ($r) $n += (int)$r->fetch_assoc()['c'];
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = $_POST['accion'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);
    if ($acc === 'guardar') {
        $nom = mb_substr(trim($_POST['descripcion'] ?? ''), 0, 80);
        $dir = mb_substr(trim($_POST['direccion'] ?? ''), 0, 120);
        $tel = mb_substr(trim($_POST['telefono'] ?? ''), 0, 30);
        if ($nom === '') { $msg = ['danger', $en ? 'The name is required.' : 'El nombre es obligatorio.']; }
        else {
            $dup = $conexion->prepare("SELECT IDAGENCIA FROM ADM_AGENCIA WHERE LOWER(TRIM(DESCRIPCION)) = LOWER(?) AND IDAGENCIA <> ? LIMIT 1");
            $dup->bind_param('si', $nom, $id); $dup->execute();
            $existe = $dup->get_result()->fetch_assoc(); $dup->close();
            if ($existe) { $msg = ['danger', $en ? 'A location with that name already exists.' : 'Ya existe una localidad con ese nombre.']; }
            elseif ($id > 0) {
                $st = $conexion->prepare("UPDATE ADM_AGENCIA SET DESCRIPCION=?, DIRECCION=NULLIF(?,''), TELEFONO=NULLIF(?,'') WHERE IDAGENCIA=?");
                $st->bind_param('sssi', $nom, $dir, $tel, $id); $st->execute(); $st->close();
                auditar($conexion, 'Configuración', 'editar', 'ADM_AGENCIA', $id, 'Editó la localidad: '.$nom);
                $msg = ['success', $en ? 'Location updated.' : 'Localidad actualizada.'];
            } else {
                $nuevo = (int)$conexion->query("SELECT COALESCE(MAX(IDAGENCIA),0)+1 n FROM ADM_AGENCIA")->fetch_assoc()['n'];
                $st = $conexion->prepare("INSERT INTO ADM_AGENCIA (IDAGENCIA, DESCRIPCION, DIRECCION, TELEFONO, ESTADO) VALUES (?, ?, NULLIF(?,''), NULLIF(?,''), 1)");
                $st->bind_param('isss', $nuevo, $nom, $dir, $tel); $st->execute(); $st->close();
                auditar($conexion, 'Configuración', 'crear', 'ADM_AGENCIA', $nuevo, 'Creó la localidad: '.$nom);
                $msg = ['success', $en ? 'Location created.' : 'Localidad creada.'];
            }
        }
    } elseif ($acc === 'toggle' && $id > 0) {
        $conexion->query("UPDATE ADM_AGENCIA SET ESTADO = IF(ESTADO = 1, 0, 1) WHERE IDAGENCIA = $id");
        auditar($conexion, 'Configuración', 'editar', 'ADM_AGENCIA', $id, 'Cambió el estado de la localidad');
        $msg = ['success', $en ? 'Status updated.' : 'Estado actualizado.'];
    } elseif ($acc === 'eliminar' && $id > 0) {
        if (agencia_usos($conexion, $id) > 0) { $msg = ['danger', $en ? 'It is used by appointments or insurers: deactivate it instead.' : 'Está en uso por citas o seguros: desactívala en lugar de eliminarla.']; }
        else {
            $conexion->query("DELETE FROM ADM_AGENCIA WHERE IDAGENCIA = $id");
            auditar($conexion, 'Configuración', 'eliminar', 'ADM_AGENCIA', $id, 'Eliminó la localidad #'.$id);
            $msg = ['success', $en ? 'Location deleted.' : 'Localidad eliminada.'];
        }
    }
}
$lista = [];
$q = $conexion->query("SELECT IDAGENCIA, DESCRIPCION, DIRECCION, TELEFONO, ESTADO FROM ADM_AGENCIA ORDER BY ESTADO DESC, DESCRIPCION");
if ($q) while ($r = $q->fetch_assoc()) { $r['usos'] = agencia_usos($conexion, $r['IDAGENCIA']); $lista[] = $r; }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Locations':'Localidades'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-map-marker icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Locations':'Localidades'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Clinic sites: name, address and phone. Active ones are offered when scheduling.':'Sedes de la clínica: nombre, dirección y teléfono. Las activas se ofrecen al agendar citas.'; ?></div>
                </div></div>
                <div class="page-title-actions"><button class="btn btn-primary btn-sm" onclick="editarAg(null)"><i class="bi bi-plus-lg"></i> <?php echo $en?'New location':'Nueva localidad'; ?></button></div></div></div>
            <?php if ($msg): ?><div class="alert alert-<?php echo h($msg[0]); ?>"><?php echo h($msg[1]); ?></div><?php endif; ?>
            <div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th><?php echo $en?'Name':'Nombre'; ?></th><th><?php echo $en?'Address':'Dirección'; ?></th><th><?php echo $en?'Phone':'Teléfono'; ?></th><th class="text-center"><?php echo $en?'Status':'Estado'; ?></th><th class="text-center"><?php echo $en?'In use':'En uso'; ?></th><th></th></tr></thead><tbody>
                <?php foreach ($lista as $a): $act = (int)$a['ESTADO'] === 1; ?>
                <tr class="<?php echo $act ? '' : 'text-muted'; ?>"><td class="fw-semibold"><?php echo h($a['DESCRIPCION']); ?></td><td><?php echo h($a['DIRECCION'] ?: '—'); ?></td><td><?php echo h($a['TELEFONO'] ?: '—'); ?></td>
                    <td class="text-center"><span class="badge bg-<?php echo $act ? 'success' : 'secondary'; ?>"><?php echo $act ? ($en?'Active':'Activa') : ($en?'Inactive':'Inactiva'); ?></span></td>
                    <td class="text-center"><?php echo (int)$a['usos']; ?></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-warning" onclick='editarAg(<?php echo json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline"><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$a['IDAGENCIA']; ?>"><button class="btn btn-sm btn-outline-secondary" title="<?php echo $act ? ($en?'Deactivate':'Desactivar') : ($en?'Activate':'Activar'); ?>"><i class="bi bi-<?php echo $act ? 'slash-circle' : 'check-circle'; ?>"></i></button></form>
                        <?php if ((int)$a['usos'] === 0): ?><form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $en?'Delete this location?':'¿Eliminar esta localidad?'; ?>');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?php echo (int)$a['IDAGENCIA']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
                    </td></tr>
                <?php endforeach; if (!$lista): ?><tr><td colspan="6" class="text-center text-muted py-4"><?php echo $en?'No locations yet.':'Aún no hay localidades.'; ?></td></tr><?php endif; ?>
                </tbody></table></div></div>
        </div></div>
    </div>
</div>
<div class="modal fade" id="modalAg" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="agTitulo"><?php echo $en?'Location':'Localidad'; ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" id="agId" value="0">
        <div class="mb-2"><label class="form-label"><?php echo $en?'Name':'Nombre'; ?> *</label><input name="descripcion" id="agNombre" class="form-control" maxlength="80" required placeholder="Kennedy"></div>
        <div class="mb-2"><label class="form-label"><?php echo $en?'Address':'Dirección'; ?></label><input name="direccion" id="agDir" class="form-control" maxlength="120"></div>
        <div class="mb-2"><label class="form-label"><?php echo $en?'Phone':'Teléfono'; ?></label><input name="telefono" id="agTel" class="form-control" maxlength="30"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo $en?'Cancel':'Cancelar'; ?></button><button class="btn btn-primary" type="submit"><?php echo $en?'Save':'Guardar'; ?></button></div>
</form></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editarAg(a) {
    document.getElementById('agId').value = a ? a.IDAGENCIA : 0;
    document.getElementById('agNombre').value = a ? a.DESCRIPCION : '';
    document.getElementById('agDir').value = a ? (a.DIRECCION || '') : '';
    document.getElementById('agTel').value = a ? (a.TELEFONO || '') : '';
    document.getElementById('agTitulo').textContent = a ? <?php echo json_encode($en?'Edit location':'Editar localidad'); ?> : <?php echo json_encode($en?'New location':'Nueva localidad'); ?>;
    new bootstrap.Modal(document.getElementById('modalAg')).show();
}
</script>
</body></html>
<?php ob_end_flush(); ?>
