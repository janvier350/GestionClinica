<?php
require_once(__DIR__ . '/../lang/i18n.php');
require_once(__DIR__ . '/../class/permisos.php');

$rol       = $_SESSION['rol'] ?? '';
$esSistema   = ($rol === 'SISTEMA');
$esDoctor    = ($rol === 'DOCTOR');
$esUsuario   = ($rol === 'USUARIO');
$esAsistente = ($rol === 'ASISTENTE');

$paginaActual = basename($_SERVER['PHP_SELF'] ?? '');
function menuActivo($paginas, $actual) {
    $paginas = is_array($paginas) ? $paginas : [$paginas];
    return in_array($actual, $paginas) ? 'mm-active' : '';
}

// URL actual para regresar tras cambiar de idioma
$lang      = current_lang();
$redirLang = $_SERVER['REQUEST_URI'] ?? 'home.php';
?>
<style>
    /* Corrige sidebar sin scroll: garantiza que todos los ítems sean alcanzables */
    .app-sidebar       { height: 100vh !important; display: flex !important; flex-direction: column !important; }
    .scrollbar-sidebar  { flex: 1 1 auto; min-height: 0; overflow-y: auto !important; overflow-x: hidden !important; }
    .app-sidebar__inner { padding-bottom: 12px; }

    /* El pie ahora es el último <li> de la lista: siempre queda al final,
       nunca "sube" aunque el contenedor con scroll colapse. */
    .sidebar-footer-li { list-style: none; margin-top: 8px; }
    .sidebar-logout-footer {
        border-top: 1px solid rgba(0,0,0,.08);
        padding: 12px 14px 16px;
    }
    .sidebar-logout-footer a.logout-link {
        display: flex; align-items: center; gap: 8px;
        color: #dc3545; font-size: .85rem; font-weight: 600;
        text-decoration: none; padding: 7px 10px; border-radius: 6px;
        transition: background .15s;
    }
    .sidebar-logout-footer a.logout-link:hover { background: rgba(220,53,69,.08); }

    .sidebar-lang { display:flex; align-items:center; gap:6px; margin-bottom:8px; font-size:.8rem; }
    .sidebar-lang .lang-label { color:#6c757d; font-weight:600; }
    .sidebar-lang a {
        text-decoration:none; color:#0d6efd; padding:2px 8px; border-radius:6px; font-weight:600;
        border:1px solid transparent;
    }
    .sidebar-lang a:hover { background:rgba(13,110,253,.08); }
    .sidebar-lang a.active { background:#0d6efd; color:#fff; }

    /* ── Buscador de pacientes en el sidebar (estilo Kalix) ── */
    .sb-search { padding: 12px 12px 6px; border-bottom: 1px solid rgba(0,0,0,.06); position: relative; }
    .sb-search .input-group-text { background:#fff; border-right:0; padding-right:0; }
    .sb-search .form-control { border-left:0; padding-left:6px; }
    .sb-search .form-control:focus { box-shadow: none; border-color: #dee2e6; }
    .sb-search-results {
        position:absolute; top:100%; left:12px; right:12px; z-index:1055;
        background:#fff; border:1px solid #e6e9f0; border-radius:10px;
        max-height:340px; overflow-y:auto; margin-top:4px;
        box-shadow: 0 6px 18px rgba(16,31,85,.08);
    }
    .sb-search-results .sbs-item { padding:8px 10px; cursor:pointer; border-bottom:1px solid #f1f3f8; }
    .sb-search-results .sbs-item:last-child { border-bottom:0; }
    .sb-search-results .sbs-item:hover, .sb-search-results .sbs-item.active { background:#f0f4ff; }
    .sb-search-results .sbs-name { font-weight:600; color:#23324d; font-size:.86rem; }
    .sb-search-results .sbs-meta { font-size:.72rem; color:#6c757d; }
    .sb-search-results .sbs-empty { padding:10px; color:#6c757d; font-size:.82rem; }
</style>

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

<!-- ── Buscador rápido de pacientes ─────────────────────────────── -->
<div class="sb-search">
    <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="sbPacienteBuscar" class="form-control" placeholder="<?php te('cal.searchPatientTop'); ?>" autocomplete="off">
    </div>
    <div id="sbPacienteResultados" class="sb-search-results d-none"></div>
</div>

<div class="scrollbar-sidebar">
    <div class="app-sidebar__inner">
        <ul class="vertical-nav-menu">

            <!-- ══ DASHBOARD ══════════════════════════════════════════ -->
            <li class="app-sidebar__heading"><?php te('menu.heading.dashboard'); ?></li>
            <li>
                <a href="home.php" class="<?php echo menuActivo('home.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-house-door"></i> <?php te('menu.home'); ?>
                </a>
            </li>
            <li>
                <a href="perfil.php" class="<?php echo menuActivo('perfil.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-person-circle"></i> <?php te('menu.myProfile'); ?>
                </a>
            </li>

            <!-- ══ AGENDA ══════════════════════════════════════════════ -->
            <li class="app-sidebar__heading"><?php te('menu.heading.schedule'); ?></li>
            <li>
                <a href="SCH_Calendar.php" class="<?php echo menuActivo('SCH_Calendar.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-calendar3"></i> <?php te('menu.calendar'); ?>
                </a>
            </li>
            <li>
                <a href="gestionar_colores_estado.php" class="<?php echo menuActivo('gestionar_colores_estado.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-palette2"></i> <?php te('menu.statusColors'); ?>
                </a>
            </li>
            <li>
                <a href="citas_canceladas.php" class="<?php echo menuActivo('citas_canceladas.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-calendar-x"></i> <?php te('menu.cancelled'); ?>
                </a>
            </li>
            <?php if (puede('agenda.atendidas')): ?>
            <li>
                <a href="historial_atenciones.php" class="<?php echo menuActivo('historial_atenciones.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-calendar-check"></i> <?php te('menu.attended'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('agenda.weightplanner')): ?>
            <li>
                <a href="body_weight_planner.php" class="<?php echo menuActivo('body_weight_planner.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-graph-down-arrow"></i> <?php te('menu.weightPlanner'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('agenda.notificacion')): ?>
            <li>
                <a href="Enviar_Notificacion.php" class="<?php echo menuActivo('Enviar_Notificacion.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-envelope"></i> <?php te('menu.sendNotification'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('agenda.ausencias')): ?>
            <li>
                <a href="ausencias_doctor.php" class="<?php echo menuActivo('ausencias_doctor.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-airplane"></i> <?php te('menu.ausencias'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('agenda.revision')): ?>
            <li>
                <a href="citas_por_fecha.php" class="<?php echo menuActivo('citas_por_fecha.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-calendar2-week"></i> <?php te('menu.citasRevision'); ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- ══ PACIENTES ═══════════════════════════════════════════ -->
            <li class="app-sidebar__heading"><?php te('menu.heading.patients'); ?></li>
            <li>
                <a href="#">
                    <i class="metismenu-icon bi bi-person-plus"></i>
                    <?php te('menu.patients'); ?>
                    <i class="metismenu-state-icon bi bi-chevron-down caret-left"></i>
                </a>
                <ul>
                    <li>
                        <a href="registrar_paciente_seguro.php" class="<?php echo menuActivo('registrar_paciente_seguro.php', $paginaActual); ?>">
                            <i class="metismenu-icon"></i> <?php te('menu.registerPatientInsurance'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="pacientes_crud.php" class="<?php echo menuActivo('pacientes_crud.php', $paginaActual); ?>">
                            <i class="metismenu-icon"></i> <?php te('menu.patientsCrud'); ?>
                        </a>
                    </li>
                    <?php if (puede('pac.eliminados')): ?>
                    <li>
                        <a href="pacientes_eliminados.php" class="<?php echo menuActivo('pacientes_eliminados.php', $paginaActual); ?>">
                            <i class="metismenu-icon"></i> <?php te('menu.patientsDeleted'); ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (puede('pac.fusionar')): ?>
                    <li>
                        <a href="fusionar_pacientes.php" class="<?php echo menuActivo('fusionar_pacientes.php', $paginaActual); ?>">
                            <i class="metismenu-icon"></i> <?php te('menu.mergePatients'); ?>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </li>
            <?php if (puede('pac.documentos')): ?>
            <li>
                <a href="gestionar_documentos.php" class="<?php echo menuActivo('gestionar_documentos.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-file-earmark-text"></i> <?php te('menu.documents'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('pac.docenviados')): ?>
            <li>
                <a href="documentos_enviados.php" class="<?php echo menuActivo('documentos_enviados.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-send-check"></i> <?php te('menu.sentDocuments'); ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- ══ Catálogos / configuración ═══════════════════════════ -->
            <?php if (puede('cfg.icd10')): ?>
            <li>
                <a href="#">
                    <i class="metismenu-icon bi bi-file-earmark-text"></i>
                    <?php te('menu.cie10'); ?>
                    <i class="metismenu-state-icon bi bi-chevron-down caret-left"></i>
                </a>
                <ul>
                    <li>
                        <a href="PNC_CIE-10Crear.php">
                            <i class="metismenu-icon"></i> <?php te('menu.createCie10'); ?>
                        </a>
                    </li>
                </ul>
            </li>
            <?php endif; ?>
            <?php if (puede('cfg.tiposconsulta')): ?>
            <li>
                <a href="gestionar_tipos_consulta.php" class="<?php echo menuActivo('gestionar_tipos_consulta.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-palette"></i> <?php te('menu.consultTypes'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('cfg.ncp')): ?>
            <li>
                <a href="ncp_diagnosticos_admin.php" class="<?php echo menuActivo('ncp_diagnosticos_admin.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-clipboard2-pulse"></i> <?php te('menu.ncpCatalog'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('cfg.tiposseguro')): ?>
            <li>
                <a href="gestionar_tipos_seguro.php" class="<?php echo menuActivo('gestionar_tipos_seguro.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-bookmark-star"></i> <?php te('menu.insuranceTypes'); ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- ══ BILLS (por permisos por usuario) ════════════════════ -->
            <?php $verBilling = puede('fact.crear') || puede('fact.cuentas') || puede('fact.billitems') || puede('fact.kalix') || puede('fact.aseguradoras'); ?>
            <?php if ($verBilling): ?>
            <li class="app-sidebar__heading"><?php te('menu.heading.billing'); ?></li>
            <li>
                <a href="#">
                    <i class="metismenu-icon bi bi-receipt"></i>
                    <?php te('menu.register'); ?>
                    <i class="metismenu-state-icon bi bi-chevron-down caret-left"></i>
                </a>
                <ul>
                    <?php if (puede('fact.billitems')): ?>
                    <li>
                        <a href="bill_items_admin.php" class="<?php echo menuActivo('bill_items_admin.php', $paginaActual); ?>">
                            <i class="metismenu-icon"></i> Bill Items
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (puede('fact.crear')): ?>
                    <li>
                        <a href="crear_factura.php" class="<?php echo menuActivo('crear_factura.php', $paginaActual); ?>">
                            <i class="metismenu-icon"></i> <?php te('menu.createInvoice'); ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (puede('fact.cuentas')): ?>
                    <li>
                        <a href="cuentas_por_cobrar.php" class="<?php echo menuActivo(['cuentas_por_cobrar.php','factura_ver.php'], $paginaActual); ?>">
                            <i class="metismenu-icon"></i> <?php te('menu.accountsReceivable'); ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (puede('fact.kalix')): ?>
                    <li>
                        <a href="importar_facturas_kalix.php" class="<?php echo menuActivo('importar_facturas_kalix.php', $paginaActual); ?>">
                            <i class="metismenu-icon"></i> <?php te('menu.importKalix'); ?>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </li>
            <?php if (puede('fact.aseguradoras')): ?>
            <li>
                <a href="gestionar_seguros.php" class="<?php echo menuActivo('gestionar_seguros.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-shield-check"></i> <?php te('menu.insurance'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ══ REPORTES ════════════════════════════════════════════ -->
            <?php if (puede('rep.plantillas') || puede('rep.calculadora')): ?>
            <li class="app-sidebar__heading"><?php te('menu.heading.reports'); ?></li>
            <?php if (puede('rep.plantillas')): ?>
            <li>
                <a href="plantillas_admin.php" class="<?php echo menuActivo('plantillas_admin.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-file-earmark-text"></i> <?php te('menu.templates'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (puede('rep.calculadora')): ?>
            <li>
                <a href="calculadora_nutricional.php" class="<?php echo menuActivo('calculadora_nutricional.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-calculator"></i> <?php te('menu.nutricalc'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ══ PANEL DE CONTROL (solo SISTEMA) ═══════════════════ -->
            <?php if (puede('panel.usuarios')): ?>
            <li class="app-sidebar__heading"><?php te('menu.heading.controlPanel'); ?></li>
            <li>
                <a href="#">
                    <i class="metismenu-icon bi bi-people"></i>
                    <?php te('menu.users'); ?>
                    <i class="metismenu-state-icon bi bi-chevron-down caret-left"></i>
                </a>
                <ul>
                    <li>
                        <a href="PNC_UsuarioCrear.php">
                            <i class="metismenu-icon"></i> <?php te('menu.createNew'); ?>
                        </a>
                    </li>
                </ul>
            </li>
            <?php if ($esSistema): ?>
            <li>
                <a href="permisos_usuarios.php" class="<?php echo menuActivo('permisos_usuarios.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-shield-lock"></i> <?php te('menu.userPermissions'); ?>
                </a>
            </li>
            <li>
                <a href="zona_horaria.php" class="<?php echo menuActivo('zona_horaria.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-clock-history"></i> <?php echo (current_lang()==='en'?'Time zone':'Zona horaria'); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ══ AUDITORÍA (SISTEMA/Dra. Silvia o a quien se otorgue) ══ -->
            <?php if (puede('panel.auditoria')): ?>
            <?php if (!puede('panel.usuarios')): ?><li class="app-sidebar__heading"><?php te('menu.heading.controlPanel'); ?></li><?php endif; ?>
            <li>
                <a href="auditoria.php" class="<?php echo menuActivo('auditoria.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-journal-text"></i> <?php te('menu.auditlog'); ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- ══ SOLICITUDES / TICKETS (todos los roles) ═══════════ -->
            <li class="app-sidebar__heading"><?php echo (current_lang()==='en'?'Support':'Soporte'); ?></li>
            <li>
                <a href="tickets.php" class="<?php echo menuActivo('tickets.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-ticket-detailed"></i> <?php echo (current_lang()==='en'?'Requests / Tickets':'Solicitudes / Tickets'); ?>
                </a>
            </li>

            <!-- ══ AYUDA (todos los roles) ═══════════════════════════ -->
            <li class="app-sidebar__heading"><?php te('help.centerTitle'); ?></li>
            <li>
                <a href="ayuda.php" class="<?php echo menuActivo('ayuda.php', $paginaActual); ?>">
                    <i class="metismenu-icon bi bi-question-circle"></i> <?php te('menu.help'); ?>
                </a>
            </li>

            <!-- ══ PIE: idioma + cerrar sesión (dentro de la lista, siempre al final) ══ -->
            <li class="sidebar-footer-li">
                <div class="sidebar-logout-footer">
                    <div class="sidebar-lang">
                        <span class="lang-label"><i class="bi bi-translate"></i> <?php te('lang.language'); ?>:</span>
                        <a href="set_lang.php?lang=en&redir=<?php echo urlencode($redirLang); ?>" class="<?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
                        <a href="set_lang.php?lang=es&redir=<?php echo urlencode($redirLang); ?>" class="<?php echo $lang === 'es' ? 'active' : ''; ?>">ES</a>
                    </div>
                    <a href="salir.php" class="logout-link">
                        <i class="metismenu-icon bi bi-power"></i> <?php te('menu.logout'); ?>
                    </a>
                </div>
            </li>

        </ul>
    </div>
</div>

<script>
// ── Buscador de pacientes del sidebar → historial del paciente ──
(function(){
    var input = document.getElementById('sbPacienteBuscar');
    var box   = document.getElementById('sbPacienteResultados');
    if (!input || !box) return;
    var CAL_SEARCH_NONE = <?php echo json_encode(t('cal.searchNone')); ?>;
    var timer = null, items = [], activeIdx = -1;
    function hide(){ box.classList.add('d-none'); box.innerHTML=''; items=[]; activeIdx=-1; }
    function irA(id, nombre){
        // Si hay modal de detalle del paciente disponible, abrirlo; si no, navegar.
        if (typeof window.mnuVerHistorialPaciente === 'function') {
            window.mnuVerHistorialPaciente(id, nombre || '');
        } else {
            window.location.href = 'historial_atenciones.php?id=' + encodeURIComponent(id);
        }
    }
    function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function render(data){
        items = Array.isArray(data) ? data : [];
        if (!items.length) {
            box.innerHTML = '<div class="sbs-empty">' + escapeHtml(CAL_SEARCH_NONE) + '</div>';
            box.classList.remove('d-none'); return;
        }
        box.innerHTML = items.map(function(p){
            var meta = [p.cedula, p.telefono].filter(Boolean).join('  ·  ');
            return '<div class="sbs-item" data-id="' + p.id + '" data-name="' + escapeHtml(p.nombre) + '">'
                 + '<div class="sbs-name">' + escapeHtml(p.nombre) + '</div>'
                 + (meta ? '<div class="sbs-meta">' + escapeHtml(meta) + '</div>' : '')
                 + '</div>';
        }).join('');
        box.classList.remove('d-none');
        Array.prototype.forEach.call(box.querySelectorAll('.sbs-item'), function(el){
            el.addEventListener('mousedown', function(e){
                e.preventDefault();
                irA(el.getAttribute('data-id'), el.getAttribute('data-name'));
                hide();
                input.blur();
            });
        });
        activeIdx = -1;
    }
    input.addEventListener('input', function(){
        var q = input.value.trim();
        if (timer) clearTimeout(timer);
        if (q.length < 2) { hide(); return; }
        timer = setTimeout(function(){
            fetch('buscar_pacientes.php?q=' + encodeURIComponent(q))
                .then(function(r){ return r.json(); }).then(render).catch(hide);
        }, 220);
    });
    input.addEventListener('keydown', function(e){
        if (box.classList.contains('d-none')) return;
        var els = box.querySelectorAll('.sbs-item');
        if (!els.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx+1, els.length-1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx-1, 0); }
        else if (e.key === 'Enter') { if (activeIdx >= 0 && items[activeIdx]) { e.preventDefault(); irA(items[activeIdx].id, items[activeIdx].nombre); hide(); input.blur(); } return; }
        else if (e.key === 'Escape') { hide(); return; }
        else return;
        Array.prototype.forEach.call(els, function(el,i){ el.classList.toggle('active', i===activeIdx); });
        if (els[activeIdx]) els[activeIdx].scrollIntoView({block:'nearest'});
    });
    document.addEventListener('click', function(e){ if (!e.target.closest('.sb-search')) hide(); });
})();
</script>

<!-- ══ MODAL HISTORIAL (compartido, abierto por el buscador del sidebar) ══ -->
<div class="modal fade" id="mnuModalHistorial" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#1a1a2e;">
                <h6 class="modal-title text-white mb-0">
                    <i class="bi bi-person-lines-fill me-2"></i>
                    <span id="mnuModalPacienteNombre"></span>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php te('common.close'); ?>"></button>
            </div>
            <div class="modal-body p-0" id="mnuModalHistorialBody">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border spinner-border-sm me-2"></div> <?php te('common.loading'); ?>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL INFORME (compartido) ══════════════════════════════════════ -->
<div class="modal fade" id="mnuModalInforme" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i><?php te('plist.reportTitle'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="mnuCuerpoInforme">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
            <div class="modal-footer py-2">
                <button id="mnuBtnEditarInforme" type="button" class="btn btn-outline-warning btn-sm" onclick="mnuEditarInforme()">
                    <i class="bi bi-pencil-square"></i> <?php te('common.edit'); ?>
                </button>
                <button id="mnuBtnGuardarInforme" type="button" class="btn btn-success btn-sm d-none" onclick="mnuGuardarInformeEditado()">
                    <i class="bi bi-check-lg"></i> <?php te('common.saveChanges'); ?>
                </button>
                <button id="mnuBtnCancelarInforme" type="button" class="btn btn-outline-secondary btn-sm d-none" onclick="mnuCancelarEdicionInforme()">
                    <?php te('plist.reportCancel'); ?>
                </button>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> <?php te('common.print'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
// Textos i18n para los modales compartidos del sidebar
(function(){
    if (window.MNU_TXT) return;
    window.MNU_TXT = {
        loading:       <?php echo json_encode(t('common.loading')); ?>,
        loadError:     <?php echo json_encode(t('plist.js.loadError')); ?>,
        loadHttp:      <?php echo json_encode(t('plist.js.loadHttp')); ?>,
        historyErrPre: <?php echo json_encode(t('plist.js.historyErrPre')); ?>,
        historyErrTail:<?php echo json_encode(t('plist.js.historyErrTail')); ?>,
        reportSaved:   <?php echo json_encode(t('plist.js.reportSaved')); ?>,
        reportSaveErr: <?php echo json_encode(t('plist.js.reportSaveErr')); ?>,
        reportEmpty:   <?php echo json_encode(t('plist.js.reportEmpty')); ?>,
        confirmCancelEdit:<?php echo json_encode(t('plist.js.confirmCancelEdit')); ?>,
        connError:     <?php echo json_encode(t('common.js.connError')); ?>
    };
})();

// Mueve los modales del sidebar al <body> para escapar del stacking
// context del sidebar (evita que el backdrop de Bootstrap tape el modal).
(function(){
    function mover(id){
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){
            mover('mnuModalHistorial'); mover('mnuModalInforme');
        });
    } else {
        mover('mnuModalHistorial'); mover('mnuModalInforme');
    }
})();

// Abre el modal de detalle del paciente (agenda, informes, documentos).
window.mnuVerHistorialPaciente = function (idPaciente, nombre) {
    var body = document.getElementById('mnuModalHistorialBody');
    document.getElementById('mnuModalPacienteNombre').textContent = nombre || '';
    if (body) {
        body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>' + MNU_TXT.loading + '</div>';
    }
    var m = new bootstrap.Modal(document.getElementById('mnuModalHistorial'));
    m.show();
    if (window.jQuery) {
        jQuery.get('get_historial_paciente.php', { id: idPaciente })
            .done(function(html){ body.innerHTML = html; })
            .fail(function(xhr){ body.innerHTML = '<div class="alert alert-danger m-3">' + MNU_TXT.historyErrPre + xhr.status + MNU_TXT.historyErrTail + '</div>'; });
    } else {
        fetch('get_historial_paciente.php?id=' + encodeURIComponent(idPaciente))
            .then(function(r){ return r.text(); })
            .then(function(html){ body.innerHTML = html; })
            .catch(function(){ body.innerHTML = '<div class="alert alert-danger m-3">' + MNU_TXT.historyErrPre + '?' + MNU_TXT.historyErrTail + '</div>'; });
    }
};

// ── Seguros del paciente (globales, para usarlos desde el modal del sidebar) ──
window.mnuCargarSegurosPaciente = function (idPaciente) {
    var cont = document.getElementById('hpLista');
    if (!cont) return;
    cont.innerHTML = '<div class="text-muted small">' + MNU_TXT.loading + '</div>';
    fetch('seguro_paciente_listar.php?id_paciente=' + encodeURIComponent(idPaciente))
        .then(function(r){ return r.text(); })
        .then(function(html){ cont.innerHTML = html; })
        .catch(function(){ cont.innerHTML = '<div class="text-danger small">' + MNU_TXT.loadError + '</div>'; });
};
window.mnuAgregarSeguroPaciente = function (idPaciente) {
    var idSeguro  = (document.getElementById('hpSeguro')    || {}).value || '';
    var poliza    = (document.getElementById('hpPoliza')    || {}).value || '';
    var prioridad = (document.getElementById('hpPrioridad') || {}).value || 'Primario';
    if (!idPaciente || !idSeguro) return;
    fetch('seguro_paciente_guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id_paciente: idPaciente, id_seguro: idSeguro, num_poliza: poliza, prioridad: prioridad })
    }).then(function(r){ return r.text(); }).then(function(res){
        res = (res || '').trim();
        if (res === 'OK') {
            var s = document.getElementById('hpSeguro');    if (s) s.value = '';
            var p = document.getElementById('hpPoliza');    if (p) p.value = '';
            var pr = document.getElementById('hpPrioridad'); if (pr) pr.value = 'Primario';
            window.mnuCargarSegurosPaciente(idPaciente);
        } else if (res === 'DUP') {
            alert('Este seguro ya está registrado para el paciente.');
        } else {
            alert('No se pudo agregar el seguro: ' + res);
        }
    }).catch(function(){ alert(MNU_TXT.loadError); });
};
window.mnuEliminarSeguroPaciente = function (id, idPaciente) {
    if (!confirm('¿Quitar este seguro?')) return;
    fetch('seguro_paciente_eliminar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id: id })
    }).then(function(r){ return r.text(); }).then(function(res){
        if ((res || '').trim() === 'OK') window.mnuCargarSegurosPaciente(idPaciente);
        else alert('No se pudo eliminar: ' + res);
    }).catch(function(){ alert(MNU_TXT.loadError); });
};
window.mnuSubirImagenSeguro = function (input, id, lado, idPaciente) {
    if (!input.files || !input.files[0]) return;
    var fd = new FormData();
    fd.append('id_paciente_seguro', id);
    fd.append('lado', lado);
    fd.append('imagen', input.files[0]);
    fetch('seguro_paciente_subir_imagen.php', { method: 'POST', body: fd })
        .then(function(r){ return r.text(); })
        .then(function(res){
            if ((res || '').trim().startsWith('OK')) window.mnuCargarSegurosPaciente(idPaciente);
            else alert('No se pudo subir la imagen: ' + res);
        }).catch(function(){ alert(MNU_TXT.loadError); });
};

// Compat: el HTML de seguro_paciente_listar.php usa las funciones globales
// eliminarSeguroPaciente(id) y subirImagenSeguro(input,id,lado). Cuando abrimos
// el modal desde el sidebar, redirigimos a las versiones -mnu- pasando el id
// del paciente activo (leído del wrapper del modal).
(function(){
    function pacIdActivo() {
        var w = document.getElementById('hpSegurosWrap');
        return w ? parseInt(w.getAttribute('data-id-paciente') || '0', 10) : 0;
    }
    if (typeof window.eliminarSeguroPaciente !== 'function') {
        window.eliminarSeguroPaciente = function(id){
            var p = pacIdActivo(); if (!p) return;
            window.mnuEliminarSeguroPaciente(id, p);
        };
    }
    if (typeof window.subirImagenSeguro !== 'function') {
        window.subirImagenSeguro = function(input, id, lado){
            var p = pacIdActivo(); if (!p) return;
            window.mnuSubirImagenSeguro(input, id, lado, p);
        };
    }
})();

// ── Edición de informe (compartido, modal del sidebar) ──────────────
window._mnuInformeId = null;
window._mnuInformeOriginal = '';

function _mnuResetBotonesInforme(){
    var e = document.getElementById('mnuBtnEditarInforme');
    var g = document.getElementById('mnuBtnGuardarInforme');
    var c = document.getElementById('mnuBtnCancelarInforme');
    if (e) e.classList.remove('d-none');
    if (g) g.classList.add('d-none');
    if (c) c.classList.add('d-none');
    var body = document.getElementById('mnuCuerpoInforme');
    if (body) { body.contentEditable = 'false'; body.style.outline = ''; body.style.background = ''; }
}
window.mnuEditarInforme = function(){
    var body = document.getElementById('mnuCuerpoInforme');
    if (!body || !window._mnuInformeId) return;
    body.contentEditable = 'true';
    body.style.outline = '2px dashed #ffc107';
    body.style.background = '#fffdf5';
    body.focus();
    document.getElementById('mnuBtnEditarInforme').classList.add('d-none');
    document.getElementById('mnuBtnGuardarInforme').classList.remove('d-none');
    document.getElementById('mnuBtnCancelarInforme').classList.remove('d-none');
};
window.mnuCancelarEdicionInforme = function(){
    if (!confirm(MNU_TXT.confirmCancelEdit)) return;
    var body = document.getElementById('mnuCuerpoInforme');
    if (body) body.innerHTML = window._mnuInformeOriginal;
    _mnuResetBotonesInforme();
};
window.mnuGuardarInformeEditado = function(){
    var body = document.getElementById('mnuCuerpoInforme');
    if (!body || !window._mnuInformeId) return;
    var contenido = body.innerHTML;
    if (contenido.replace(/<[^>]+>/g,'').trim().length < 5) { alert(MNU_TXT.reportEmpty); return; }
    var btn = document.getElementById('mnuBtnGuardarInforme');
    if (btn) btn.disabled = true;
    fetch('actualizar_atencion.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ idHistorial: window._mnuInformeId, informe: contenido })
    }).then(function(r){ return r.text(); }).then(function(res){
        if (btn) btn.disabled = false;
        res = (res || '').trim();
        if (res === 'OK') {
            window._mnuInformeOriginal = contenido;
            _mnuResetBotonesInforme();
            alert(MNU_TXT.reportSaved);
        } else {
            alert(MNU_TXT.reportSaveErr + res);
        }
    }).catch(function(){ if (btn) btn.disabled = false; alert(MNU_TXT.connError); });
};

// Restablecer al cerrar el modal compartido
(function(){
    function armar(){
        var m = document.getElementById('mnuModalInforme');
        if (m) m.addEventListener('hidden.bs.modal', _mnuResetBotonesInforme);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', armar);
    else armar();
})();

// verInforme(id): abre el modal del informe (fallback global).
if (typeof window.verInforme !== 'function') {
    window.verInforme = function (idHistorial) {
        var body = document.getElementById('mnuCuerpoInforme');
        window._mnuInformeId = idHistorial;
        window._mnuInformeOriginal = '';
        _mnuResetBotonesInforme();
        if (body) body.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>';
        new bootstrap.Modal(document.getElementById('mnuModalInforme')).show();
        var done = function(html){ body.innerHTML = html; window._mnuInformeOriginal = html; };
        var fail = function(){ body.innerHTML = '<div class="alert alert-danger m-3">' + MNU_TXT.loadError + '</div>'; };
        if (window.jQuery) {
            jQuery.get('get_informe_html.php', { id: idHistorial }).done(done).fail(fail);
        } else {
            fetch('get_informe_html.php?id=' + encodeURIComponent(idHistorial))
                .then(function(r){ return r.text(); }).then(done).catch(fail);
        }
    };
}
</script>
