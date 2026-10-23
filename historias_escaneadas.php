<?php
/**
 * historias_escaneadas.php — Sube historias clínicas físicas escaneadas (PDF/imagen).
 * El personal elige un paciente (o lo crea con datos mínimos) y sube uno o varios
 * archivos. Reutiliza el módulo de documentos del paciente: paciente_archivo_subir.php
 * (almacenamiento protegido) y paciente_archivo_ver.php (visor con auditoría).
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/pacientes_util.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('pac.historias');
paciente_archivos_asegurar($conexion);

$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$puedeVer = puede('pac.archivos') || puede('pac.historias');

$recientes = [];
$q = $conexion->query("SELECT A.id, A.titulo, A.nombre_original, A.fecha, A.fecha_doc, A.tamano,
                              CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA,
                              TRIM(CONCAT(COALESCE(U.NOMBRES,''),' ',COALESCE(U.APELLIDOS,''))) AS USUARIO
                         FROM paciente_archivos A
                         INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = A.IDPACIENTE
                         LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = A.id_usuario
                        WHERE A.tipo = 'historia_fisica' AND A.estado = 'A'
                        ORDER BY A.fecha DESC, A.id DESC LIMIT 20");
if ($q) while ($r = $q->fetch_assoc()) $recientes[] = $r;
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'Scanned records':'Historias escaneadas'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-copy-file icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'Scanned medical records':'Historias clínicas escaneadas'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Upload paper records (PDF) to the patient file.':'Sube las historias en papel (PDF) a la ficha del paciente.'; ?></div>
                </div>
            </div></div></div>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <h6 class="mb-2"><?php echo $en?'1. Patient':'1. Paciente'; ?></h6>
                <input type="text" id="hsBusca" class="form-control mb-1" autocomplete="off" placeholder="<?php echo $en?'Search by name or ID (min. 2 letters)…':'Buscar por nombre o cédula (mín. 2 letras)…'; ?>">
                <div id="hsRes" class="list-group mb-2"></div>
                <div id="hsSel" class="alert alert-success py-2 d-none d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-check"></i> <b id="hsSelNombre"></b></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="hsQuitar"><?php echo $en?'Change':'Cambiar'; ?></button></div>
                <div id="hsNuevoWrap">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="hsToggleNuevo"><i class="bi bi-person-plus"></i> <?php echo $en?'Patient not found — create new':'No aparece — crear paciente nuevo'; ?></button>
                    <div id="hsNuevo" class="mt-2 d-none">
                        <div class="row g-2">
                            <div class="col-md-3"><input id="npNombres" class="form-control" placeholder="<?php echo $en?'First name *':'Nombres *'; ?>"></div>
                            <div class="col-md-3"><input id="npApellidos" class="form-control" placeholder="<?php echo $en?'Last name *':'Apellidos *'; ?>"></div>
                            <div class="col-md-2"><input id="npCedula" class="form-control" placeholder="<?php echo $en?'ID':'Cédula'; ?>"></div>
                            <div class="col-md-2"><input id="npTelefono" class="form-control" placeholder="<?php echo $en?'Phone':'Teléfono'; ?>"></div>
                            <div class="col-md-2"><input type="date" id="npFeNac" class="form-control" title="<?php echo $en?'Birth date *':'Fecha de nacimiento *'; ?>"></div>
                            <div class="col-md-2"><select id="npSex" class="form-select"><option value="Male"><?php echo $en?'Male':'Masculino'; ?></option><option value="Fame"><?php echo $en?'Female':'Femenino'; ?></option></select></div>
                            <div class="col-md-3"><button type="button" class="btn btn-primary w-100" id="npCrear"><i class="bi bi-check2"></i> <?php echo $en?'Create patient':'Crear paciente'; ?></button></div>
                        </div>
                        <div class="form-text"><?php echo $en?'* required. You can complete the rest in Manage Patients.':'* obligatorios. El resto se completa luego en Gestionar Pacientes.'; ?></div>
                    </div>
                </div>
                <div id="hsMsgPac" class="small mt-2"></div>
            </div></div>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <h6 class="mb-2"><?php echo $en?'2. Files':'2. Archivos'; ?></h6>
                <div class="row g-2 align-items-end">
                    <div class="col-md-5"><label class="form-label small mb-1"><?php echo $en?'Scanned file(s) — PDF, JPG, PNG (max. 25 MB each)':'Archivo(s) escaneado(s) — PDF, JPG, PNG (máx. 25 MB c/u)'; ?></label>
                        <input type="file" id="hsArchivos" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple></div>
                    <div class="col-md-4"><label class="form-label small mb-1"><?php echo $en?'Title':'Título'; ?></label>
                        <input id="hsTitulo" class="form-control" maxlength="200" value="<?php echo $en?'Paper medical record':'Historia clínica física'; ?>"></div>
                    <div class="col-md-3"><label class="form-label small mb-1"><?php echo $en?'Record date (optional)':'Fecha de la historia (opcional)'; ?></label>
                        <input type="date" id="hsFecha" class="form-control"></div>
                </div>
                <div class="mt-3"><button type="button" class="btn btn-success" id="hsSubir" disabled><i class="bi bi-upload"></i> <?php echo $en?'Upload':'Subir historia'; ?></button>
                    <span class="small text-muted ms-2" id="hsAyuda"><?php echo $en?'Select a patient first.':'Primero selecciona un paciente.'; ?></span></div>
                <ul id="hsProgreso" class="list-group mt-3"></ul>
            </div></div>

            <h6 class="mt-4"><?php echo $en?'Recently uploaded':'Subidas recientemente'; ?></h6>
            <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th><?php echo $en?'Uploaded':'Subida'; ?></th><th><?php echo $en?'Patient':'Paciente'; ?></th><th><?php echo $en?'Title':'Título'; ?></th><th><?php echo $en?'Record date':'Fecha historia'; ?></th><th><?php echo $en?'By':'Por'; ?></th><th></th></tr></thead><tbody>
                <?php foreach ($recientes as $r): ?><tr>
                    <td><?php echo h(date('m/d/Y H:i', strtotime($r['fecha']))); ?></td>
                    <td><b><?php echo h($r['PACIENTE']); ?></b> <span class="small text-muted"><?php echo h($r['CEDULA']); ?></span></td>
                    <td><?php echo h($r['titulo'] ?: $r['nombre_original']); ?></td>
                    <td><?php echo $r['fecha_doc'] ? h(date('m/d/Y', strtotime($r['fecha_doc']))) : '—'; ?></td>
                    <td><?php echo h($r['USUARIO']); ?></td>
                    <td class="text-end"><?php if ($puedeVer): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="paciente_archivo_ver.php?id=<?php echo (int)$r['id']; ?>"><i class="bi bi-eye"></i></a><?php endif; ?></td></tr>
                <?php endforeach; if (!$recientes): ?><tr><td colspan="6" class="text-center text-muted py-3"><?php echo $en?'Nothing uploaded yet.':'Aún no hay historias subidas.'; ?></td></tr><?php endif; ?>
                </tbody></table></div></div>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    var en = <?php echo json_encode($en); ?>;
    var $ = function(id){ return document.getElementById(id); };
    var idPac = 0, t = null;
    function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
    function refrescar(){
        $('hsSubir').disabled = !(idPac > 0);
        $('hsAyuda').textContent = idPac > 0 ? '' : (en?'Select a patient first.':'Primero selecciona un paciente.');
    }
    function elegir(id, nombre){
        idPac = id; $('hsSelNombre').textContent = nombre; $('hsSel').classList.remove('d-none');
        $('hsBusca').classList.add('d-none'); $('hsRes').innerHTML = ''; $('hsNuevoWrap').classList.add('d-none'); $('hsMsgPac').textContent = ''; refrescar();
    }
    $('hsQuitar').onclick = function(){ idPac = 0; $('hsSel').classList.add('d-none'); $('hsBusca').classList.remove('d-none'); $('hsBusca').value=''; $('hsNuevoWrap').classList.remove('d-none'); refrescar(); };
    $('hsBusca').addEventListener('input', function(){
        clearTimeout(t); var v = this.value.trim();
        if (v.length < 2) { $('hsRes').innerHTML = ''; return; }
        t = setTimeout(function(){
            fetch('buscar_pacientes.php?q='+encodeURIComponent(v), {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(a){
                $('hsRes').innerHTML = '';
                a.forEach(function(p){
                    var b = document.createElement('button'); b.type='button'; b.className='list-group-item list-group-item-action';
                    b.innerHTML = '<b>'+esc(p.nombre)+'</b> <span class="text-muted small">'+esc(p.cedula||'')+' '+esc(p.telefono||'')+'</span>';
                    b.onclick = function(){ elegir(p.id, p.nombre); };
                    $('hsRes').appendChild(b);
                });
                if (!a.length) $('hsRes').innerHTML = '<div class="small text-muted px-1">'+(en?'No matches.':'Sin coincidencias.')+'</div>';
            });
        }, 250);
    });
    $('hsToggleNuevo').onclick = function(){ $('hsNuevo').classList.toggle('d-none'); };
    $('npCrear').onclick = function(){
        var fd = new FormData();
        fd.append('nombres', $('npNombres').value); fd.append('apellidos', $('npApellidos').value); fd.append('cedula', $('npCedula').value);
        fd.append('telefono', $('npTelefono').value); fd.append('feNac', $('npFeNac').value); fd.append('sex', $('npSex').value);
        var m = $('hsMsgPac'); m.className = 'small mt-2 text-muted'; m.textContent = '…';
        fetch('paciente_crear_rapido.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
            if (j.ok) { elegir(j.id, ($('npNombres').value+' '+$('npApellidos').value).trim()); return; }
            m.className = 'small mt-2 text-danger';
            if (j.error === 'DUPLICADO') m.textContent = en ? 'A patient with that ID or phone already exists — search for them above.' : 'Ya existe un paciente con esa cédula o teléfono: búscalo arriba.';
            else if (j.error === 'DATOS_INCOMPLETOS') m.textContent = en ? 'First name, last name and birth date are required.' : 'Nombres, apellidos y fecha de nacimiento son obligatorios.';
            else m.textContent = en ? 'Could not create the patient.' : 'No se pudo crear el paciente.';
        });
    };
    $('hsSubir').onclick = function(){
        var files = $('hsArchivos').files; if (!idPac || !files.length) { if (!files.length) alert(en?'Select at least one file.':'Selecciona al menos un archivo.'); return; }
        var btn = this; btn.disabled = true; var ul = $('hsProgreso'); ul.innerHTML = ''; var pendientes = files.length, ok = 0;
        Array.prototype.forEach.call(files, function(f, i){
            var li = document.createElement('li'); li.className = 'list-group-item d-flex justify-content-between'; li.innerHTML = '<span>'+esc(f.name)+'</span><span class="text-muted">…</span>'; ul.appendChild(li);
            var fd = new FormData(); fd.append('idPaciente', idPac); fd.append('archivo', f); fd.append('tipo', 'historia_fisica');
            var titulo = $('hsTitulo').value.trim(); if (titulo && files.length > 1) titulo += ' ('+(i+1)+'/'+files.length+')';
            fd.append('titulo', titulo); fd.append('fecha_doc', $('hsFecha').value);
            fetch('paciente_archivo_subir.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
                var s = li.lastChild; if (j.ok) { ok++; s.className = 'text-success'; s.textContent = en?'Uploaded':'Subido'; } else { s.className = 'text-danger'; s.textContent = j.error || 'Error'; }
            }).catch(function(){ li.lastChild.className='text-danger'; li.lastChild.textContent = en?'Connection error':'Error de conexión'; })
            .then(function(){ if (--pendientes === 0) { btn.disabled = false; if (ok === files.length) { $('hsArchivos').value=''; setTimeout(function(){ location.reload(); }, 1200); } } });
        });
    };
    refrescar();
})();
</script>
</body>
</html>
<?php ob_end_flush(); ?>
