<?php
/**
 * class/modulos.php — Activador/desactivador de módulos por instalación.
 *
 * Cada módulo es un "feature flag" guardado en app_config (clave 'mod.<key>',
 * valor '1' o '0'). Sin fila guardada se usa el valor por defecto del catálogo,
 * así una instalación existente conserva todo lo que ya tenía activo.
 *
 * Un módulo agrupa permisos de class/permisos.php: si el módulo está apagado,
 * puede() devuelve false para esos permisos (incluso para SISTEMA), por lo que
 * los menús y los requerir() existentes se apagan sin tocar cada página.
 *
 * Uso:
 *   require_once("class/modulos.php");
 *   if (modulo_activo('tickets')) { ... }     // menús / vistas
 *   requerir_modulo('tickets');                // páginas que no usan permisos
 */
require_once(__DIR__ . '/conexionBD.php');

/**
 * Catálogo: key => [etiqueta ES, etiqueta EN, descripción ES, descripción EN,
 *                   default, disponible, [permisos que gobierna]].
 * 'disponible' = false: módulo reservado (aún no construido); no se puede activar.
 */
function modulos_catalogo() {
    return [
        'facturacion' => ['Facturación y cuentas por cobrar', 'Billing & accounts receivable',
            'Facturas, abonos/pagos, catálogo de items y reportes de cobro.',
            'Invoices, payments, bill items and receivables reports.',
            true, true, ['fact.crear','fact.cuentas','fact.pagos','fact.billitems','fact.registerbills','fact.kalix','fact.reportes']],
        'seguros' => ['Seguros / aseguradoras', 'Insurance',
            'Aseguradoras, tipos de seguro y seguros del paciente.',
            'Insurers, insurance types and patient insurance.',
            true, true, ['fact.aseguradoras','cfg.tiposseguro']],
        'documentos_paciente' => ['Documentos del paciente (PDF)', 'Patient documents (PDF)',
            'Subida y consulta segura de PDF por paciente (base de las historias escaneadas).',
            'Secure per-patient PDF upload and viewing (basis for scanned records).',
            true, true, ['pac.archivos','pac.historias']],
        'documentos_plantillas' => ['Plantillas y documentos para firmar', 'Document templates & signing',
            'Plantillas de documentos, envío y firma electrónica.',
            'Document templates, sending and e-signature.',
            true, true, ['pac.documentos','pac.docenviados']],
        'nutricion' => ['Herramientas de nutrición', 'Nutrition tools',
            'Calculadora nutricional, planificador de peso y diagnósticos NCP/PES.',
            'Nutrition calculator, weight planner and NCP/PES diagnoses.',
            true, true, ['rep.calculadora','agenda.weightplanner','cfg.ncp']],
        'plantillas_informe' => ['Plantillas de informe', 'Report templates',
            'Editor de plantillas de informe clínico.',
            'Clinical report template editor.',
            true, true, ['rep.plantillas']],
        'auditoria' => ['Bitácora de cambios (auditoría)', 'Audit log',
            'Consulta y exportación de la bitácora.',
            'Audit log viewer and export.',
            true, true, ['panel.auditoria']],
        'tickets' => ['Solicitudes / Tickets', 'Requests / Tickets',
            'Módulo de soporte interno.',
            'Internal support module.',
            true, true, ['panel.tickets']],
        // Fase 2: triaje (apagado por defecto; se activa por instalación)
        'triaje' => ['Triaje', 'Triage',
            'Estación de signos vitales y motivo ligados a la cita (con o sin cita previa).',
            'Vital signs and reason-for-visit station linked to the appointment (walk-ins included).',
            false, true, ['triaje.registrar']],
        'registro_atencion' => ['Registro de atención (formato clínica)', 'Visit record (clinic format)',
            'Formulario con Servicio, Tratante e Indicaciones/Cantidad/Recomendaciones, y hoja imprimible del paciente con signos vitales.',
            'Form with Service, Provider and Instructions/Quantity/Recommendations, plus a printable patient sheet with vital signs.',
            false, true, ['atencion.registro','atencion.ver_registro']],
        'examenes' => ['Exámenes (órdenes y seguimiento)', 'Exams (orders and follow-up)',
            'Solicitar exámenes (laboratorio, ecografía, imagen), ver pendientes y registrar los realizados por paciente.',
            'Order exams (lab, ultrasound, imaging), see pending ones and record the completed ones per patient.',
            false, true, ['examenes.ordenar','examenes.registrar']],
        'derivacion' => ['Derivación entre profesionales / sueros', 'Referrals / IV therapy',
            'Derivar al terminar la consulta a otro profesional o a colocación de sueros.',
            'Refer at the end of a visit to another professional or IV therapy.',
            false, true, ['derivar.crear','sueros.aplicar']],
    ];
}

/** Valores guardados: [key => 0|1]. Cacheado por request; tolera tabla ausente. */
function modulos_guardados($recargar = false) {
    static $cache = null;
    if ($cache !== null && !$recargar) return $cache;
    $cache = [];
    $c = function_exists('_permisos_conn') ? _permisos_conn() : conectarse();
    if ($c) {
        $rs = @$c->query("SELECT clave, valor FROM app_config WHERE clave LIKE 'mod.%'");
        if ($rs) while ($r = $rs->fetch_assoc()) $cache[substr($r['clave'], 4)] = ((string)$r['valor'] === '1') ? 1 : 0;
    }
    return $cache;
}

/** ¿Está activo el módulo en esta instalación? Módulo desconocido => true (no bloquea). */
function modulo_activo($key) {
    $cat = modulos_catalogo();
    if (!isset($cat[$key])) return true;
    if (!$cat[$key][5]) return false;                 // reservado: nunca activo
    $g = modulos_guardados();
    return array_key_exists($key, $g) ? ($g[$key] === 1) : (bool)$cat[$key][4];
}

/** Módulo que gobierna un permiso de permisos_catalogo(), o null si es "núcleo". */
function modulo_de_permiso($permiso) {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach (modulos_catalogo() as $mk => $m) foreach ($m[6] as $p) $mapa[$p] = $mk;
    }
    return $mapa[$permiso] ?? null;
}

/** Exige el módulo activo o redirige (páginas que no se gobiernan con permisos). */
function requerir_modulo($key, $redir = 'break.php') {
    if (!modulo_activo($key)) { header('Location: ' . $redir); exit(); }
}

/** Guarda el estado de un módulo (1/0). Devuelve bool. */
function modulo_guardar($conexion, $key, $activo) {
    $cat = modulos_catalogo();
    if (!isset($cat[$key]) || !$cat[$key][5]) return false;
    @$conexion->query("CREATE TABLE IF NOT EXISTS app_config (
        clave VARCHAR(60) NOT NULL PRIMARY KEY,
        valor VARCHAR(190) NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $clave = 'mod.' . $key; $v = $activo ? '1' : '0';
    $st = $conexion->prepare("INSERT INTO app_config (clave, valor) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor), updated_at=NOW()");
    if (!$st) return false;
    $st->bind_param('ss', $clave, $v); $ok = $st->execute(); $st->close();
    modulos_guardados(true);
    return $ok;
}

/* ───────── Reglas de negocio configurables por instalación (app_config 'regla.*') ─────────
 * Valor por defecto = comportamiento histórico (regla activa), para no alterar otras instalaciones. */
function reglas_catalogo() {
    return [
        'cita_icd10'     => ['Exigir diagnóstico (ICD-10) para agendar', 'Require a diagnosis (ICD-10) to schedule',
                             'El paciente debe tener al menos un diagnóstico ICD-10 antes de agendarle una cita.',
                             'The patient must have at least one ICD-10 diagnosis before an appointment can be scheduled.', true],
        'cita_direccion' => ['Exigir dirección del paciente para agendar', 'Require patient address to schedule',
                             'El paciente debe tener dirección (calle, o ciudad y estado) antes de agendarle una cita.',
                             'The patient must have an address (street, or city and state) before an appointment can be scheduled.', true],
    ];
}

function reglas_guardadas($recargar = false) {
    static $cache = null;
    if ($cache !== null && !$recargar) return $cache;
    $cache = [];
    $c = function_exists('_permisos_conn') ? _permisos_conn() : conectarse();
    if ($c) {
        $rs = @$c->query("SELECT clave, valor FROM app_config WHERE clave LIKE 'regla.%'");
        if ($rs) while ($r = $rs->fetch_assoc()) $cache[substr($r['clave'], 6)] = ((string)$r['valor'] === '1') ? 1 : 0;
    }
    return $cache;
}

/** ¿Está activa la regla? Regla desconocida => true (no relaja nada por accidente). */
function regla_activa($key) {
    $cat = reglas_catalogo();
    if (!isset($cat[$key])) return true;
    $g = reglas_guardadas();
    return array_key_exists($key, $g) ? ($g[$key] === 1) : (bool)$cat[$key][4];
}

function regla_guardar($conexion, $key, $activa) {
    if (!isset(reglas_catalogo()[$key])) return false;
    @$conexion->query("CREATE TABLE IF NOT EXISTS app_config (
        clave VARCHAR(60) NOT NULL PRIMARY KEY, valor VARCHAR(190) NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $clave = 'regla.' . $key; $v = $activa ? '1' : '0';
    $st = $conexion->prepare("INSERT INTO app_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), updated_at=NOW()");
    if (!$st) return false;
    $st->bind_param('ss', $clave, $v); $ok = $st->execute(); $st->close();
    reglas_guardadas(true);
    return $ok;
}
