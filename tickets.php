<?php
/**
 * tickets.php — Solicitudes / Tickets.
 * Cualquier usuario con sesión puede crear y ver SUS solicitudes.
 * Con el permiso 'panel.tickets' (SISTEMA y la Dra. por defecto) se ven y
 * gestionan TODAS, con filtros, métricas y exportación.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/permisos.php");
requerir_modulo('tickets');
require_once(__DIR__ . "/class/tickets.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }

tickets_ensure_tablas($conexion);
$en       = (current_lang() === 'en');
$gestiona = tickets_puede_gestionar();
$idUser   = (int)($_SESSION['iduser'] ?? 0);
$cat      = tickets_catalogos();

// Filtros
$fEstado = trim($_GET['estado'] ?? '');
$fModulo = trim($_GET['modulo'] ?? '');
$fTipo   = trim($_GET['tipo']   ?? '');
$fQ      = trim($_GET['q']      ?? '');
$fDesde  = trim($_GET['desde']  ?? '');
$fHasta  = trim($_GET['hasta']  ?? '');

$where = "WHERE 1=1"; $tipos=''; $vals=[];
if (!$gestiona) { $where .= " AND id_solicitante=?"; $tipos.='i'; $vals[]=$idUser; }
if ($fEstado !== '') { $where.=" AND estado=?"; $tipos.='s'; $vals[]=$fEstado; }
if ($fModulo !== '') { $where.=" AND modulo=?"; $tipos.='s'; $vals[]=$fModulo; }
if ($fTipo   !== '') { $where.=" AND tipo=?";   $tipos.='s'; $vals[]=$fTipo; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$fDesde)) { $where.=" AND fecha>=?"; $tipos.='s'; $vals[]=$fDesde.' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$fHasta)) { $where.=" AND fecha<=?"; $tipos.='s'; $vals[]=$fHasta.' 23:59:59'; }
if ($fQ !== '') { $where.=" AND (titulo LIKE ? OR descripcion LIKE ? OR solicitante LIKE ?)"; $tipos.='sss'; $like='%'.$fQ.'%'; array_push($vals,$like,$like,$like); }

$rows = [];
$sql = "SELECT id, titulo, modulo, tipo, prioridad, estado, solicitante, rol_solicitante, fecha, fecha_cierre
        FROM tickets $where ORDER BY (estado IN ('Resuelto','Cerrado')) ASC, FIELD(prioridad,'Alta','Media','Baja'), fecha DESC LIMIT 500";
if ($stmt = $conexion->prepare($sql)) {
    if ($tipos !== '') $stmt->bind_param($tipos, ...$vals);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($x = $res->fetch_assoc()) $rows[] = $x;
    $stmt->close();
}

// Conteo de adjuntos y comentarios por ticket (para íconos en la tabla)
$adjCount = []; $comCount = [];
if ($rows) {
    $ids = implode(',', array_map(function($r){return (int)$r['id'];}, $rows));
    if ($q = $conexion->query("SELECT id_ticket, COUNT(*) c FROM ticket_adjuntos WHERE id_ticket IN ($ids) GROUP BY id_ticket")) while ($x=$q->fetch_assoc()) $adjCount[(int)$x['id_ticket']]=(int)$x['c'];
    if ($q = $conexion->query("SELECT id_ticket, COUNT(*) c FROM ticket_comentarios WHERE id_ticket IN ($ids) GROUP BY id_ticket")) while ($x=$q->fetch_assoc()) $comCount[(int)$x['id_ticket']]=(int)$x['c'];
}

// Métricas (solo para quien gestiona)
$met = ['abierto'=>0,'progreso'=>0,'resuelto'=>0,'cerrado'=>0,'mesTotal'=>0];
if ($gestiona) {
    if ($q = $conexion->query("SELECT estado, COUNT(*) c FROM tickets GROUP BY estado")) {
        while ($x=$q->fetch_assoc()) {
            if ($x['estado']==='Abierto') $met['abierto']=(int)$x['c'];
            elseif ($x['estado']==='En progreso') $met['progreso']=(int)$x['c'];
            elseif ($x['estado']==='Resuelto') $met['resuelto']=(int)$x['c'];
            elseif ($x['estado']==='Cerrado') $met['cerrado']=(int)$x['c'];
        }
    }
    // "Cerrados este mes" = trabajo entregado en el mes en curso (por fecha de cierre).
    $r = $conexion->query("SELECT COUNT(*) c FROM tickets WHERE fecha_cierre IS NOT NULL AND YEAR(fecha_cierre)=YEAR(CURDATE()) AND MONTH(fecha_cierre)=MONTH(CURDATE())");
    if ($r) $met['mesTotal']=(int)$r->fetch_assoc()['c'];
}

$jsv = @filemtime(__DIR__.'/js/tickets.js') ?: time();
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Requests / Tickets':'Solicitudes / Tickets'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-ticket icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Requests / Tickets':'Solicitudes / Tickets'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Report bugs, fixes, improvements or questions — with screenshots.':'Reporta errores, correcciones, mejoras o preguntas — con capturas de pantalla.'; ?></div>
                </div>
            </div>
            <div class="page-title-actions">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoTicket">
                    <i class="bi bi-plus-circle"></i> <?php echo $en?'New request':'Nueva solicitud'; ?>
                </button>
            </div>
            </div></div>

            <?php if ($gestiona): ?>
            <div class="row g-2 mb-3">
                <div class="col-6 col-md"><div class="card shadow-sm text-center"><div class="card-body py-3">
                    <div class="h4 mb-0 text-danger"><?php echo $met['abierto']; ?></div>
                    <div class="small text-muted"><?php echo $en?'Open':'Abiertos'; ?></div></div></div></div>
                <div class="col-6 col-md"><div class="card shadow-sm text-center"><div class="card-body py-3">
                    <div class="h4 mb-0 text-warning"><?php echo $met['progreso']; ?></div>
                    <div class="small text-muted"><?php echo $en?'In progress':'En progreso'; ?></div></div></div></div>
                <div class="col-6 col-md"><div class="card shadow-sm text-center"><div class="card-body py-3">
                    <div class="h4 mb-0 text-success"><?php echo $met['resuelto']; ?></div>
                    <div class="small text-muted"><?php echo $en?'Resolved':'Resueltos'; ?></div></div></div></div>
                <div class="col-6 col-md"><div class="card shadow-sm text-center"><div class="card-body py-3">
                    <div class="h4 mb-0 text-secondary"><?php echo $met['cerrado']; ?></div>
                    <div class="small text-muted"><?php echo $en?'Closed':'Cerrados'; ?></div></div></div></div>
                <div class="col-6 col-md"><div class="card shadow-sm text-center"><div class="card-body py-3">
                    <div class="h4 mb-0 text-primary"><?php echo $met['mesTotal']; ?></div>
                    <div class="small text-muted"><?php echo $en?'Closed this month':'Cerrados este mes'; ?></div></div></div></div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1"><?php echo $en?'Status':'Estado'; ?></label>
                        <select name="estado" class="form-select form-select-sm">
                            <option value=""><?php echo $en?'All':'Todos'; ?></option>
                            <?php foreach ($cat['estado'] as $e): ?>
                            <option value="<?php echo htmlspecialchars($e); ?>" <?php echo $fEstado===$e?'selected':''; ?>><?php echo htmlspecialchars($e); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1"><?php echo $en?'Module':'Módulo'; ?></label>
                        <select name="modulo" class="form-select form-select-sm">
                            <option value=""><?php echo $en?'All':'Todos'; ?></option>
                            <?php foreach ($cat['modulo'] as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $fModulo===$m?'selected':''; ?>><?php echo htmlspecialchars(tickets_label_modulo($m,$en)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1"><?php echo $en?'Type':'Tipo'; ?></label>
                        <select name="tipo" class="form-select form-select-sm">
                            <option value=""><?php echo $en?'All':'Todos'; ?></option>
                            <?php foreach ($cat['tipo'] as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $fTipo===$t?'selected':''; ?>><?php echo htmlspecialchars(tickets_label_tipo($t,$en)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1"><?php echo $en?'From':'Desde'; ?></label>
                        <input type="date" name="desde" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fDesde); ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1"><?php echo $en?'To':'Hasta'; ?></label>
                        <input type="date" name="hasta" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fHasta); ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1"><?php echo $en?'Search':'Buscar'; ?></label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fQ); ?>" placeholder="<?php echo $en?'Title / detail…':'Título / detalle…'; ?>">
                    </div>
                    <div class="col-md-12 d-flex gap-2 mt-1">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> <?php echo $en?'Filter':'Filtrar'; ?></button>
                        <a href="tickets.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i> <?php echo $en?'Clear':'Limpiar'; ?></a>
                        <?php if ($gestiona): $qsE=http_build_query(['estado'=>$fEstado,'modulo'=>$fModulo,'tipo'=>$fTipo,'q'=>$fQ,'desde'=>$fDesde,'hasta'=>$fHasta]); ?>
                        <a href="tickets_export.php?<?php echo htmlspecialchars($qsE); ?>" class="btn btn-success btn-sm ms-auto"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div></div>

            <div class="card shadow-sm"><div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th><?php echo $en?'Title':'Título'; ?></th>
                                <th><?php echo $en?'Module':'Módulo'; ?></th>
                                <th><?php echo $en?'Type':'Tipo'; ?></th>
                                <th><?php echo $en?'Priority':'Prioridad'; ?></th>
                                <th><?php echo $en?'Status':'Estado'; ?></th>
                                <?php if ($gestiona): ?><th><?php echo $en?'Requester':'Solicitante'; ?></th><?php endif; ?>
                                <th><?php echo $en?'Date':'Fecha'; ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4"><?php echo $en?'No requests yet.':'Aún no hay solicitudes.'; ?></td></tr>
                        <?php else: foreach ($rows as $r): $tid=(int)$r['id']; ?>
                            <tr style="cursor:pointer;" onclick="location.href='ticket_ver.php?id=<?php echo $tid; ?>'">
                                <td class="small text-muted"><?php echo $tid; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($r['titulo']); ?>
                                    <?php if (!empty($adjCount[$tid])): ?><i class="bi bi-paperclip text-muted ms-1" title="<?php echo (int)$adjCount[$tid]; ?>"></i><?php endif; ?>
                                    <?php if (!empty($comCount[$tid])): ?><span class="text-muted small ms-1"><i class="bi bi-chat-left-text"></i> <?php echo (int)$comCount[$tid]; ?></span><?php endif; ?>
                                </td>
                                <td class="small"><?php echo htmlspecialchars(tickets_label_modulo($r['modulo'],$en)); ?></td>
                                <td class="small"><?php echo htmlspecialchars(tickets_label_tipo($r['tipo'],$en)); ?></td>
                                <td><?php echo tickets_badge_prioridad($r['prioridad'],$en); ?></td>
                                <td><?php echo tickets_badge_estado($r['estado'],$en); ?></td>
                                <?php if ($gestiona): ?><td class="small"><?php echo htmlspecialchars($r['solicitante'] ?: '—'); ?></td><?php endif; ?>
                                <td class="small text-nowrap"><?php echo htmlspecialchars(date('m/d/Y', strtotime($r['fecha']))); ?></td>
                                <td class="text-end"><i class="bi bi-chevron-right text-muted"></i></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div></div>

        </div></div>
    </div>
</div>

<!-- Modal: Nueva solicitud -->
<div class="modal fade" id="modalNuevoTicket" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="formNuevoTicket" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-circle"></i> <?php echo $en?'New request':'Nueva solicitud'; ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?php echo $en?'Title':'Título'; ?> <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="text" class="form-control" name="titulo" id="tkTitulo" maxlength="160" required placeholder="<?php echo $en?'Short summary of the issue':'Resumen breve del problema'; ?>">
                <button class="btn btn-outline-secondary mic-btn" type="button" data-target="#tkTitulo" title="<?php echo $en?'Dictate':'Dictar'; ?>"><i class="bi bi-mic"></i></button>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label"><?php echo $en?'Module':'Módulo'; ?></label>
                <select class="form-select" name="modulo">
                    <?php foreach ($cat['modulo'] as $m): ?><option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars(tickets_label_modulo($m,$en)); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo $en?'Type':'Tipo'; ?></label>
                <select class="form-select" name="tipo">
                    <?php foreach ($cat['tipo'] as $t): ?><option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars(tickets_label_tipo($t,$en)); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo $en?'Priority':'Prioridad'; ?></label>
                <select class="form-select" name="prioridad">
                    <option value="Baja"><?php echo $en?'Low':'Baja'; ?></option>
                    <option value="Media" selected><?php echo $en?'Medium':'Media'; ?></option>
                    <option value="Alta"><?php echo $en?'High':'Alta'; ?></option>
                </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label d-flex align-items-center gap-2">
                <?php echo $en?'Description':'Descripción'; ?>
                <button class="btn btn-sm btn-outline-secondary mic-btn" type="button" data-target="#tkDesc" title="<?php echo $en?'Dictate':'Dictar'; ?>"><i class="bi bi-mic"></i></button>
            </label>
            <textarea class="form-control" name="descripcion" id="tkDesc" rows="4" placeholder="<?php echo $en?'What happens, where, and what you expected…':'Qué pasa, dónde, y qué esperabas…'; ?>"></textarea>
          </div>
          <div class="mb-2 ticket-attach" id="attachNuevo">
            <label class="form-label"><i class="bi bi-image"></i> <?php echo $en?'Screenshots / files':'Capturas / archivos'; ?></label>
            <div class="attach-drop border rounded p-3 text-center text-muted" tabindex="0" contenteditable="false"
                 style="cursor:text;background:#fafafa;">
                <?php echo $en?'Click here and press Ctrl+V to paste a screenshot, or drag files here.':'Haz clic aquí y presiona Ctrl+V para pegar una captura, o arrastra archivos.'; ?>
                <div class="mt-2">
                    <label class="btn btn-sm btn-outline-primary mb-0">
                        <i class="bi bi-upload"></i> <?php echo $en?'Choose files':'Elegir archivos'; ?>
                        <input type="file" data-role="input" accept="image/*,application/pdf" multiple hidden>
                    </label>
                </div>
            </div>
            <div class="attach-thumbs mt-2"></div>
            <div class="form-text"><?php echo $en?'Images or PDF, up to 10 MB each.':'Imágenes o PDF, hasta 10 MB cada uno.'; ?></div>
          </div>
          <div class="alert alert-danger d-none mt-2" id="tkError"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo $en?'Cancel':'Cancelar'; ?></button>
          <button type="submit" class="btn btn-primary" id="tkEnviar"><i class="bi bi-send"></i> <?php echo $en?'Send':'Enviar'; ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/tickets.js?v=<?php echo $jsv; ?>"></script>
<script>
document.getElementById('formNuevoTicket').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var form = ev.target;
    var err  = document.getElementById('tkError');
    var btn  = document.getElementById('tkEnviar');
    err.classList.add('d-none');
    if (!form.titulo.value.trim()) { err.textContent = '<?php echo $en?"Title is required.":"El título es obligatorio."; ?>'; err.classList.remove('d-none'); return; }
    var fd = new FormData(form);
    var cont = document.getElementById('attachNuevo');
    (window.TicketAttach.get(cont) || []).forEach(function (f) { fd.append('adjuntos[]', f, f.name || 'captura.png'); });
    btn.disabled = true;
    fetch('ticket_guardar.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
          if (d && d.ok) { location.href = 'ticket_ver.php?id=' + d.id; }
          else { err.textContent = (d && d.error) ? d.error : 'Error'; err.classList.remove('d-none'); btn.disabled = false; }
      })
      .catch(function () { err.textContent = 'Error de red'; err.classList.remove('d-none'); btn.disabled = false; });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>
