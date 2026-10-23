<?php
/**
 * class/permisos.php — Permisos por usuario (override sobre el rol).
 *
 * Cada módulo tiene un permiso por defecto según el rol. La tabla
 * usuario_permisos permite, por PERSONA, permitir o bloquear un módulo
 * puntual, sobreescribiendo el default del rol.
 *
 * Uso:
 *   require_once("class/permisos.php");
 *   if (puede('fact.crear')) { ... }        // en menús / vistas
 *   requerir('fact.crear');                  // al inicio de una página protegida
 *
 * Requiere sesión iniciada ($_SESSION['rol'], $_SESSION['iduser']).
 * SISTEMA (Admin) siempre tiene acceso (no puede bloquearse a sí mismo).
 */
require_once(__DIR__ . '/conexionBD.php');
require_once(__DIR__ . '/modulos.php');   // feature flags por instalación

/** Catálogo de módulos gestionables: key => [etiqueta, grupo, defaults por rol]. */
function permisos_catalogo() {
    return [
        // Agenda
        'agenda.pendientes'    => ['Citas pendientes',              'Agenda',        ['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>1,'ASISTENTE'=>1]],
        'agenda.atendidas'     => ['Citas atendidas (historial)',   'Agenda',        ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'agenda.weightplanner' => ['Planificador de peso',          'Agenda',        ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0]],
        'agenda.notificacion'  => ['Enviar notificación (correo)',  'Agenda',        ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'agenda.ausencias'     => ['Vacaciones / no disponibilidad','Agenda',        ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'agenda.revision'      => ['Revisión de citas por fecha',    'Agenda',        ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        // Triaje
        'triaje.registrar'     => ['Triaje (registrar signos vitales)', 'Triaje',    ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1,'ENFERMERA'=>1]],
        // Registro de atención (formato clínica)
        'atencion.registro'    => ['Registro de atención (editar)',  'Atención', ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0,'ENFERMERA'=>0]],
        'atencion.ver_registro'=> ['Hoja del paciente (ver / imprimir)', 'Atención', ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1,'ENFERMERA'=>1]],
        // Derivación / sueros
        'derivar.crear'        => ['Derivar paciente (profesional / sueros)', 'Derivación', ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0,'ENFERMERA'=>0]],
        'sueros.aplicar'       => ['Sueros: ver cola y registrar aplicación',  'Derivación', ['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>0,'ASISTENTE'=>0,'ENFERMERA'=>1]],
        // Pacientes
        'pac.eliminados'       => ['Pacientes eliminados',          'Pacientes',     ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0]],
        'pac.fusionar'         => ['Fusionar duplicados',           'Pacientes',     ['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>0,'ASISTENTE'=>0]],
        'pac.documentos'       => ['Documentos (plantillas)',       'Pacientes',     ['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>0,'ASISTENTE'=>0]],
        'pac.docenviados'      => ['Documentos enviados',           'Pacientes',     ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'pac.historias'        => ['Historias escaneadas (subir PDF, crear paciente)','Pacientes',['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1,'ENFERMERA'=>1]],
        'pac.archivos'         => ['Documentos del paciente (PDF)',  'Pacientes',     ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        // Configuración / catálogos
        'cfg.crear_doctor'     => ['Crear doctor',                  'Configuración', ['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>0,'ASISTENTE'=>0]],
        'cfg.icd10'            => ['Catálogo ICD-10',               'Configuración', ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'cfg.tiposconsulta'    => ['Tipos de consulta',             'Configuración', ['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>0,'ASISTENTE'=>0]],
        'cfg.ncp'              => ['Diagnósticos NCP/PES (catálogo)','Configuración', ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'cfg.tiposseguro'      => ['Tipos de seguro',               'Configuración', ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        // Facturación
        'fact.crear'           => ['Crear factura',                 'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'fact.cuentas'         => ['Cuentas por cobrar (ver)',      'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'fact.pagos'           => ['Registrar / anular pagos',      'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'fact.billitems'       => ['Bill Items (catálogo)',         'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'fact.registerbills'   => ['Registrar Bills (anterior)',    'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'fact.kalix'           => ['Importar facturas (Kalix)',     'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>0,'ASISTENTE'=>0]],
        'fact.reportes'        => ['Reportes de facturación',       'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        'fact.aseguradoras'    => ['Aseguradoras (gestionar)',      'Facturación',   ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>1]],
        // Reportes / herramientas
        'rep.plantillas'       => ['Plantillas de informe',         'Reportes',      ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0]],
        'rep.calculadora'      => ['Calculadora nutricional',       'Reportes',      ['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0]],
        // Panel de control
        'panel.usuarios'       => ['Usuarios (crear / listar)',     'Panel de control',['SISTEMA'=>1,'DOCTOR'=>0,'USUARIO'=>0,'ASISTENTE'=>0]],
        'panel.auditoria'      => ['Bitácora de cambios (auditoría)','Panel de control',['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0]],
        'panel.tickets'        => ['Solicitudes/Tickets (gestionar todos)','Panel de control',['SISTEMA'=>1,'DOCTOR'=>1,'USUARIO'=>0,'ASISTENTE'=>0]],
    ];
}

function _permisos_conn() {
    static $c = null;
    if ($c === null) { $c = conectarse(); if ($c) { @$c->set_charset('utf8mb4'); } }
    return $c;
}

/** Overrides guardados para un usuario: [modulo => 0|1]. Cacheado por request. */
function permisos_overrides($idUser) {
    static $cache = [];
    $idUser = (int)$idUser;
    if (isset($cache[$idUser])) return $cache[$idUser];
    $ov = [];
    $c = _permisos_conn();
    if ($c) {
        $dbRow = $c->query("SELECT DATABASE() AS db");
        $db = $dbRow ? $dbRow->fetch_assoc()['db'] : '';
        $existe = $db ? (int)$c->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA='".$c->real_escape_string($db)."' AND TABLE_NAME='usuario_permisos'")->fetch_assoc()['c'] : 0;
        if ($existe) {
            if ($st = $c->prepare("SELECT modulo, permitido FROM usuario_permisos WHERE IDADM_USUARIO=?")) {
                $st->bind_param('i', $idUser); $st->execute();
                $rs = $st->get_result();
                while ($r = $rs->fetch_assoc()) $ov[$r['modulo']] = (int)$r['permitido'];
                $st->close();
            }
        }
    }
    return $cache[$idUser] = $ov;
}

/** Permiso efectivo para un usuario+rol sobre un módulo. */
function puede_usuario($idUser, $rol, $key) {
    $rol = strtoupper((string)$rol);
    // Módulo apagado en esta instalación (class/modulos.php): bloquea a todos, SISTEMA incluido.
    $mod = modulo_de_permiso($key);
    if ($mod !== null && !modulo_activo($mod)) return false;
    if ($rol === 'SISTEMA') return true;   // Admin siempre (no puede quedarse sin acceso)
    $cat = permisos_catalogo();
    if (!isset($cat[$key])) return false;
    $ov = permisos_overrides($idUser);
    if (array_key_exists($key, $ov)) return ((int)$ov[$key] === 1);
    return ((int)($cat[$key][2][$rol] ?? 0) === 1);
}

/** Atajo con la sesión actual. */
function puede($key) {
    if (!isset($_SESSION['rol'])) return false;
    return puede_usuario($_SESSION['iduser'] ?? 0, $_SESSION['rol'], $key);
}

/** Exige el permiso o redirige. Úsese al inicio de una página protegida. */
function requerir($key, $redir = 'break.php') {
    if (!puede($key)) { header('Location: ' . $redir); exit(); }
}
