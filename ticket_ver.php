<?php
/**
 * ticket_ver.php — Detalle de una solicitud: descripción, adjuntos, hilo de
 * comentarios y (para quien gestiona) cambio de estado/prioridad.
 * Acceso: quien gestiona (panel.tickets) o el solicitante del ticket.
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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: tickets.php"); exit(); }

$st = $conexion->prepare("SELECT * FROM tickets WHERE id=? LIMIT 1");
$st->bind_param('i', $id); $st->execute();
$tk = $st->get_result()->fetch_assoc(); $st->close();
if (!$tk) { header("Location: tickets.php"); exit(); }
if (!$gestiona && (int)$tk['id_solicitante'] !== $idUser) { header("Location: break.php"); exit(); }

// Adjuntos
$adjTicket = []; $adjPorComentario = [];
if ($q = $conexion->prepare("SELECT id, id_comentario, nombre_original, mime FROM ticket_adjuntos WHERE id_ticket=? ORDER BY id ASC")) {
    $q->bind_param('i', $id); $q->execute();
    $rs = $q->get_result();
    while ($a = $rs->fetch_assoc()) {
        if ($a['id_comentario'] === null) $adjTicket[] = $a;
        else $adjPorComentario[(int)$a['id_comentario']][] = $a;
    }
    $q->close();
}

// Comentarios
$comentarios = [];
if ($q = $conexion->prepare("SELECT id, usuario, rol, comentario, fecha FROM ticket_comentarios WHERE id_ticket=? ORDER BY fecha ASC, id ASC")) {
    $q->bind_param('i', $id); $q->execute();
    $rs = $q->get_result();
    while ($c = $rs->fetch_assoc()) $comentarios[] = $c;
    $q->close();
}

$GLOBALS['tkImgs'] = []; // lista ordenada de imágenes para el carrusel
function adjChip($a, $en) {
    $esImg = strpos((string)$a['mime'], 'image/') === 0;
    $url = 'ticket_adjunto_ver.php?id='.(int)$a['id'];
    if ($esImg) {
        $idx = count($GLOBALS['tkImgs']);
        $GLOBALS['tkImgs'][] = ['url'=>$url, 'nombre'=>(string)($a['nombre_original'] ?: 'Imagen')];
        // href como respaldo (sin JS); con JS abre el modal/carrusel.
        return '<a href="'.$url.'" target="_blank" class="tk-img-thumb d-inline-block me-2 mb-2" data-idx="'.$idx.'">'
             . '<img src="'.$url.'" style="width:90px;height:90px;object-fit:cover;border-radius:6px;border:1px solid #ddd;cursor:zoom-in;"></a>';
    }
    return '<a href="'.$url.'" target="_blank" class="btn btn-sm btn-outline-danger me-2 mb-2">'
         . '<i class="bi bi-file-earmark-pdf"></i> '.htmlspecialchars($a['nombre_original'] ?: 'PDF').'</a>';
}

$jsv = @filemtime(__DIR__.'/js/tickets.js') ?: time();
$puedeComentar = $gestiona || ((int)$tk['id_solicitante'] === $idUser);
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo ($en?'Request #':'Solicitud #').$id; ?></title>
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

            <div class="mb-3">
                <a href="tickets.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?php echo $en?'Back to requests':'Volver a solicitudes'; ?></a>
            </div>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h4 class="mb-1"><span class="text-muted">#<?php echo $id; ?></span> <?php echo htmlspecialchars($tk['titulo']); ?></h4>
                        <div class="mb-2">
                            <?php echo tickets_badge_estado($tk['estado'],$en); ?>
                            <?php echo tickets_badge_prioridad($tk['prioridad'],$en); ?>
                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(tickets_label_modulo($tk['modulo'],$en)); ?></span>
                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(tickets_label_tipo($tk['tipo'],$en)); ?></span>
                        </div>
                        <div class="small text-muted">
                            <i class="bi bi-person"></i> <?php echo htmlspecialchars($tk['solicitante'] ?: '—'); ?>
                            <?php if ($tk['rol_solicitante']): ?>(<?php echo htmlspecialchars($tk['rol_solicitante']); ?>)<?php endif; ?>
                            · <i class="bi bi-calendar3"></i> <?php echo htmlspecialchars(date('m/d/Y H:i', strtotime($tk['fecha']))); ?>
                            <?php if (!empty($tk['fecha_cierre'])): ?> · <span class="text-success"><i class="bi bi-check2-circle"></i> <?php echo $en?'Closed ':'Cerrado '; ?><?php echo htmlspecialchars(date('m/d/Y', strtotime($tk['fecha_cierre']))); ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (trim((string)$tk['descripcion']) !== ''): ?>
                <hr>
                <div style="white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($tk['descripcion'])); ?></div>
                <?php endif; ?>

                <?php if ($adjTicket): ?>
                <div class="mt-3"><?php foreach ($adjTicket as $a) echo adjChip($a,$en); ?></div>
                <?php endif; ?>
            </div></div>

            <?php if ($gestiona): ?>
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h6 class="mb-3"><i class="bi bi-gear"></i> <?php echo $en?'Manage':'Gestionar'; ?></h6>
                <form id="formEstado" class="row g-2 align-items-end">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <div class="col-md-4">
                        <label class="form-label small mb-1"><?php echo $en?'Status':'Estado'; ?></label>
                        <select name="estado" class="form-select form-select-sm">
                            <?php foreach ($cat['estado'] as $e): ?>
                            <option value="<?php echo htmlspecialchars($e); ?>" <?php echo $tk['estado']===$e?'selected':''; ?>><?php echo htmlspecialchars($e); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1"><?php echo $en?'Priority':'Prioridad'; ?></label>
                        <select name="prioridad" class="form-select form-select-sm">
                            <?php foreach ($cat['prioridad'] as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $tk['prioridad']===$p?'selected':''; ?>><?php echo htmlspecialchars($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save"></i> <?php echo $en?'Save':'Guardar'; ?></button>
                        <span id="estadoMsg" class="small ms-2"></span>
                    </div>
                </form>
            </div></div>
            <?php endif; ?>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <h6 class="mb-3"><i class="bi bi-chat-left-text"></i> <?php echo $en?'Conversation':'Conversación'; ?></h6>
                <?php if (!$comentarios): ?>
                    <p class="text-muted small"><?php echo $en?'No comments yet.':'Aún no hay comentarios.'; ?></p>
                <?php else: foreach ($comentarios as $c): ?>
                    <div class="border rounded p-2 mb-2">
                        <div class="small text-muted mb-1">
                            <strong><?php echo htmlspecialchars($c['usuario'] ?: '—'); ?></strong>
                            <?php if ($c['rol']): ?><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['rol']); ?></span><?php endif; ?>
                            · <?php echo htmlspecialchars(date('m/d/Y H:i', strtotime($c['fecha']))); ?>
                        </div>
                        <div style="white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($c['comentario'])); ?></div>
                        <?php if (!empty($adjPorComentario[(int)$c['id']])): ?>
                        <div class="mt-2"><?php foreach ($adjPorComentario[(int)$c['id']] as $a) echo adjChip($a,$en); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>

                <?php if ($puedeComentar): ?>
                <form id="formComentar" enctype="multipart/form-data" class="mt-3">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <label class="form-label d-flex align-items-center gap-2">
                        <?php echo $en?'Add comment':'Agregar comentario'; ?>
                        <button class="btn btn-sm btn-outline-secondary mic-btn" type="button" data-target="#cmTexto" title="<?php echo $en?'Dictate':'Dictar'; ?>"><i class="bi bi-mic"></i></button>
                    </label>
                    <textarea class="form-control mb-2" name="comentario" id="cmTexto" rows="3" placeholder="<?php echo $en?'Write a reply…':'Escribe una respuesta…'; ?>"></textarea>
                    <div class="ticket-attach mb-2" id="attachComentar">
                        <div class="attach-drop border rounded p-2 text-center text-muted small" tabindex="0" style="cursor:text;background:#fafafa;">
                            <?php echo $en?'Click and Ctrl+V to paste a screenshot, or drag files.':'Haz clic y Ctrl+V para pegar una captura, o arrastra archivos.'; ?>
                            <label class="btn btn-sm btn-outline-primary ms-2 mb-0">
                                <i class="bi bi-upload"></i> <?php echo $en?'Files':'Archivos'; ?>
                                <input type="file" data-role="input" accept="image/*,application/pdf" multiple hidden>
                            </label>
                        </div>
                        <div class="attach-thumbs mt-2"></div>
                    </div>
                    <div class="alert alert-danger d-none" id="cmError"></div>
                    <button class="btn btn-primary btn-sm" type="submit" id="cmEnviar"><i class="bi bi-send"></i> <?php echo $en?'Send':'Enviar'; ?></button>
                </form>
                <?php endif; ?>
            </div></div>

        </div></div>
    </div>
</div>

<?php if (!empty($GLOBALS['tkImgs'])): ?>
<!-- Modal lightbox con carrusel de imágenes -->
<div class="modal fade" id="modalImg" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content bg-dark">
      <div class="modal-header border-0 py-2">
        <span class="text-white-50 small" id="tkImgCaption"></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <div id="tkCarousel" class="carousel slide" data-bs-ride="false">
          <div class="carousel-inner">
            <?php foreach ($GLOBALS['tkImgs'] as $i => $im): ?>
            <div class="carousel-item <?php echo $i===0?'active':''; ?>" data-nombre="<?php echo htmlspecialchars($im['nombre']); ?>">
              <img src="<?php echo htmlspecialchars($im['url']); ?>" class="d-block mx-auto" style="max-height:78vh;max-width:100%;object-fit:contain;">
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (count($GLOBALS['tkImgs']) > 1): ?>
          <button class="carousel-control-prev" type="button" data-bs-target="#tkCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon"></span><span class="visually-hidden">Prev</span>
          </button>
          <button class="carousel-control-next" type="button" data-bs-target="#tkCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon"></span><span class="visually-hidden">Next</span>
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer border-0 py-2">
        <a id="tkImgDownload" href="#" class="btn btn-sm btn-outline-light" download><i class="bi bi-download"></i> <?php echo $en?'Download':'Descargar'; ?></a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/tickets.js?v=<?php echo $jsv; ?>"></script>
<?php if (!empty($GLOBALS['tkImgs'])): ?>
<script>
(function () {
    var carEl = document.getElementById('tkCarousel');
    var modalEl = document.getElementById('modalImg');
    if (!carEl || !modalEl) return;
    var carousel = bootstrap.Carousel.getOrCreateInstance(carEl, { interval: false, ride: false });
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var caption = document.getElementById('tkImgCaption');
    var dl = document.getElementById('tkImgDownload');

    function sync() {
        var active = carEl.querySelector('.carousel-item.active');
        if (!active) return;
        if (caption) caption.textContent = active.getAttribute('data-nombre') || '';
        var img = active.querySelector('img');
        if (dl && img) dl.href = img.getAttribute('src') + '&dl=1';
    }
    carEl.addEventListener('slid.bs.carousel', sync);

    document.querySelectorAll('.tk-img-thumb').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var idx = parseInt(a.getAttribute('data-idx'), 10) || 0;
            carousel.to(idx);
            sync();
            modal.show();
        });
    });
})();
</script>
<?php endif; ?>
<script>
<?php if ($gestiona): ?>
document.getElementById('formEstado').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var msg = document.getElementById('estadoMsg');
    msg.textContent = '…';
    fetch('ticket_estado.php', { method:'POST', body:new FormData(ev.target) })
      .then(function(r){return r.json();})
      .then(function(d){
          if (d && d.ok) { msg.className='small ms-2 text-success'; msg.textContent='<?php echo $en?"Saved":"Guardado"; ?>'; setTimeout(function(){location.reload();}, 500); }
          else { msg.className='small ms-2 text-danger'; msg.textContent=(d&&d.error)?d.error:'Error'; }
      })
      .catch(function(){ msg.className='small ms-2 text-danger'; msg.textContent='Error'; });
});
<?php endif; ?>
<?php if ($puedeComentar): ?>
document.getElementById('formComentar').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var form = ev.target, err = document.getElementById('cmError'), btn = document.getElementById('cmEnviar');
    err.classList.add('d-none');
    var fd = new FormData(form);
    var cont = document.getElementById('attachComentar');
    (window.TicketAttach.get(cont) || []).forEach(function (f) { fd.append('adjuntos[]', f, f.name || 'captura.png'); });
    if (!form.comentario.value.trim() && (window.TicketAttach.get(cont)||[]).length === 0) {
        err.textContent = '<?php echo $en?"Write a comment or attach a file.":"Escribe un comentario o adjunta un archivo."; ?>'; err.classList.remove('d-none'); return;
    }
    btn.disabled = true;
    fetch('ticket_comentar.php', { method:'POST', body:fd })
      .then(function(r){return r.json();})
      .then(function(d){ if (d&&d.ok) location.reload(); else { err.textContent=(d&&d.error)?d.error:'Error'; err.classList.remove('d-none'); btn.disabled=false; } })
      .catch(function(){ err.textContent='Error de red'; err.classList.remove('d-none'); btn.disabled=false; });
});
<?php endif; ?>
</script>
</body>
</html>
<?php ob_end_flush(); ?>
