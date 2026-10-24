<?php
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/permisos.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('agenda.ausencias');

$rol   = strtoupper($_SESSION['rol'] ?? '');
$idU   = (int)($_SESSION['iduser'] ?? 0);
$esDoctor = ($rol === 'DOCTOR');
$en = (current_lang() === 'en');

// Lista de doctores (para SISTEMA/ASISTENTE). El DOCTOR gestiona solo el suyo.
$doctores = [];
$rs = $conexion->query("SELECT U.IDADM_USUARIO, U.NOMBRES, U.APELLIDOS
                        FROM ADM_USUARIO U INNER JOIN ADM_ROL R ON U.IDADM_ROL = R.IDADM_ROL
                        WHERE R.CARGO='DOCTOR' AND U.ESTADO='A' ORDER BY U.NOMBRES");
if ($rs) while ($d = $rs->fetch_assoc()) $doctores[] = $d;
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php te('aus.title'); ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-plane icon-gradient bg-happy-itmeo"></i></div>
                <div><?php te('aus.title'); ?><div class="page-title-subheading"><?php te('aus.subtitle'); ?></div></div>
            </div></div></div>

            <div class="row g-3">
              <!-- Formulario -->
              <div class="col-lg-5">
                <div class="card shadow-sm"><div class="card-body">
                  <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-1"></i><?php te('aus.addTitle'); ?></h6>

                  <?php if (!$esDoctor): ?>
                  <div class="mb-2">
                    <label class="form-label small mb-1"><?php te('aus.doctor'); ?></label>
                    <select id="ausDoctor" class="form-select form-select-sm">
                      <option value=""><?php echo $en?'Select…':'Seleccione…'; ?></option>
                      <?php foreach ($doctores as $d): ?>
                      <option value="<?php echo (int)$d['IDADM_USUARIO']; ?>"><?php echo htmlspecialchars($d['NOMBRES'].' '.$d['APELLIDOS']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php else: ?>
                    <input type="hidden" id="ausDoctor" value="<?php echo $idU; ?>">
                    <div class="alert alert-light border py-2 small mb-2"><i class="bi bi-person-badge me-1"></i><?php echo $en?'Managing your own time off.':'Gestionando tus propias ausencias.'; ?></div>
                  <?php endif; ?>

                  <div class="mb-2">
                    <label class="form-label small mb-1"><?php te('aus.type'); ?></label>
                    <div class="btn-group btn-group-sm w-100" role="group">
                      <input type="radio" class="btn-check" name="ausTipo" id="ausTipoVac" value="vacacion" checked>
                      <label class="btn btn-outline-primary" for="ausTipoVac"><i class="bi bi-umbrella me-1"></i><?php te('aus.vacation'); ?></label>
                      <input type="radio" class="btn-check" name="ausTipo" id="ausTipoBlq" value="bloqueo">
                      <label class="btn btn-outline-primary" for="ausTipoBlq"><i class="bi bi-clock-history me-1"></i><?php te('aus.block'); ?></label>
                    </div>
                  </div>

                  <div class="row g-2">
                    <div class="col-6">
                      <label class="form-label small mb-1"><?php te('aus.from'); ?></label>
                      <input type="date" id="ausDesde" class="form-control form-control-sm">
                    </div>
                    <div class="col-6" id="ausHastaWrap">
                      <label class="form-label small mb-1"><?php te('aus.to'); ?></label>
                      <input type="date" id="ausHasta" class="form-control form-control-sm">
                    </div>
                  </div>

                  <div class="row g-2 mt-0 d-none" id="ausHorasWrap">
                    <div class="col-6">
                      <label class="form-label small mb-1"><?php te('aus.fromHour'); ?></label>
                      <input type="time" id="ausHoraIni" class="form-control form-control-sm" step="1800">
                    </div>
                    <div class="col-6">
                      <label class="form-label small mb-1"><?php te('aus.toHour'); ?></label>
                      <input type="time" id="ausHoraFin" class="form-control form-control-sm" step="1800">
                    </div>
                  </div>

                  <div class="mb-2 mt-2">
                    <label class="form-label small mb-1"><?php te('aus.reason'); ?></label>
                    <input type="text" id="ausMotivo" class="form-control form-control-sm" maxlength="255" placeholder="<?php echo $en?'Optional':'Opcional'; ?>">
                  </div>

                  <button type="button" class="btn btn-primary btn-sm w-100" onclick="ausGuardar()"><i class="bi bi-save me-1"></i><?php te('aus.save'); ?></button>
                  <div id="ausMsg" class="small mt-2"></div>
                </div></div>
              </div>

              <!-- Lista -->
              <div class="col-lg-7">
                <div class="card shadow-sm"><div class="card-body">
                  <h6 class="fw-bold mb-3"><i class="bi bi-list-ul me-1"></i><?php te('aus.listTitle'); ?></h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead><tr>
                        <th><?php te('aus.doctor'); ?></th><th><?php te('aus.type'); ?></th>
                        <th><?php te('aus.dates'); ?></th><th><?php te('aus.reason'); ?></th><th></th>
                      </tr></thead>
                      <tbody id="ausLista"><tr><td colspan="5" class="text-muted small"><?php te('common.loading'); ?></td></tr></tbody>
                    </table>
                  </div>
                </div></div>
              </div>
            </div>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var AUS_T = {
  esDoctor: <?php echo $esDoctor ? 'true':'false'; ?>,
  vac: <?php echo json_encode(t('aus.vacation')); ?>,
  blq: <?php echo json_encode(t('aus.block')); ?>,
  del: <?php echo json_encode(t('common.delete')); ?>,
  confirmDel: <?php echo json_encode($en?'Remove this entry?':'¿Quitar este registro?'); ?>,
  needDoctor: <?php echo json_encode($en?'Select a doctor.':'Seleccione un doctor.'); ?>,
  needDate: <?php echo json_encode($en?'Enter the date(s).':'Ingrese la(s) fecha(s).'); ?>,
  saved: <?php echo json_encode($en?'Saved.':'Guardado.'); ?>,
  err: <?php echo json_encode($en?'Could not save: ':'No se pudo guardar: '); ?>,
  allday: <?php echo json_encode($en?'all day':'todo el día'); ?>
};
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function ausToggleTipo(){
  var blq = document.getElementById('ausTipoBlq').checked;
  document.getElementById('ausHorasWrap').classList.toggle('d-none', !blq);
  document.getElementById('ausHastaWrap').classList.toggle('d-none', blq); // bloqueo = una sola fecha
}
document.querySelectorAll('input[name="ausTipo"]').forEach(function(r){ r.addEventListener('change', ausToggleTipo); });

function ausCargar(){
  var url = 'get_ausencias.php?accion=listar' + (AUS_T.esDoctor ? '&idDoctor=' + encodeURIComponent(document.getElementById('ausDoctor').value) : '');
  fetch(url).then(function(r){return r.json();}).then(function(rows){
    var tb = document.getElementById('ausLista');
    if(!rows.length){ tb.innerHTML = '<tr><td colspan="5" class="text-muted small">—</td></tr>'; return; }
    tb.innerHTML = rows.map(function(a){
      var tipo = a.tipo==='bloqueo' ? '<span class="badge bg-warning text-dark">'+esc(AUS_T.blq)+'</span>' : '<span class="badge bg-info text-dark">'+esc(AUS_T.vac)+'</span>';
      var fechas = a.tipo==='bloqueo'
          ? esc(a.fecha_inicio) + ' · ' + esc(a.hora_inicio) + '–' + esc(a.hora_fin)
          : esc(a.fecha_inicio) + (a.fecha_fin && a.fecha_fin!==a.fecha_inicio ? ' → ' + esc(a.fecha_fin) : '') + ' <span class="text-muted">('+esc(AUS_T.allday)+')</span>';
      return '<tr>'
        + '<td>'+esc(a.doctor)+'</td>'
        + '<td>'+tipo+'</td>'
        + '<td class="small">'+fechas+'</td>'
        + '<td class="small text-muted">'+esc(a.motivo||'')+'</td>'
        + '<td class="text-end"><button class="btn btn-sm btn-outline-danger py-0 px-1" title="'+esc(AUS_T.del)+'" onclick="ausEliminar('+a.id+')"><i class="bi bi-trash"></i></button></td>'
        + '</tr>';
    }).join('');
  }).catch(function(){});
}
function ausGuardar(){
  var idDoctor = document.getElementById('ausDoctor').value;
  var tipo = document.querySelector('input[name="ausTipo"]:checked').value;
  var desde = document.getElementById('ausDesde').value;
  var msg = document.getElementById('ausMsg'); msg.textContent='';
  if(!AUS_T.esDoctor && !idDoctor){ msg.className='small mt-2 text-danger'; msg.textContent=AUS_T.needDoctor; return; }
  if(!desde){ msg.className='small mt-2 text-danger'; msg.textContent=AUS_T.needDate; return; }
  var data = new URLSearchParams({ idDoctor: idDoctor, tipo: tipo, fecha_inicio: desde,
      fecha_fin: document.getElementById('ausHasta').value || desde,
      hora_inicio: document.getElementById('ausHoraIni').value || '',
      hora_fin: document.getElementById('ausHoraFin').value || '',
      motivo: document.getElementById('ausMotivo').value || '' });
  fetch('ausencia_guardar.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:data })
    .then(function(r){return r.json();}).then(function(res){
      if(res.ok){ msg.className='small mt-2 text-success'; msg.textContent=AUS_T.saved;
        document.getElementById('ausMotivo').value=''; ausCargar(); }
      else { msg.className='small mt-2 text-danger'; msg.textContent=AUS_T.err + (res.error||''); }
    }).catch(function(){ msg.className='small mt-2 text-danger'; msg.textContent=AUS_T.err; });
}
function ausEliminar(id){
  if(!confirm(AUS_T.confirmDel)) return;
  fetch('ausencia_eliminar.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({id:id}) })
    .then(function(r){return r.json();}).then(function(res){ if(res.ok) ausCargar(); });
}
document.addEventListener('DOMContentLoaded', function(){ ausToggleTipo(); ausCargar();
  var sel=document.getElementById('ausDoctor'); if(sel && sel.tagName==='SELECT') sel.addEventListener('change', ausCargar);
});
</script>
</body>
</html>
