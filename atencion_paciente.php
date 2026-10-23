<?php
ob_start();
session_start();

require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
$snLang = (current_lang() === 'en') ? 'en-US' : 'es-ES';

if(!isset($_SESSION["rol"])){
    header("Location: break.php");
    exit();
}
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) {
    session_destroy();
    header("Location: expirada.php");
    exit();
}

if(!isset($_GET['idCita']) || empty($_GET['idCita'])){
    die(htmlspecialchars(t('att.errNoId')));
}

$idCita = $conexion->real_escape_string($_GET['idCita']);

// ¿Existen las columnas NPI y LICENSE_ID en ADM_USUARIO? (las agrega migrar_credenciales_doctor.php)
$dbName = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
$colDocExiste = function ($col) use ($conexion, $dbName) {
    return (int)$conexion->query(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='ADM_USUARIO' AND COLUMN_NAME='$col'"
    )->fetch_assoc()['c'] > 0;
};
$tieneNpi     = $colDocExiste('NPI');
$tieneLicense = $colDocExiste('LICENSE_ID');
$tieneFirma   = $colDocExiste('FIRMA_IMG');
$selDocCreds  = ($tieneNpi     ? ", D.NPI        AS DOC_NPI"        : ", '' AS DOC_NPI")
              . ($tieneLicense ? ", D.LICENSE_ID AS DOC_LICENSE_ID" : ", '' AS DOC_LICENSE_ID")
              . ($tieneFirma   ? ", D.FIRMA_IMG  AS DOC_FIRMA_IMG"  : ", '' AS DOC_FIRMA_IMG");
// Credenciales del usuario de sesión (para la firma cuando el que atiende es el logueado)
$selUsrCreds  = ($tieneNpi     ? ", U.NPI        AS USR_NPI"        : ", '' AS USR_NPI")
              . ($tieneLicense ? ", U.LICENSE_ID AS USR_LICENSE_ID" : ", '' AS USR_LICENSE_ID")
              . ($tieneFirma   ? ", U.FIRMA_IMG  AS USR_FIRMA_IMG"  : ", '' AS USR_FIRMA_IMG");
$idUserSesion = (int)($_SESSION['iduser'] ?? 0);

$sql = "SELECT
            P.IDPACIENTE, P.NOMBRES, P.APELLIDOS, P.FECHANACIMIENTO, P.SEX,
            P.EMAIL, P.TELEFONO, P.CEDULA, P.ADDRESS,
            A.FECHA_CITA, A.HORA_INICIO, A.IDDOCTOR, A.IDAGENCIA,
            D.NOMBRES  AS DOC_NOMBRES,
            D.APELLIDOS AS DOC_APELLIDOS
            $selDocCreds
            $selUsrCreds,
            AG.DESCRIPCION AS AGENCIA_NOMBRE,
            AG.DIRECCION  AS AGENCIA_DIRECCION,
            AG.TELEFONO   AS AGENCIA_TEL,
            TC.NOMBRES    AS TIPO_CONSULTA
        FROM AG_CITA A
        INNER JOIN AG_PACIENTE     P  ON A.IDPACIENTE      = P.IDPACIENTE
        LEFT  JOIN ADM_USUARIO     D  ON A.IDDOCTOR         = D.IDADM_USUARIO
        LEFT  JOIN ADM_USUARIO     U  ON U.IDADM_USUARIO    = $idUserSesion
        LEFT  JOIN ADM_AGENCIA     AG ON AG.IDAGENCIA        = COALESCE(A.IDAGENCIA, 1)
        LEFT  JOIN AG_TIPOCONSULTA TC ON A.IDTIPOCONSULTA   = TC.IDTIPOCONSULTA
        WHERE A.IDCITA = '$idCita'";

$res = $conexion->query($sql);
if (!$res || $res->num_rows == 0) {
    die(htmlspecialchars(t('att.errNotFound')));
}
$d = $res->fetch_assoc();

// ── Nota de ESTA cita (si ya fue atendida antes) y de la consulta ANTERIOR ──
$idCitaInt   = (int)$idCita;
$idPacienteA = (int)$d['IDPACIENTE'];

// Triaje de esta cita (signos vitales + motivo tomados por enfermería), si el módulo está activo
require_once(__DIR__ . '/class/permisos.php');
require_once(__DIR__ . '/class/triaje.php');
$triajeCita = modulo_activo('triaje') ? triaje_obtener($conexion, $idCitaInt) : null;

// Registro de atención (formato de la hoja física de la clínica); convive con la nota libre
require_once(__DIR__ . '/class/registro_atencion.php');
$modRegistro = modulo_activo('registro_atencion');
$puedeRegistro = $modRegistro && puede('atencion.registro');
$verHoja = $modRegistro && puede('atencion.ver_registro');
$registro = ['reg' => null, 'items' => []];
if ($puedeRegistro) { registro_asegurar_tablas($conexion); $registro = registro_obtener($conexion, $idCitaInt); }
$rgServicio = $registro['reg']['SERVICIO'] ?? ($d['TIPO_CONSULTA'] ?? '');
$rgFecha    = $registro['reg']['FECHA']    ?? ($d['FECHA_CITA'] ?? date('Y-m-d'));
$rgTratante = $registro['reg']['TRATANTE'] ?? trim(($d['DOC_NOMBRES'] ?? '').' '.($d['DOC_APELLIDOS'] ?? ''));
$rgItems    = $registro['items'] ?: [['INDICACION'=>'','CANTIDAD'=>'','RECOMENDACION'=>'']];

// Exámenes del paciente (órdenes y seguimiento)
require_once(__DIR__ . '/class/enfermeria.php');
$modExamenes   = modulo_activo('examenes');
$puedeOrdenar  = $modExamenes && puede('examenes.ordenar');
$verExamenes   = $modExamenes && puede('examenes.registrar');
$examenesPac   = [];
if ($puedeOrdenar || $verExamenes) { enfermeria_asegurar_tablas($conexion); $examenesPac = examenes_paciente($conexion, $idPacienteA); }

// Derivación: la recibida (esta cita es el destino) y las emitidas desde esta cita
require_once(__DIR__ . '/class/derivacion.php');
$derRecibida = null; $derEmitidas = []; $doctoresDerivar = [];
$modDeriva = modulo_activo('derivacion');
if ($modDeriva) {
    derivacion_asegurar_tablas($conexion);
    $qd = $conexion->query("SELECT D.MOTIVO, D.FECHA_CREACION, D.IDCITA_ORIGEN, CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS DERIVA, H.CONTENIDO_INFORME
                              FROM derivacion D
                              LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = D.IDUSUARIO_ORIGEN
                              LEFT JOIN AG_HISTORIAL H ON H.IDCITA = D.IDCITA_ORIGEN
                             WHERE D.IDCITA_DESTINO = $idCitaInt LIMIT 1");
    if ($qd) $derRecibida = $qd->fetch_assoc() ?: null;
    $qe = $conexion->query("SELECT D.IDDERIVACION, D.TIPO, D.ESTADO, D.MOTIVO, D.FECHA_CREACION, CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS DESTINO,
                                   (SELECT GROUP_CONCAT(CONCAT(S.PRODUCTO, ' (', S.ESTADO, ')') SEPARATOR ', ') FROM suero_orden S WHERE S.IDDERIVACION = D.IDDERIVACION) AS SUEROS
                              FROM derivacion D LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = D.IDUSUARIO_DESTINO
                             WHERE D.IDCITA_ORIGEN = $idCitaInt ORDER BY D.IDDERIVACION");
    if ($qe) while ($x = $qe->fetch_assoc()) $derEmitidas[] = $x;
    if (puede('derivar.crear')) {
        $qdd = $conexion->query("SELECT U.IDADM_USUARIO id, CONCAT(U.NOMBRES,' ',U.APELLIDOS) nombre FROM ADM_USUARIO U
                                  INNER JOIN ADM_ROL R ON R.IDADM_ROL = U.IDADM_ROL
                                  WHERE R.CARGO='DOCTOR' AND U.ESTADO='A' AND U.IDADM_USUARIO <> ".(int)$d['IDDOCTOR']." ORDER BY U.NOMBRES");
        if ($qdd) while ($x = $qdd->fetch_assoc()) $doctoresDerivar[] = $x;
    }
}

// Nota ya guardada para esta misma cita (permite reabrir/editar una atención)
$informeActual = '';
if ($sa = $conexion->prepare("SELECT CONTENIDO_INFORME FROM AG_HISTORIAL WHERE IDCITA=? LIMIT 1")) {
    $sa->bind_param('i', $idCitaInt); $sa->execute();
    $ra = $sa->get_result()->fetch_assoc(); $sa->close();
    if ($ra) $informeActual = (string)$ra['CONTENIDO_INFORME'];
}

// Nota de la consulta anterior del mismo paciente (para precargar en follow-ups)
$prevInforme = ''; $prevFecha = ''; $prevTipo = '';
if ($sp = $conexion->prepare(
    "SELECT H.CONTENIDO_INFORME, C.FECHA_CITA, TC.NOMBRES AS TIPO
     FROM AG_HISTORIAL H
     INNER JOIN AG_CITA C ON C.IDCITA = H.IDCITA
     LEFT  JOIN AG_TIPOCONSULTA TC ON TC.IDTIPOCONSULTA = C.IDTIPOCONSULTA
     WHERE C.IDPACIENTE = ? AND H.IDCITA <> ?
       AND TRIM(COALESCE(H.CONTENIDO_INFORME,'')) <> ''
     ORDER BY C.FECHA_CITA DESC, C.HORA_INICIO DESC, H.IDHISTORIAL DESC
     LIMIT 1")) {
    $sp->bind_param('ii', $idPacienteA, $idCitaInt); $sp->execute();
    $rp = $sp->get_result()->fetch_assoc(); $sp->close();
    if ($rp) { $prevInforme = (string)$rp['CONTENIDO_INFORME']; $prevFecha = (string)$rp['FECHA_CITA']; $prevTipo = (string)($rp['TIPO'] ?? ''); }
}

// Peso/talla de la atención anterior (para mostrarlos en el encabezado y no preguntar)
$prevPeso = null; $prevTalla = null; $prevPesoFecha = '';
if ($sp2 = $conexion->prepare(
    "SELECT H.PESO, H.TALLA, C.FECHA_CITA
     FROM AG_HISTORIAL H
     INNER JOIN AG_CITA C ON C.IDCITA = H.IDCITA
     WHERE C.IDPACIENTE = ? AND H.IDCITA <> ? AND H.PESO IS NOT NULL AND H.PESO > 0
     ORDER BY C.FECHA_CITA DESC, C.HORA_INICIO DESC, H.IDHISTORIAL DESC
     LIMIT 1")) {
    $sp2->bind_param('ii', $idPacienteA, $idCitaInt); $sp2->execute();
    $rp2 = $sp2->get_result()->fetch_assoc(); $sp2->close();
    if ($rp2) { $prevPeso = (float)$rp2['PESO']; $prevTalla = (float)$rp2['TALLA']; $prevPesoFecha = (string)$rp2['FECHA_CITA']; }
}

// Serie completa de peso/IMC del paciente (para insertar la gráfica en el informe).
$evolLabels = []; $evolPesos = []; $evolImcs = [];
if ($sp3 = $conexion->prepare(
    "SELECT C.FECHA_CITA fecha, H.PESO peso, H.IMC imc
       FROM AG_HISTORIAL H INNER JOIN AG_CITA C ON C.IDCITA = H.IDCITA
      WHERE C.IDPACIENTE = ? AND H.PESO IS NOT NULL AND H.PESO > 0
      ORDER BY C.FECHA_CITA ASC, C.HORA_INICIO ASC")) {
    $sp3->bind_param('i', $idPacienteA); $sp3->execute();
    $rs3 = $sp3->get_result();
    while ($r3 = $rs3->fetch_assoc()) {
        $evolLabels[] = date('m/d/Y', strtotime($r3['fecha']));
        $evolPesos[]  = ($r3['peso'] !== null) ? round((float)$r3['peso'], 1) : null;
        $evolImcs[]   = ($r3['imc'] !== null && (float)$r3['imc'] > 0) ? round((float)$r3['imc'], 1) : null;
    }
    $sp3->close();
}

// ── Diagnósticos ICD-10: catálogo + los ya asignados al paciente ──────────
$catIcd10 = [];
$icdTieneActivo = (int)$conexion->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ENFE_DIAG_COD' AND COLUMN_NAME='ACTIVO'")->fetch_assoc()['c'] > 0;
$icdFiltro = $icdTieneActivo ? "WHERE COALESCE(ACTIVO,1)=1" : "";
$rc = $conexion->query("SELECT ID_ENFE_DIAG_COD AS id, CODIGO AS codigo, DESCRIPCION AS descripcion FROM ENFE_DIAG_COD $icdFiltro ORDER BY CODIGO");
if ($rc) while ($x = $rc->fetch_assoc()) $catIcd10[] = $x;

$icd10Paciente = [];
$tieneTablaIcd = (int)$conexion->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='paciente_icd10'")->fetch_assoc()['c'] > 0;
if ($tieneTablaIcd) {
    if ($si = $conexion->prepare("SELECT C.ID_ENFE_DIAG_COD AS id, C.CODIGO AS codigo, C.DESCRIPCION AS descripcion
                                    FROM paciente_icd10 R
                                    INNER JOIN ENFE_DIAG_COD C ON C.ID_ENFE_DIAG_COD = R.ID_ENFE_DIAG_COD
                                   WHERE R.IDPACIENTE = ? ORDER BY R.id ASC")) {
        $si->bind_param('i', $idPacienteA); $si->execute();
        $rs = $si->get_result(); while ($x = $rs->fetch_assoc()) $icd10Paciente[] = $x; $si->close();
    }
}

// ── Catálogo de diagnósticos NCP/PES (en el idioma actual) ────────────────
$ncpDiag = [];
$tieneNcp = (int)$conexion->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncp_diagnosticos'")->fetch_assoc()['c'] > 0;
if ($tieneNcp) {
    $L = (current_lang() === 'en') ? 'en' : 'es';
    $q = $conexion->query("SELECT id, codigo,
                                  enfermedad_$L AS enfermedad, problema_$L AS problema,
                                  etiologia_$L AS etiologia, signos_$L AS signos,
                                  intervencion_$L AS intervencion, monitoreo_$L AS monitoreo
                             FROM ncp_diagnosticos WHERE activo=1 ORDER BY orden, enfermedad_$L");
    if ($q) while ($x = $q->fetch_assoc()) $ncpDiag[] = $x;
}

$sessionNombres   = $_SESSION['nombres']   ?? '';
$sessionApellidos = $_SESSION['apellidos'] ?? '';
$docNombreCompleto = trim($d['DOC_NOMBRES'] . ' ' . $d['DOC_APELLIDOS']);
$doctorAtiende = ($sessionNombres || $sessionApellidos)
    ? trim($sessionNombres . ' ' . $sessionApellidos)
    : $docNombreCompleto;

// Mapea el sexo del paciente a 0 (male) / 1 (female) para las tablas WHO/CDC.
$sexoTexto = strtolower(trim($d['SEX'] ?? ''));
if (in_array($sexoTexto, ['male','m','masculino','hombre'], true))       $pacienteSexIdx = 0;
elseif (in_array($sexoTexto, ['female','fame','f','femenino','mujer'], true)) $pacienteSexIdx = 1;
else                                                                          $pacienteSexIdx = -1;

// Credenciales para la firma: si el usuario logueado tiene NPI/License, se usan
// las suyas (él es quien firma); si no, se caen al doctor asignado a la cita.
$firmaNpi     = trim($d['USR_NPI'] ?? '') !== '' ? $d['USR_NPI'] : ($d['DOC_NPI'] ?? '');
$firmaLicense = trim($d['USR_LICENSE_ID'] ?? '') !== '' ? $d['USR_LICENSE_ID'] : ($d['DOC_LICENSE_ID'] ?? '');
$firmaImg     = trim($d['USR_FIRMA_IMG'] ?? '') !== '' ? $d['USR_FIRMA_IMG'] : ($d['DOC_FIRMA_IMG'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon de la app -->
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="images/favicon.png">
    <link rel="apple-touch-icon" href="images/favicon.png">
    <title><?php te('att.title'); ?> - <?php echo htmlspecialchars($d['NOMBRES'].' '.$d['APELLIDOS']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
    <style>
        .unit-toggle .btn { padding: 2px 8px; font-size: 0.78rem; }
        .medicion-group { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .medicion-group input { max-width: 100px; }
        /* Los inputs de pies/pulgadas comparten el ancho disponible */
        #grpFtIn { flex: 1 1 100%; min-width: 0; }
        #grpFtIn .input-group { flex: 1 1 0; min-width: 0; }
        #grpFtIn .input-group input { max-width: none; min-width: 0; }

        /* En pantallas pequeñas: apilar inputs y selector de unidad para que
           pies/pulgadas no se vean apretados. */
        @media (max-width: 575.98px) {
            .medicion-group { flex-direction: column; align-items: stretch; gap: 8px; }
            .medicion-group input { max-width: none; width: 100%; }
            .unit-toggle { display: flex; width: 100%; }
            .unit-toggle .btn { flex: 1 1 0; padding: 5px 4px; }
        }

        /* ── Tarjetas de medición (acorde a la plantilla de la app) ── */
        /* select2 a la altura de los inputs de Bootstrap 5 */
        .select2-container .select2-selection--single { height: calc(2.375rem + 2px); display:flex; align-items:center; }
        .select2-container--default .select2-selection--single { border:1px solid #ced4da; border-radius:.375rem; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height:1.5; padding-left:.75rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: calc(2.375rem); }
        .select2-container { width: 100% !important; }
        /* Chips de diagnósticos ICD-10 */
        .icd10-chip{ display:inline-flex; align-items:center; gap:6px; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:16px; padding:3px 6px 3px 10px; font-size:.82rem; }
        .icd10-chip .code{ font-weight:700; }
        .icd10-chip button{ border:0; background:transparent; color:#4338ca; line-height:1; padding:0 2px; cursor:pointer; }
        .att-metrics .att-tile {
            background:#fff; border:1px solid #e6e9f0; border-radius:12px;
            padding:12px 14px; height:100%; box-shadow:0 1px 2px rgba(16,31,85,.04);
        }
        .att-tile-label {
            font-size:.72rem; font-weight:700; text-transform:uppercase;
            letter-spacing:.04em; color:#5b6b8c; margin-bottom:6px; display:block;
        }
        #imc { font-size:1.15rem; }
        #estado_imc { width:100%; }
        .att-toolbar { background:#f6f8fc; border:1px solid #e6e9f0; border-radius:12px; }
        /* Editor y cabecera de marca */
        .note-editor.note-frame { border-radius:12px; border-color:#e6e9f0; }
        .att-actionbar { border-top:1px solid #eef1f6; padding-top:16px; margin-top:4px; }

        /* ── Dictado por voz ── */
        @keyframes micPulse { 0%,100%{opacity:1} 50%{opacity:.25} }
        .mic-pulse { animation: micPulse 1s infinite; display:inline-block; }
        #btnMic { transition: all .2s; }
        #interimText {
            max-width: 520px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>
<body>
<div class="app-container app-theme-white body-tabs-shadow fixed-sidebar fixed-header">

    <!-- HEADER -->
    <div class="app-header header-shadow">
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
        <div class="app-header__content">
            <div class="app-header-left"></div>
            <div class="app-header-right">
                <div class="header-btn-lg pr-0">
                    <div class="widget-content p-0">
                        <div class="widget-content-wrapper">
                            <div class="widget-content-left ml-3 header-user-info">
                                <div class="widget-heading"><?php echo htmlspecialchars($sessionNombres); ?></div>
                                <div class="widget-subheading"><?php echo htmlspecialchars($_SESSION['rol'] ?? ''); ?></div>
                            </div>
                            <div class="widget-content-left ms-3">
                                <div class="btn-group">
                                    <a data-toggle="dropdown" class="p-0 btn" href="#">
                                        <i class="fa fa-angle-down ml-2 opacity-8"></i>
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <a href="salir.php" class="dropdown-item"><?php te('menu.logout'); ?></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-main">
        <!-- SIDEBAR -->
        <div class="app-sidebar sidebar-shadow">
            <?php include("./menu/menu_adm.php"); ?>
        </div>

        <div class="app-main__outer">
            <div class="app-main__inner">

<?php
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
// Datos de cabecera del paciente: fecha de nacimiento + edad
$dobRaw  = $d['FECHANACIMIENTO'] ?? '';
$dobTxt  = ''; $edadTxt = '';
if ($dobRaw && $dobRaw !== '0000-00-00') {
    $ts = strtotime($dobRaw);
    if ($ts) { $dobTxt = date('m/d/Y', $ts); $edadTxt = (string)(new DateTime($dobRaw))->diff(new DateTime('today'))->y; }
}
// Texto de peso/talla anteriores (peso en kg, talla guardada en cm → m)
$prevPesoTxt = ($prevPeso && $prevPeso > 0) ? number_format($prevPeso, 1) . ' kg' : '';
$prevTallaTxt = '';
if ($prevTalla && $prevTalla > 0) {
    $prevTallaM = $prevTalla > 3 ? $prevTalla / 100 : $prevTalla;
    $prevTallaTxt = number_format($prevTallaM, 2) . ' m';
}
$prevMedidas = trim($prevPesoTxt . ($prevPesoTxt && $prevTallaTxt ? ' · ' : '') . $prevTallaTxt);
?>
<div class="app-page-title">
    <div class="page-title-wrapper">
        <div class="page-title-heading">
            <div class="page-title-icon">
                <i class="pe-7s-note2 icon-gradient bg-tempting-azure"></i>
            </div>
            <div>
                <?php echo htmlspecialchars($d['NOMBRES'].' '.$d['APELLIDOS']); ?>
                <div class="page-title-subheading">
                    <i class="bi bi-person-lines-fill me-1"></i>
                    <?php te('att.title'); ?> &nbsp;·&nbsp; <?php te('att.appt'); ?> #<?php echo $idCita; ?>
                </div>
                <div class="page-title-subheading att-pac-info mt-1" style="opacity:1;">
                    <span class="me-3" title="<?php te('pf.id'); ?>"><i class="bi bi-card-text me-1"></i><?php echo h($d['CEDULA'] ?: '—'); ?></span>
                    <span class="me-3" title="<?php te('pf.dob'); ?>"><i class="bi bi-calendar-heart me-1"></i><?php echo $dobTxt ?: '—'; ?><?php echo $edadTxt !== '' ? ' ('.$edadTxt.' '.t('att.years').')' : ''; ?></span>
                    <span class="me-3" title="<?php te('pf.sex'); ?>"><i class="bi bi-gender-ambiguous me-1"></i><?php echo h($d['SEX'] ?: '—'); ?></span>
                    <?php if ($prevMedidas !== ''): ?>
                    <span class="me-3" title="<?php te('att.prevMeasures'); ?>"><i class="bi bi-clipboard2-pulse me-1"></i><?php echo h($prevMedidas); ?><?php echo $prevPesoFecha ? ' <span style="opacity:.75;">('.date('m/d/Y', strtotime($prevPesoFecha)).')</span>' : ''; ?></span>
                    <?php endif; ?>
                    <span class="me-3" title="<?php te('pf.phone'); ?>"><i class="bi bi-telephone me-1"></i><?php echo h($d['TELEFONO'] ?: '—'); ?></span>
                    <span class="me-3" title="<?php te('pf.email'); ?>"><i class="bi bi-envelope me-1"></i><?php echo h($d['EMAIL'] ?: '—'); ?></span>
                    <?php if (!empty($d['ADDRESS'])): ?>
                    <span class="me-3" title="<?php te('pf.address'); ?>"><i class="bi bi-geo-alt me-1"></i><?php echo h($d['ADDRESS']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="page-title-actions">
            <?php if ($modDeriva && puede('derivar.crear')): ?>
            <button type="button" class="btn btn-outline-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalDerivar">
                <i class="bi bi-arrow-right-circle me-1"></i><?php echo current_lang()==='en' ? 'Refer' : 'Derivar'; ?>
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-info btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalAyudaAtencion">
                <i class="bi bi-question-circle me-1"></i><?php te('help.howItWorks'); ?>
            </button>
            <span class="badge rounded-pill fs-6" style="background:linear-gradient(135deg,#0e1f55,#1a3a8c);color:#fff;padding:.55rem .9rem;">
                <i class="bi bi-hash"></i><?php echo $idCita; ?>
            </span>
        </div>
    </div>
</div>

<?php if ($derRecibida): $enD = (current_lang() === 'en'); ?>
<div class="main-card mb-3 card border-primary">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-start">
            <div class="fw-semibold"><i class="bi bi-arrow-right-circle text-primary me-1"></i><?php echo $enD ? 'Referred by' : 'Derivado por'; ?> <?php echo h($derRecibida['DERIVA']); ?>
                <span class="small text-muted ms-2"><?php echo h(date('m/d/Y H:i', strtotime($derRecibida['FECHA_CREACION']))); ?></span></div>
            <?php if (trim((string)$derRecibida['CONTENIDO_INFORME']) !== ''): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNotaOrigen"><i class="bi bi-journal-text"></i> <?php echo $enD ? "View referring doctor's note" : 'Ver nota del profesional que deriva'; ?></button>
            <?php endif; ?>
        </div>
        <div class="mt-1"><span class="text-muted small"><?php echo $enD ? 'Reason' : 'Motivo'; ?>:</span> <?php echo nl2br(h($derRecibida['MOTIVO'])); ?></div>
    </div>
</div>
<div class="modal fade" id="modalNotaOrigen" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $enD ? 'Referring note (read-only)' : 'Nota de origen (solo lectura)'; ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-0"><iframe sandbox="" style="width:100%;height:70vh;border:0;" srcdoc="<?php echo h('<base target="_blank"><body style="font-family:sans-serif;padding:12px">'.$derRecibida['CONTENIDO_INFORME'].'</body>'); ?>"></iframe></div>
</div></div></div>
<?php endif; ?>
<?php if ($derEmitidas): $enD = (current_lang() === 'en'); ?>
<div class="main-card mb-3 card border-secondary"><div class="card-body py-2 small">
    <div class="fw-semibold mb-1"><i class="bi bi-diagram-3 me-1"></i><?php echo $enD ? 'Referrals from this visit' : 'Derivaciones de esta consulta'; ?></div>
    <?php foreach ($derEmitidas as $de): ?>
    <div><span class="badge bg-<?php echo $de['ESTADO']==='Atendida'?'success':'warning text-dark'; ?>"><?php echo h($de['ESTADO']); ?></span>
        <?php echo $de['TIPO']==='SUERO' ? ($enD?'IV therapy':'Sueros').': '.h($de['SUEROS']) : ($enD?'To':'A').' '.h($de['DESTINO']); ?>
        <span class="text-muted">— <?php echo h($de['MOTIVO']); ?></span></div>
    <?php endforeach; ?>
</div></div>
<?php endif; ?>
<?php if ($triajeCita): $tj = $triajeCita; $enT = (current_lang() === 'en');
      $fmt = function($v){ if ($v === null) return '—'; $v = (string)$v; return strpos($v, '.') !== false ? rtrim(rtrim($v, '0'), '.') : $v; }; ?>
<div class="main-card mb-3 card border-info">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="fw-semibold"><i class="bi bi-heart-pulse text-info me-1"></i><?php echo $enT ? 'Triage' : 'Triaje'; ?>
                <span class="small text-muted ms-2"><?php echo h($tj['REGISTRADO_POR']); ?> · <?php echo h(date('m/d/Y H:i', strtotime($tj['FECHA_MODIFICACION'] ?: $tj['FECHA_REGISTRO']))); ?></span></div>
            <?php if (puede('triaje.registrar')): ?><a class="btn btn-sm btn-outline-info" href="triaje.php?idCita=<?php echo (int)$idCitaInt; ?>&from=att"><i class="bi bi-pencil"></i> <?php echo $enT ? 'Edit' : 'Editar'; ?></a><?php endif; ?>
        </div>
        <div class="row g-2 small">
            <div class="col-6 col-md-2"><span class="text-muted"><?php echo $enT ? 'Weight' : 'Peso'; ?></span><br><b><?php echo h($fmt($tj['PESO_KG'])); ?></b> kg</div>
            <div class="col-6 col-md-2"><span class="text-muted"><?php echo $enT ? 'Height' : 'Talla'; ?></span><br><b><?php echo h($fmt($tj['TALLA_CM'])); ?></b> cm</div>
            <div class="col-6 col-md-2"><span class="text-muted"><?php echo $enT ? 'BP' : 'P. arterial'; ?></span><br><b><?php echo h($tj['PRESION'] ?: '—'); ?></b></div>
            <div class="col-6 col-md-2"><span class="text-muted"><?php echo $enT ? 'HR' : 'FC'; ?></span><br><b><?php echo h($fmt($tj['FC'])); ?></b></div>
            <div class="col-6 col-md-2"><span class="text-muted">SatO₂</span><br><b><?php echo h($fmt($tj['SAT_O2'])); ?></b> %</div>
            <div class="col-6 col-md-1"><span class="text-muted">Temp.</span><br><b><?php echo h($fmt($tj['TEMPERATURA'])); ?></b></div>
            <div class="col-6 col-md-1"><span class="text-muted">Gluc.</span><br><b><?php echo h($fmt($tj['GLUCOSA'])); ?></b></div>
        </div>
        <?php if (trim((string)$tj['MOTIVO']) !== ''): ?><div class="mt-2"><span class="text-muted small"><?php echo $enT ? 'Reason for visit' : 'Motivo de consulta'; ?>:</span> <?php echo nl2br(h($tj['MOTIVO'])); ?></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($puedeRegistro): $enR = (current_lang() === 'en'); ?>
<div class="main-card mb-3 card border-success" id="cardRegistro">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
            <div class="fw-semibold"><i class="bi bi-clipboard2-check text-success me-1"></i><?php echo $enR ? 'Visit record (clinic format)' : 'Registro de atención (formato clínica)'; ?>
                <?php if ($registro['reg']): ?><span class="badge bg-success ms-1"><?php echo $enR ? 'Saved' : 'Guardado'; ?></span><?php endif; ?></div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="rgInsertarEnNota()"><i class="bi bi-box-arrow-in-down"></i> <?php echo $enR ? 'Insert into note' : 'Insertar en la nota'; ?></button>
                <?php if ($verHoja): ?><a class="btn btn-sm btn-outline-secondary" target="_blank" href="registro_paciente.php?idPaciente=<?php echo (int)$idPacienteA; ?>"><i class="bi bi-printer"></i> <?php echo $enR ? 'Patient sheet' : 'Hoja del paciente'; ?></a><?php endif; ?>
            </div>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-md-5"><label class="form-label small mb-0"><?php echo $enR ? 'Service' : 'Servicio'; ?></label><input id="rgServicio" class="form-control form-control-sm" maxlength="150" value="<?php echo h($rgServicio); ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0"><?php echo $enR ? 'Date' : 'Fecha'; ?></label><input type="date" id="rgFecha" class="form-control form-control-sm" value="<?php echo h($rgFecha); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0"><?php echo $enR ? 'Provider' : 'Tratante'; ?></label><input id="rgTratante" class="form-control form-control-sm" maxlength="150" value="<?php echo h($rgTratante); ?>"></div>
        </div>
        <div class="row g-2 small text-muted d-none d-md-flex"><div class="col-md-5"><?php echo $enR ? 'Instructions' : 'Indicaciones'; ?></div><div class="col-md-2"><?php echo $enR ? 'Quantity' : 'Cantidad'; ?></div><div class="col-md-4"><?php echo $enR ? 'Recommendations' : 'Recomendaciones'; ?></div></div>
        <div id="rgFilas">
        <?php foreach ($rgItems as $it): ?>
            <div class="row g-2 mb-2 rg-fila">
                <div class="col-md-5"><textarea class="form-control form-control-sm" rows="2" data-k="indicacion"><?php echo h($it['INDICACION']); ?></textarea></div>
                <div class="col-6 col-md-2"><input class="form-control form-control-sm" maxlength="60" data-k="cantidad" value="<?php echo h($it['CANTIDAD']); ?>"></div>
                <div class="col-6 col-md-4"><textarea class="form-control form-control-sm" rows="2" data-k="recomendacion"><?php echo h($it['RECOMENDACION']); ?></textarea></div>
                <div class="col-12 col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="rgQuitar(this)" title="<?php echo $enR ? 'Remove row' : 'Quitar fila'; ?>"><i class="bi bi-trash"></i></button></div>
            </div>
        <?php endforeach; ?>
        </div>
        <div class="d-flex align-items-center gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="rgAgregar()"><i class="bi bi-plus"></i> <?php echo $enR ? 'Add row' : 'Agregar fila'; ?></button>
            <button type="button" class="btn btn-sm btn-success" id="rgGuardar" onclick="rgGuardar()"><i class="bi bi-save"></i> <?php echo $enR ? 'Save record' : 'Guardar registro'; ?></button>
            <span id="rgMsg" class="small"></span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($puedeOrdenar || $verExamenes): $enX = (current_lang() === 'en'); $pendX = count(array_filter($examenesPac, function($e){ return $e['ESTADO']==='Pendiente'; })); ?>
<div class="main-card mb-3 card border-warning" id="cardExamenes">
    <div class="card-body py-3">
        <div class="fw-semibold mb-2"><i class="bi bi-clipboard2-data text-warning me-1"></i><?php echo $enX ? 'Exams' : 'Exámenes'; ?>
            <span class="badge bg-warning text-dark ms-1"><?php echo $pendX; ?> <?php echo $enX ? 'pending' : 'pendientes'; ?></span></div>
        <?php if (!$examenesPac): ?><div class="small text-muted mb-2"><?php echo $enX ? 'No exams ordered for this patient.' : 'Este paciente no tiene exámenes solicitados.'; ?></div>
        <?php else: ?><ul class="list-unstyled small mb-2">
            <?php foreach ($examenesPac as $ex): $cl = $ex['ESTADO']==='Pendiente' ? 'warning text-dark' : ($ex['ESTADO']==='Realizado' ? 'success' : 'secondary'); ?>
            <li class="mb-1"><span class="badge bg-<?php echo $cl; ?>"><?php echo h($ex['ESTADO']); ?></span> <b><?php echo h($ex['EXAMEN']); ?></b>
                <span class="text-muted">(<?php echo h($ex['TIPO']); ?> · <?php echo h(date('m/d/Y', strtotime($ex['FECHA_SOLICITUD']))); ?><?php echo $ex['SOLICITA'] ? ' · '.h($ex['SOLICITA']) : ''; ?>)</span>
                <?php if ($ex['INDICACION']): ?><span class="text-muted"> — <?php echo h($ex['INDICACION']); ?></span><?php endif; ?>
                <?php if ($ex['ESTADO']!=='Pendiente' && $ex['RESULTADO']): ?><div><?php echo nl2br(h($ex['RESULTADO'])); ?></div><?php endif; ?>
                <?php if ($ex['ESTADO']==='Pendiente' && $verExamenes): ?><button type="button" class="btn btn-sm btn-outline-success py-0 ms-1" onclick="exAtencionRealizado(<?php echo (int)$ex['IDEXAMEN']; ?>)"><i class="bi bi-check2"></i> <?php echo $enX ? 'Mark done' : 'Marcar realizado'; ?></button><?php endif; ?></li>
            <?php endforeach; ?></ul><?php endif; ?>
        <?php if ($puedeOrdenar): ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label small mb-0"><?php echo $enX ? 'Type' : 'Tipo'; ?></label><select id="exTipo" class="form-select form-select-sm"><?php foreach (examen_tipos() as $tp): ?><option><?php echo h($tp); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small mb-0"><?php echo $enX ? 'Exam' : 'Examen'; ?></label><input id="exNombre" class="form-control form-control-sm" maxlength="200" placeholder="<?php echo $enX ? 'e.g. Abdominal ultrasound, CBC' : 'p. ej. Ecografía abdominal, biometría hemática'; ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0"><?php echo $enX ? 'Instructions (optional)' : 'Indicación (opcional)'; ?></label><input id="exIndic" class="form-control form-control-sm"></div>
            <div class="col-md-2"><button type="button" class="btn btn-sm btn-warning w-100" onclick="exAtencionCrear()"><i class="bi bi-plus"></i> <?php echo $enX ? 'Order' : 'Solicitar'; ?></button></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="main-card mb-3 card">
    <div class="card-body">

        <!-- ── MEDICIONES ──────────────────────────────────────────── -->
        <div class="row g-3 mb-4 att-metrics align-items-stretch">

            <div class="col-6 col-md-3">
                <div class="att-tile">
                    <span class="att-tile-label"><i class="bi bi-speedometer2 me-1"></i><?php te('att.weight'); ?></span>
                    <div class="medicion-group">
                        <input type="number" id="peso" class="form-control" step="0.1"
                               placeholder="0.0" oninput="calcularIMC()">
                        <div class="btn-group unit-toggle" role="group">
                            <input type="radio" class="btn-check" name="unidadPeso" id="uKg" value="kg" checked onchange="calcularIMC()">
                            <label class="btn btn-outline-secondary" for="uKg">kg</label>
                            <input type="radio" class="btn-check" name="unidadPeso" id="uLbs" value="lbs" onchange="calcularIMC()">
                            <label class="btn btn-outline-secondary" for="uLbs">lbs</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="att-tile">
                    <span class="att-tile-label"><i class="bi bi-rulers me-1"></i><?php te('att.heightLbl'); ?></span>
                    <div class="medicion-group">
                        <!-- Talla en un solo número (cm / m) -->
                        <input type="number" id="talla" class="form-control" step="0.1"
                               placeholder="0.0" oninput="calcularIMC()">
                        <!-- Talla en pies + pulgadas (se muestra al elegir ft/in) -->
                        <div id="grpFtIn" class="d-flex gap-1" style="display:none!important;">
                            <div class="input-group input-group-sm">
                                <input type="number" id="tallaFt" class="form-control" step="1" min="0" placeholder="0" oninput="calcularIMC()">
                                <span class="input-group-text">ft</span>
                            </div>
                            <div class="input-group input-group-sm">
                                <input type="number" id="tallaIn" class="form-control" step="0.1" min="0" placeholder="0" oninput="calcularIMC()">
                                <span class="input-group-text">in</span>
                            </div>
                        </div>
                        <div class="btn-group unit-toggle" role="group">
                            <input type="radio" class="btn-check" name="unidadTalla" id="uCm" value="cm" checked onchange="toggleTallaUnidad()">
                            <label class="btn btn-outline-secondary" for="uCm">cm</label>
                            <input type="radio" class="btn-check" name="unidadTalla" id="uM" value="m" onchange="toggleTallaUnidad()">
                            <label class="btn btn-outline-secondary" for="uM">m</label>
                            <input type="radio" class="btn-check" name="unidadTalla" id="uFtIn" value="ftin" onchange="toggleTallaUnidad()">
                            <label class="btn btn-outline-secondary" for="uFtIn">ft/in</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-2">
                <div class="att-tile">
                    <span class="att-tile-label"><?php te('common.bmi'); ?></span>
                    <input type="text" id="imc" class="form-control bg-white fw-bold border-0 px-0" readonly>
                </div>
            </div>

            <div class="col-6 col-md-2">
                <div class="att-tile">
                    <span class="att-tile-label"><?php te('att.status'); ?></span>
                    <div id="estado_imc" class="badge p-2 d-block fs-6">---</div>
                </div>
            </div>

            <div class="col-12 col-md-2">
                <div class="att-tile">
                    <span class="att-tile-label"><?php te('att.reportTemplate'); ?></span>
                    <select id="selPlantilla" class="form-select" onchange="cargarPlantilla(this.value)">
                        <option value=""><?php te('att.selectNoteType'); ?></option>
                        <?php
                        $plantillas = $conexion->query(
                            "SELECT id, nombre_plantilla FROM cat_plantillas_nutricion ORDER BY categoria, nombre_plantilla"
                        );
                        while($p = $plantillas->fetch_assoc()):
                        ?>
                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre_plantilla']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ── DIAGNÓSTICOS ICD-10 (asignables en la consulta) ─────── -->
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="att-tile-label mb-2"><i class="bi bi-clipboard2-pulse me-1"></i><?php te('att.icd10.title'); ?></div>
                <?php if (!$tieneTablaIcd): ?>
                    <div class="alert alert-warning py-2 mb-0">
                        <?php te('att.icd10.needMig'); ?>
                        <a href="migrar_icd10_paciente_multi.php" class="alert-link"><?php te('att.icd10.needMigLink'); ?></a>.
                    </div>
                <?php else: ?>
                    <div id="icd10Chips" class="d-flex flex-wrap gap-2 mb-2"></div>
                    <div class="row g-2 align-items-center">
                        <div class="col-md-9">
                            <select id="icd10Select" class="form-select">
                                <option value=""></option>
                                <?php foreach ($catIcd10 as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['codigo'].' — '.$c['descripcion']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-grid">
                            <button type="button" class="btn btn-outline-primary" onclick="agregarIcd10()">
                                <i class="bi bi-plus-lg"></i> <?php te('att.icd10.add'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="form-text"><?php te('att.icd10.help'); ?></div>
                <?php endif; ?>

                <!-- Diagnóstico nutricional NCP/PES: insertar en la nota -->
                <hr class="my-3">
                <div class="att-tile-label mb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span><i class="bi bi-card-checklist me-1"></i><?php te('att.ncp.diagTitle'); ?></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="modal" data-bs-target="#modalNcpAyuda">
                        <i class="bi bi-question-circle"></i> <?php te('att.ncp.howto'); ?>
                    </button>
                </div>
                <?php if (!empty($ncpDiag)): ?>
                    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalNcp">
                        <i class="bi bi-clipboard2-plus"></i> <?php te('att.ncp.insert'); ?>
                    </button>
                    <div class="form-text"><?php te('att.ncp.help'); ?></div>
                <?php elseif (strtoupper($_SESSION['rol'] ?? '') === 'SISTEMA'): ?>
                    <div class="alert alert-warning py-2 mb-0">
                        <?php te('att.ncp.needMig'); ?>
                        <a href="migrar_ncp_diagnosticos.php" class="alert-link"><?php te('att.ncp.needMigLink'); ?></a>.
                    </div>
                <?php else: ?>
                    <div class="text-muted small"><?php te('att.ncp.needMigUser'); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── VALORACIÓN PEDIÁTRICA (auto, WHO 2-19 años) ───────── -->
        <div id="pedAssess" class="mb-3 d-none">
            <div class="card border-0" style="background:#e8f4f4;">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                        <div class="fw-bold text-uppercase small" style="color:#0e2c3a;letter-spacing:.05em;">
                            <i class="bi bi-clipboard2-pulse me-1"></i>
                            <?php te('att.ped.title'); ?>
                        </div>
                        <button type="button" id="btnInsertPed"
                                class="btn btn-sm btn-outline-primary"
                                onclick="insertarTablaPediatrica()">
                            <i class="bi bi-file-earmark-plus me-1"></i>
                            <?php te('att.ped.insertIntoReport'); ?>
                        </button>
                    </div>
                    <div id="pedTableWrap"></div>
                </div>
            </div>
        </div>

        <!-- ── BARRA DE DICTADO ────────────────────────────────────── -->
        <div class="d-flex align-items-center gap-3 mb-2 p-2 att-toolbar">
            <button type="button" id="btnMic"
                    class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1"
                    onclick="toggleDictado()">
                <i class="bi bi-mic-fill"></i> <?php te('att.startDictation'); ?>
            </button>

            <!-- Toggle idioma -->
            <div class="d-flex align-items-center gap-1" title="<?php te('att.micLanguage'); ?>">
                <span id="langES" class="badge"
                      style="cursor:pointer;background:#0264d6;font-size:.7rem;"
                      onclick="setLang('es')">ES</span>
                <span id="langEN" class="badge bg-secondary"
                      style="cursor:pointer;font-size:.7rem;"
                      onclick="setLang('en')">EN</span>
            </div>

            <div id="micStatus" class="d-none d-flex align-items-center gap-2">
                <span class="badge bg-danger d-flex align-items-center gap-1">
                    <span class="mic-pulse">●</span> <?php te('att.listening'); ?>
                </span>
                <small id="interimText" class="text-muted fst-italic"></small>
            </div>

            <small class="text-muted ms-auto d-none d-sm-inline">
                <i class="bi bi-info-circle me-1"></i>
                Chrome / Edge
            </small>
            <button type="button"
                    class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1"
                    data-bs-toggle="modal" data-bs-target="#modalDictadoAyuda"
                    title="<?php te('att.helpBtn'); ?>">
                <i class="bi bi-question-circle"></i>
                <span class="d-none d-md-inline"><?php te('att.helpBtn'); ?></span>
            </button>
        </div>

        <!-- ── AVISO: consulta anterior precargada ─────────────────── -->
        <div id="avisoPrevio" class="alert alert-info d-none align-items-center justify-content-between flex-wrap gap-2 py-2">
            <span><i class="bi bi-clock-history me-1"></i><span id="avisoPrevioTxt"></span></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="empezarEnBlanco()">
                <i class="bi bi-eraser"></i> <?php te('att.prev.blank'); ?>
            </button>
        </div>

        <!-- ── AVISO: borrador local recuperado ────────────────────── -->
        <div id="avisoBorrador" class="alert alert-warning d-none align-items-center justify-content-between flex-wrap gap-2 py-2">
            <span><i class="bi bi-arrow-counterclockwise me-1"></i><span id="avisoBorradorTxt"></span></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="descartarBorrador()">
                <i class="bi bi-x-circle"></i> <span id="btnDescartarBorradorTxt"></span>
            </button>
        </div>

        <!-- ── EDITOR ──────────────────────────────────────────────── -->
        <div class="mb-1 d-flex justify-content-end flex-wrap gap-2">
            <?php if (!empty($ncpDiag)): ?>
            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalNcp">
                <i class="bi bi-clipboard2-plus"></i> <?php te('att.ncp.insert'); ?>
            </button>
            <?php endif; ?>
            <button type="button" id="btnCargarPrevia" class="btn btn-sm btn-outline-primary d-none" onclick="cargarConsultaAnterior()">
                <i class="bi bi-arrow-clockwise"></i> <?php te('att.prev.load'); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertarEvolucionPeso()">
                <i class="bi bi-graph-up-arrow"></i> <?php te('att.insertWeightChart'); ?>
            </button>
        </div>
        <canvas id="chartEvolucionOculto" width="760" height="380" style="display:none;"></canvas>
        <div class="mb-1">
            <textarea id="editorInforme" name="informe"></textarea>
        </div>
        <div id="draftStatus" class="text-muted small mb-3 d-none">
            <i class="bi bi-cloud-check me-1"></i><span id="draftStatusTxt"></span>
        </div>

        <!-- ── BOTONES ─────────────────────────────────────────────── -->
        <div class="d-flex justify-content-between align-items-center att-actionbar flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="SCH_Calendar.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> <?php te('att.back'); ?>
                </a>
                <span class="badge rounded-pill" id="attTimeRange"
                      style="background:#eef1f6;color:#33475b;font-weight:600;font-size:.8rem;padding:.5rem .7rem;">
                    <i class="bi bi-clock me-1"></i>
                    <span id="attTimeText">—</span>
                </span>
                <div class="dropdown">
                    <button type="button" id="btnAjustarHora"
                            class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1"
                            data-bs-toggle="dropdown" data-bs-auto-close="outside"
                            aria-expanded="false" title="<?php te('att.adjustTip'); ?>">
                        <i class="bi bi-clock-history"></i>
                        <span><?php te('att.adjustBtn'); ?></span>
                    </button>
                    <div class="dropdown-menu p-3 shadow" style="min-width:260px;">
                        <label class="form-label small fw-semibold mb-1" for="inpHoraFin">
                            <?php te('att.adjustEndLabel'); ?>
                        </label>
                        <input type="time" id="inpHoraFin" class="form-control form-control-sm mb-2">
                        <div class="small text-muted mb-1"><?php te('att.adjustQuick'); ?></div>
                        <div class="btn-group btn-group-sm w-100 mb-2" role="group">
                            <button type="button" class="btn btn-outline-secondary" onclick="extenderCita(15)">+15</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="extenderCita(30)">+30</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="extenderCita(60)">+60</button>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="ajustarHoraFin()">
                                <i class="bi bi-check-lg"></i> <?php te('att.adjustSave'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="bootstrap.Dropdown.getInstance(document.getElementById('btnAjustarHora'))?.hide()">
                                <?php te('att.adjustCancel'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-outline-info" type="button" onclick="abrirCalculadora()">
                    <i class="bi bi-calculator"></i> <?php te('nc.title'); ?>
                </button>
                <button class="btn btn-outline-primary" onclick="imprimirInforme()">
                    <i class="bi bi-printer"></i> <?php te('att.previewPrint'); ?>
                </button>
                <button class="btn btn-success px-4" onclick="guardarAtencion()">
                    <i class="bi bi-file-earmark-check"></i> <?php te('att.saveFinish'); ?>
                </button>
            </div>
        </div>

    </div>
</div><!-- /main-card -->

<!-- ══ MODAL CALCULADORA NUTRICIONAL ══════════════════════════════════ -->
<div class="modal fade" id="modalNutriCalc" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#5a2d82;">
                <h6 class="modal-title text-white mb-0"><i class="bi bi-calculator me-2"></i><?php te('nc.title'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php include(__DIR__ . '/calculadora_nutricional_widget.php'); ?>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

            </div><!-- /app-main__inner -->
        </div><!-- /app-main__outer -->
    </div><!-- /app-main -->
</div><!-- /app-container -->

<script>
const DATOS_CITA = {
    idCita:          "<?php echo $idCita; ?>",
    idPaciente:      <?php echo (int)$idPacienteA; ?>,
    pacienteNombre:  "<?php echo addslashes(htmlspecialchars($d['NOMBRES'].' '.$d['APELLIDOS'])); ?>",
    pacienteDOB:     "<?php echo $d['FECHANACIMIENTO']; ?>",
    pacienteEmail:   "<?php echo addslashes($d['EMAIL']); ?>",
    pacienteTel:     "<?php echo addslashes($d['TELEFONO']); ?>",
    pacienteCedula:  "<?php echo addslashes($d['CEDULA']); ?>",
    pacienteSexIdx:  <?php echo (int)$pacienteSexIdx; ?>,
    horaInicio:      "<?php echo substr($d['HORA_INICIO'] ?? '', 0, 5); ?>",
    horaFin:         "<?php echo substr($d['HORA_FIN']    ?? '', 0, 5); ?>",
    docNombre:       "<?php echo addslashes($docNombreCompleto); ?>",
    docApellido:     "<?php echo addslashes($d['DOC_APELLIDOS']); ?>",
    docEspecialidad: "",
    atiendNombre:    "<?php echo addslashes($doctorAtiende); ?>",
    atiendApellido:  "<?php echo addslashes($sessionApellidos); ?>",
    agenciaNombre:   "<?php echo addslashes($d['AGENCIA_NOMBRE']); ?>",
    agenciaDirec:    "<?php echo addslashes($d['AGENCIA_DIRECCION']); ?>",
    agenciaTel:      "<?php echo addslashes($d['AGENCIA_TEL']); ?>",
    tipoConsulta:    "<?php echo addslashes($d['TIPO_CONSULTA']); ?>",
    fechaCita:       "<?php echo $d['FECHA_CITA'] ? date('m/d/Y', strtotime($d['FECHA_CITA'])) : ''; ?>",
    fechaHoy:        "<?php echo date('m/d/Y'); ?>",
    firmaNpi:        "<?php echo addslashes($firmaNpi); ?>",
    firmaLicense:    "<?php echo addslashes($firmaLicense); ?>",
    firmaImg:        <?php echo json_encode($firmaImg ?: ''); ?>,
    informeActual:   <?php echo json_encode($informeActual); ?>,
    prevInforme:     <?php echo json_encode($prevInforme); ?>,
    prevFecha:       <?php echo json_encode($prevFecha ? date('m/d/Y', strtotime($prevFecha)) : ''); ?>,
    prevTipo:        <?php echo json_encode($prevTipo); ?>
};

// ── Textos traducibles (i18n) ────────────────────────────────────────
const ATT = {
    snLang:            <?php echo json_encode($snLang); ?>,
    editorPlaceholder: <?php echo json_encode(t('att.js.editorPlaceholder')); ?>,
    prev: {
        loadedMsg:      <?php echo json_encode(t('att.prev.loadedMsg')); ?>,
        confirmReplace: <?php echo json_encode(t('att.prev.confirmReplace')); ?>,
        confirmBlank:   <?php echo json_encode(t('att.prev.confirmBlank')); ?>
    },
    icd10: {
        searchPh:   <?php echo json_encode(t('att.icd10.searchPh')); ?>,
        noResults:  <?php echo json_encode(t('att.icd10.noResults')); ?>,
        none:       <?php echo json_encode(t('att.icd10.none')); ?>,
        remove:     <?php echo json_encode(t('att.icd10.remove')); ?>,
        confirmDel: <?php echo json_encode(t('att.icd10.confirmDel')); ?>,
        saveError:  <?php echo json_encode(t('att.icd10.saveError')); ?>,
        connError:  <?php echo json_encode(t('common.js.connError')); ?>
    },
    ncp: {
        heading:     <?php echo json_encode(t('att.ncp.heading')); ?>,
        intervLabel: <?php echo json_encode(t('att.ncp.intervLabel')); ?>,
        monitLabel:  <?php echo json_encode(t('att.ncp.monitLabel')); ?>
    },
    templateLoadError: <?php echo json_encode(t('att.js.templateLoadError')); ?>,
    emptyReport:       <?php echo json_encode(t('att.js.emptyReport')); ?>,
    confirmFinish:     <?php echo json_encode(t('att.js.confirmFinish')); ?>,
    savedOk:           <?php echo json_encode(t('att.js.savedOk')); ?>,
    savedPartial:      <?php echo json_encode(t('att.js.savedPartial')); ?>,
    saveError:         <?php echo json_encode(t('att.js.saveError')); ?>,
    saveConnError:     <?php echo json_encode(t('att.js.saveConnError')); ?>,
    reportTitle:       <?php echo json_encode(t('att.js.reportTitle')); ?>,
    noSpeech:          <?php echo json_encode(t('att.js.noSpeech')); ?>,
    micDenied:         <?php echo json_encode(t('att.js.micDenied')); ?>,
    underweight:       <?php echo json_encode(t('att.js.underweight')); ?>,
    normal:            <?php echo json_encode(t('att.js.normal')); ?>,
    overweight:        <?php echo json_encode(t('att.js.overweight')); ?>,
    obesity:           <?php echo json_encode(t('att.js.obesity')); ?>,
    extendOk:          <?php echo json_encode(t('att.js.extendOk')); ?>,
    extendOutOfRange:  <?php echo json_encode(t('att.js.extendOutOfRange')); ?>,
    extendError:       <?php echo json_encode(t('att.js.extendError')); ?>,
    adjustOk:          <?php echo json_encode(t('att.js.adjustOk')); ?>,
    endBeforeStart:    <?php echo json_encode(t('att.js.endBeforeStart')); ?>,
    invalidTime:       <?php echo json_encode(t('att.js.invalidTime')); ?>,
    ped: {
        inserted:      <?php echo json_encode(t('att.js.pedInserted')); ?>
    },
    // Etiquetas pediátricas WHO (2-19)
    imc: {
        severeUnderweight: <?php echo json_encode(t('att.js.pedSevereUnderweight')); ?>,
        underweight:       <?php echo json_encode(t('att.js.pedUnderweight')); ?>,
        healthyWeight:     <?php echo json_encode(t('att.js.pedHealthy')); ?>,
        overweight:        <?php echo json_encode(t('att.js.pedOverweight')); ?>,
        obesity:           <?php echo json_encode(t('att.js.pedObesity')); ?>,
        severeObesity:     <?php echo json_encode(t('att.js.pedSevereObesity')); ?>
    },
    startDictation:    <?php echo json_encode(t('att.startDictation')); ?>,
    stopDictation:     <?php echo json_encode(t('att.stopDictation')); ?>,
    drTitle:           <?php echo json_encode(t('att.drTitle')); ?>,
    ph: {
        biochem:           <?php echo json_encode(t('att.ph.biochem')); ?>,
        physical:          <?php echo json_encode(t('att.ph.physical')); ?>,
        clientHistory:     <?php echo json_encode(t('att.ph.clientHistory')); ?>,
        nutritionPlan:     <?php echo json_encode(t('att.ph.nutritionPlan')); ?>,
        objectives:        <?php echo json_encode(t('att.ph.objectives')); ?>,
        recommendations:   <?php echo json_encode(t('att.ph.recommendations')); ?>,
        diagnosis:         <?php echo json_encode(t('att.ph.diagnosis')); ?>,
        treatment:         <?php echo json_encode(t('att.ph.treatment')); ?>,
        nutritionDiagnosis:<?php echo json_encode(t('att.ph.nutritionDiagnosis')); ?>,
        intervention:      <?php echo json_encode(t('att.ph.intervention')); ?>,
        monitoring:        <?php echo json_encode(t('att.ph.monitoring')); ?>,
        nutritionHistory:  <?php echo json_encode(t('att.ph.nutritionHistory')); ?>,
        pesDiagnosis:      <?php echo json_encode(t('att.ph.pesDiagnosis')); ?>,
        prescription:      <?php echo json_encode(t('att.ph.prescription')); ?>,
        actionPlan:        <?php echo json_encode(t('att.ph.actionPlan')); ?>,
        indicator:         <?php echo json_encode(t('att.ph.indicator')); ?>,
        goal:              <?php echo json_encode(t('att.ph.goal')); ?>,
        progress:          <?php echo json_encode(t('att.ph.progress')); ?>
    },
    draft: {
        saved:    <?php echo json_encode(t('att.draft.saved')); ?>,
        restored: <?php echo json_encode(t('att.draft.restored')); ?>,
        discard:  <?php echo json_encode(t('att.draft.discard')); ?>
    },
    weightChart: {
        noData: <?php echo json_encode(t('att.js.weightChartNoData')); ?>,
        title:  <?php echo json_encode(t('att.js.weightChartTitle')); ?>
    }
};
</script>

<!-- ── MODAL: ¿Cómo se usa el diagnóstico NCP/PES? ─────────────────── -->
<div class="modal fade" id="modalNcpAyuda" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-question-circle me-2"></i><?php te('att.ncp.howtoTitle'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (current_lang() === 'en'): ?>
          <p class="text-muted">Add a nutrition diagnosis (NCP/PES) to the note without memorizing the format.</p>
          <ol class="mb-3" style="line-height:1.8;">
            <li>In the <b>Diagnoses</b> card, click <b>Insert NCP/PES diagnosis</b>.</li>
            <li>Pick the <b>disease / case</b> (e.g. Obesity/MASLD, Type 2 Diabetes, CKD…).</li>
            <li>Check which parts to include: <b>PES statement</b> (Problem / related to / as evidenced by), <b>Intervention</b>, <b>Monitoring</b>.</li>
            <li>Check the <b>Preview</b>.</li>
            <li>Click <b>Insert into note</b> — the text drops into the report.</li>
            <li><b>Edit it in the note</b>: adjust the numbers and add the patient's details.</li>
            <li><b>Save the visit</b> as usual. The diagnosis stays in the patient's record.</li>
          </ol>
          <div class="alert alert-info py-2 mb-2"><b>ICD-10:</b> above, you can also search and <b>Add</b> one or more ICD-10 codes to the patient.</div>
          <div class="text-muted small"><b>Admin:</b> to add more diseases to the library, go to menu → <b>NCP/PES Diagnoses</b>.</div>
        <?php else: ?>
          <p class="text-muted">Añade un diagnóstico nutricional (NCP/PES) a la nota sin tener que memorizar el formato.</p>
          <ol class="mb-3" style="line-height:1.8;">
            <li>En la tarjeta de <b>Diagnósticos</b>, pulsa <b>Insertar diagnóstico NCP/PES</b>.</li>
            <li>Elige la <b>enfermedad / caso</b> (ej. Obesidad/MASLD, Diabetes tipo 2, ERC…).</li>
            <li>Marca qué partes incluir: <b>Enunciado PES</b> (Problema / relacionado con / evidenciado por), <b>Intervención</b>, <b>Monitoreo</b>.</li>
            <li>Revisa la <b>Vista previa</b>.</li>
            <li>Pulsa <b>Insertar en la nota</b> — el texto entra en el informe.</li>
            <li><b>Edítalo en la nota</b>: ajusta las cifras y agrega los datos del paciente.</li>
            <li><b>Guarda la atención</b> como siempre. El diagnóstico queda en la información del paciente.</li>
          </ol>
          <div class="alert alert-info py-2 mb-2"><b>ICD-10:</b> arriba también puedes buscar y <b>Añadir</b> uno o varios códigos ICD-10 al paciente.</div>
          <div class="text-muted small"><b>Admin:</b> para agregar más enfermedades a la biblioteca, ve al menú → <b>Diagnósticos NCP/PES</b>.</div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
      </div>
    </div>
  </div>
</div>

<!-- ── MODAL: ¿Cómo atender una cita? (guía para doctores) ──────────── -->
<div class="modal fade" id="modalAyudaAtencion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title"><i class="bi bi-clipboard2-pulse me-2"></i><?php te('help.attendTitle'); ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (current_lang() === 'en'): ?>
          <ol style="line-height:1.9;">
            <li><b>Weight &amp; height →</b> type them and the <b>BMI is calculated automatically</b>. You can switch units (kg/lbs, cm/m or ft+in). The patient's <b>previous weight/height</b> shows in the header.</li>
            <li><b>Previous note →</b> for follow-ups the <b>last visit's note is pre-loaded</b> so you only edit what changed.</li>
            <li><b>ICD-10 diagnoses →</b> search a code or description and press <b>Add</b>; add as many as needed. They stay in the patient's record.</li>
            <li><b>NCP/PES diagnosis →</b> click <b>Insert NCP/PES diagnosis</b>, pick the case, and it drops the ready-made statement into the note.</li>
            <li><b>Nutrition Calculator →</b> opens with the patient's data already filled. It computes <b>BMR, TEE, ideal &amp; adjusted weight, BMI, fluids (Holliday-Segar)</b>; in <b>Pediatric</b> mode: <b>EER, protein, fluids, fiber</b>; plus <b>heart-rate zones</b>, <b>muscle gain</b>, and a <b>manual per-kg</b> box. Press <b>Insert into report</b> on any card to drop it into the note.</li>
            <li><b>Report templates →</b> load a template; auto fields like <code>{{fecha_actual}}</code>, <code>{{paciente_dob}}</code>, <code>{{firma_nombre}}</code> fill in by themselves (dates in month/day/year).</li>
            <li><b>Finish →</b> <b>Save &amp; Finish Consultation</b> stores the note and marks the visit attended. <b>Preview &amp; Print</b> prints it.</li>
          </ol>
          <div class="alert alert-info py-2 mb-0"><b>Tip:</b> "Insert into report" always adds the block to the <b>end</b> of the note — you can then move or edit it.</div>
        <?php else: ?>
          <ol style="line-height:1.9;">
            <li><b>Peso y estatura →</b> escríbelos y el <b>IMC se calcula solo</b>. Puedes cambiar las unidades (kg/lbs, cm/m o pies+pulgadas). El <b>peso/talla anterior</b> del paciente aparece en el encabezado.</li>
            <li><b>Nota anterior →</b> en los seguimientos se <b>precarga la nota de la visita anterior</b>; solo editas lo que cambió.</li>
            <li><b>Diagnósticos ICD-10 →</b> busca un código o descripción y pulsa <b>Añadir</b>; agrega los que necesites. Quedan en la ficha del paciente.</li>
            <li><b>Diagnóstico NCP/PES →</b> pulsa <b>Insertar diagnóstico NCP/PES</b>, elige el caso y coloca el enunciado ya redactado en la nota.</li>
            <li><b>Calculadora nutricional →</b> abre con los datos del paciente ya cargados. Calcula <b>TMB (BMR), GET (TEE), peso ideal y ajustado, IMC, líquidos (Holliday-Segar)</b>; en modo <b>Pediátrico</b>: <b>EER, proteínas, líquidos, fibra</b>; además <b>zonas de frecuencia cardíaca</b>, <b>aumento muscular</b> y un cuadro <b>manual por kg</b>. Pulsa <b>Insertar en el informe</b> en cualquier tarjeta para pasarlo a la nota.</li>
            <li><b>Plantillas de informe →</b> carga una plantilla; los campos automáticos como <code>{{fecha_actual}}</code>, <code>{{paciente_dob}}</code>, <code>{{firma_nombre}}</code> se llenan solos (fechas en mes/día/año).</li>
            <li><b>Terminar →</b> <b>Guardar y finalizar</b> guarda la nota y marca la cita como atendida. <b>Vista previa e imprimir</b> la imprime.</li>
          </ol>
          <div class="alert alert-info py-2 mb-0"><b>Tip:</b> "Insertar en el informe" siempre agrega el bloque al <b>final</b> de la nota — después puedes moverlo o editarlo.</div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
      </div>
    </div>
  </div>
</div>
<script>(function(){var m=document.getElementById('modalAyudaAtencion');if(m&&m.parentNode!==document.body)document.body.appendChild(m);})();</script>

<?php if (!empty($ncpDiag)): ?>
<!-- ── MODAL: Insertar diagnóstico NCP/PES ─────────────────────────── -->
<div class="modal fade" id="modalNcp" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard2-pulse me-2"></i><?php te('att.ncp.modalTitle'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small fw-semibold"><?php te('att.ncp.select'); ?></label>
        <select id="ncpSelect" class="form-select mb-3" onchange="ncpPreview()">
          <option value=""><?php te('att.ncp.selectPh'); ?></option>
          <?php foreach ($ncpDiag as $n): ?>
          <option value="<?php echo (int)$n['id']; ?>"><?php echo h($n['enfermedad'].($n['codigo']?' ('.$n['codigo'].')':'')); ?></option>
          <?php endforeach; ?>
        </select>
        <div class="d-flex flex-wrap gap-3 mb-3">
          <div class="form-check"><input class="form-check-input" type="checkbox" id="ncpPES" checked onchange="ncpPreview()"><label class="form-check-label" for="ncpPES"><?php te('att.ncp.partPES'); ?></label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="ncpInterv" onchange="ncpPreview()"><label class="form-check-label" for="ncpInterv"><?php te('att.ncp.partInterv'); ?></label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="ncpMonit" onchange="ncpPreview()"><label class="form-check-label" for="ncpMonit"><?php te('att.ncp.partMonit'); ?></label></div>
        </div>
        <label class="form-label small fw-semibold"><?php te('att.ncp.preview'); ?></label>
        <div id="ncpPreview" class="border rounded p-2 bg-light" style="min-height:80px;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php te('common.cancel'); ?></button>
        <button type="button" class="btn btn-success" onclick="insertarNcp()"><i class="bi bi-check-lg"></i> <?php te('att.ncp.doInsert'); ?></button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript" src="./assets/scripts/main.js"></script>
<script src="js/who_growth.js"></script>
<script src="js/cdc_growth.js"></script>
<script src="js/nutri_calc.js?v=<?php echo @filemtime(__DIR__.'/js/nutri_calc.js'); ?>"></script>
<script src="js/nutri_calc_ui.js?v=<?php echo @filemtime(__DIR__.'/js/nutri_calc_ui.js'); ?>"></script>
<script>
// ── DIAGNÓSTICOS ICD-10 (asignar/quitar en la consulta) ──────────────
$(function(){
    if (!document.getElementById('icd10Select')) return; // migración no ejecutada
    $('#icd10Select').select2({
        placeholder: ATT.icd10.searchPh,
        allowClear: true,
        width: '100%',
        language: { noResults: function(){ return ATT.icd10.noResults; } }
    });
    renderIcd10Chips(<?php echo json_encode($icd10Paciente); ?>);
});

function renderIcd10Chips(list){
    var $c = $('#icd10Chips'); if(!$c.length) return; $c.empty();
    if (!list || !list.length){ $c.append($('<span class="text-muted small"></span>').text(ATT.icd10.none)); return; }
    list.forEach(function(it){
        var chip = $('<span class="icd10-chip"></span>');
        chip.append($('<span class="code"></span>').text(it.codigo));
        chip.append(document.createTextNode(' ' + (it.descripcion || '')));
        var btn = $('<button type="button">&times;</button>').attr('title', ATT.icd10.remove);
        btn.on('click', function(){ eliminarIcd10(it.id); });
        chip.append(btn);
        $c.append(chip);
    });
}

function agregarIcd10(){
    var id = parseInt($('#icd10Select').val() || '0', 10);
    if (!id) return;
    $.post('paciente_icd10.php', { accion:'agregar', idPaciente: DATOS_CITA.idPaciente, idIcd10: id }, function(res){
        if (res && res.ok){ renderIcd10Chips(res.lista); $('#icd10Select').val('').trigger('change'); }
        else { alert(ATT.icd10.saveError + (res && res.error ? res.error : '')); }
    }, 'json').fail(function(){ alert(ATT.icd10.connError); });
}

function eliminarIcd10(id){
    if (!confirm(ATT.icd10.confirmDel)) return;
    $.post('paciente_icd10.php', { accion:'eliminar', idPaciente: DATOS_CITA.idPaciente, idIcd10: id }, function(res){
        if (res && res.ok){ renderIcd10Chips(res.lista); }
        else { alert(ATT.icd10.saveError + (res && res.error ? res.error : '')); }
    }, 'json').fail(function(){ alert(ATT.icd10.connError); });
}

// ── DIAGNÓSTICOS NCP/PES (insertar en la nota) ───────────────────────
var NCP_LIST = <?php echo json_encode($ncpDiag); ?>;
var NCP_DATA = {}; NCP_LIST.forEach(function(n){ NCP_DATA[n.id] = n; });
function ncpEsc(s){ return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function ncpBuildHtml(){
    var sel = document.getElementById('ncpSelect'); if(!sel) return '';
    var n = NCP_DATA[sel.value]; if(!n) return '';
    var html = '';
    if (document.getElementById('ncpPES').checked){
        var titulo = ATT.ncp.heading + ' — ' + n.enfermedad + (n.codigo ? ' ('+n.codigo+')' : '');
        html += '<p><strong>'+ncpEsc(titulo)+'</strong></p><ul>';
        if(n.problema)  html += '<li><strong>P:</strong> '+ncpEsc(n.problema)+'</li>';
        if(n.etiologia) html += '<li><strong>E:</strong> '+ncpEsc(n.etiologia)+'</li>';
        if(n.signos)    html += '<li><strong>S:</strong> '+ncpEsc(n.signos)+'</li>';
        html += '</ul>';
    }
    if (document.getElementById('ncpInterv').checked && n.intervencion)
        html += '<p><strong>'+ncpEsc(ATT.ncp.intervLabel)+':</strong> '+ncpEsc(n.intervencion)+'</p>';
    if (document.getElementById('ncpMonit').checked && n.monitoreo)
        html += '<p><strong>'+ncpEsc(ATT.ncp.monitLabel)+':</strong> '+ncpEsc(n.monitoreo)+'</p>';
    return html;
}
function ncpPreview(){ var p=document.getElementById('ncpPreview'); if(p) p.innerHTML = ncpBuildHtml() || '<span class="text-muted small">—</span>'; }

// ── Insertar la gráfica de evolución de peso/IMC en el informe (como imagen) ──
var _chartEvolucion = null;
// Datos incrustados en la página (sin depender de una petición aparte).
var EVOL_PESO = {
    labels: <?php echo json_encode($evolLabels); ?>,
    pesos:  <?php echo json_encode($evolPesos); ?>,
    imcs:   <?php echo json_encode($evolImcs); ?>
};
function insertarEvolucionPeso(){
    (function(){
        var d = EVOL_PESO;
        if (!d || !d.labels || d.labels.length === 0) { alert(ATT.weightChart.noData); return; }
        if (typeof Chart === 'undefined') { window.open('evolucion_peso.php?idPaciente=' + encodeURIComponent(DATOS_CITA.idPaciente), '_blank'); return; }
        var canvas = document.getElementById('chartEvolucionOculto');
        if (_chartEvolucion) { try { _chartEvolucion.destroy(); } catch(e){} }
        // Plugin para fondo blanco (si no, la imagen sale con fondo transparente/negro al imprimir)
        var fondoBlanco = { id:'fondoBlanco', beforeDraw:function(c){ var ctx=c.canvas.getContext('2d'); ctx.save(); ctx.globalCompositeOperation='destination-over'; ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,c.width,c.height); ctx.restore(); } };
        _chartEvolucion = new Chart(canvas.getContext('2d'), {
            type:'line',
            data:{ labels:d.labels, datasets:[
                { label:'Peso (kg)', data:d.pesos, yAxisID:'yPeso', borderColor:'#3d5af1', backgroundColor:'rgba(61,90,241,.12)', tension:.3, spanGaps:true, pointRadius:3, fill:true },
                { label:'IMC', data:d.imcs, yAxisID:'yImc', borderColor:'#e67e22', backgroundColor:'rgba(230,126,34,.10)', tension:.3, spanGaps:true, pointRadius:3, fill:false }
            ]},
            options:{ responsive:false, animation:false, plugins:{ legend:{ position:'top' }, title:{ display:true, text:ATT.weightChart.title } },
                scales:{ yPeso:{ type:'linear', position:'left', title:{display:true,text:'Peso (kg)'} },
                         yImc:{ type:'linear', position:'right', title:{display:true,text:'IMC'}, grid:{drawOnChartArea:false} } } },
            plugins:[fondoBlanco]
        });
        // animation:false => ya está renderizada; tomar la imagen.
        var dataUrl = canvas.toDataURL('image/png', 1.0);
        var html = '<div style="text-align:center;margin:8px 0;">'
                 + '<img src="'+dataUrl+'" style="max-width:100%;height:auto;" alt="'+ATT.weightChart.title+'">'
                 + '<br><small>'+ATT.weightChart.title+'</small></div>';
        var $ed = $('#editorInforme');
        try {
            var cur = $ed.summernote('code') || '';
            $ed.summernote('code', cur + '<p><br></p>' + html);
        } catch (e) {
            try { $ed.summernote('pasteHTML', html); } catch (e2) {}
        }
        try { _chartEvolucion.destroy(); } catch(e){} _chartEvolucion = null;
    })();
}
function insertarNcp(){
    var html = ncpBuildHtml(); if(!html) return;
    var el = document.getElementById('modalNcp');
    var m = el ? bootstrap.Modal.getInstance(el) : null; if(m) m.hide();
    var $ed = $('#editorInforme');
    try {
        var cur = $ed.summernote('code') || '';
        $ed.summernote('code', cur + '<p><br></p>' + html);
        $ed.summernote('focus');
    } catch (e) {
        try { $ed.summernote('focus'); $ed.summernote('pasteHTML', html); } catch (e2) {}
    }
}
</script>
<script>

// ── INICIALIZAR EDITOR ───────────────────────────────────────────────
$(document).ready(function(){
    $('#editorInforme').summernote({
        placeholder: ATT.editorPlaceholder,
        tabsize: 2,
        height: 520,
        lang: ATT.snLang,
        toolbar: [
            ['style',  ['style']],
            ['font',   ['bold','underline','clear']],
            ['color',  ['color']],
            ['para',   ['ul','ol','paragraph']],
            ['table',  ['table']],
            ['insert', ['link','picture']],
            ['view',   ['fullscreen','codeview','help']]
        ]
    });

    // Precarga de contenido del informe:
    //  1) Si esta cita ya tiene nota guardada → cargarla (reabrir/editar atención).
    //  2) Si no, y el paciente tiene una consulta anterior → precargarla (follow-up).
    var actual = (DATOS_CITA.informeActual || '').trim();
    var previa = (DATOS_CITA.prevInforme   || '').trim();
    if (actual !== '') {
        $('#editorInforme').summernote('code', DATOS_CITA.informeActual);
    } else if (previa !== '') {
        $('#editorInforme').summernote('code', DATOS_CITA.prevInforme);
        var txt = ATT.prev.loadedMsg
            .replace('%d', DATOS_CITA.prevFecha || '')
            .replace('%t', DATOS_CITA.prevTipo || '');
        $('#avisoPrevioTxt').text(txt);
        $('#avisoPrevio').removeClass('d-none').addClass('d-flex');
    }
    // El botón para (re)cargar la consulta anterior sólo si existe una.
    if (previa !== '') $('#btnCargarPrevia').removeClass('d-none');

    // ── AUTOGUARDADO LOCAL (borrador) ────────────────────────────────
    // Si existe un borrador local para esta cita y difiere de lo cargado,
    // se restaura automáticamente (recupera lo que la Dra. estaba escribiendo
    // aunque se haya cerrado la sesión o la página).
    try {
        var raw = localStorage.getItem(_draftKey);
        if (raw) {
            var d = JSON.parse(raw);
            var cargado  = ($('#editorInforme').summernote('code') || '').replace(/\s+/g, '');
            var borrador = (d && d.html ? d.html : '').replace(/\s+/g, '');
            var borradorTxt = (d && d.html ? d.html : '').replace(/<[^>]*>/g, '').trim();
            if (borradorTxt.length > 0 && borrador !== cargado) {
                $('#editorInforme').summernote('code', d.html);
                $('#avisoBorradorTxt').text((ATT.draft.restored || '').replace('%t', _fmtHoraBorrador(d.ts)));
                document.getElementById('btnDescartarBorradorTxt').textContent = ATT.draft.discard || '';
                $('#avisoBorrador').removeClass('d-none').addClass('d-flex');
            }
        }
    } catch (e) {}

    // Guardar el borrador al escribir (con rebote) y periódicamente.
    $('#editorInforme').on('summernote.change', function () {
        clearTimeout(_draftTimer);
        _draftTimer = setTimeout(_guardarBorrador, 1200);
    });
    setInterval(_guardarBorrador, 5000);
    window.addEventListener('beforeunload', _guardarBorrador);
});

// ── Helpers de autoguardado local (borrador de la nota) ──────────────
var _draftKey   = 'att_draft_' + DATOS_CITA.idCita;
var _draftTimer = null;
function _fmtHoraBorrador(ts) {
    try { return new Date(ts).toLocaleTimeString(); } catch (e) { return ''; }
}
function _guardarBorrador() {
    try {
        var html = $('#editorInforme').summernote('code') || '';
        if (html.replace(/<[^>]*>/g, '').trim().length === 0) return;   // no guardar vacío
        var ts = Date.now();
        localStorage.setItem(_draftKey, JSON.stringify({ html: html, ts: ts }));
        var box = document.getElementById('draftStatus');
        var txt = document.getElementById('draftStatusTxt');
        if (box && txt) {
            txt.textContent = (ATT.draft.saved || '').replace('%t', _fmtHoraBorrador(ts));
            box.classList.remove('d-none');
        }
    } catch (e) {}
}
function descartarBorrador() {
    try { localStorage.removeItem(_draftKey); } catch (e) {}
    var b = document.getElementById('avisoBorrador');
    if (b) { b.classList.add('d-none'); b.classList.remove('d-flex'); }
}
function _limpiarBorrador() {
    try { localStorage.removeItem(_draftKey); } catch (e) {}
}

// Carga (o recarga) la nota de la consulta anterior en el editor.
function cargarConsultaAnterior(){
    var previa = (DATOS_CITA.prevInforme || '').trim();
    if (previa === '') return;
    var actual = ($('#editorInforme').summernote('code') || '').replace(/<[^>]*>/g,'').trim();
    if (actual !== '' && !confirm(ATT.prev.confirmReplace)) return;
    $('#editorInforme').summernote('code', DATOS_CITA.prevInforme);
}

// Vacía el editor para empezar la nota desde cero.
function empezarEnBlanco(){
    if (!confirm(ATT.prev.confirmBlank)) return;
    $('#editorInforme').summernote('code', '');
    $('#avisoPrevio').addClass('d-none').removeClass('d-flex');
}

// ── CALCULAR IMC ─────────────────────────────────────────────────────
// Edad del paciente en meses (a partir del DOB); null si no hay fecha válida.
function edadPacienteMeses(){
    var dobStr = DATOS_CITA.pacienteDOB || '';
    if (!/^\d{4}-\d{2}-\d{2}/.test(dobStr)) return null;
    var dob = new Date(dobStr + 'T00:00:00');
    if (isNaN(dob)) return null;
    var hoy = new Date();
    var meses = (hoy.getFullYear() - dob.getFullYear()) * 12 + (hoy.getMonth() - dob.getMonth());
    if (hoy.getDate() < dob.getDate()) meses -= 1;
    return meses;
}

// Última tabla pediátrica generada (para poder insertarla en el informe).
var _pedTablaHtml = null;

function renderPediatricAssessment(sexIdx, meses, pesoKg, tallaCm) {
    var wrap = document.getElementById('pedAssess');
    var body = document.getElementById('pedTableWrap');
    if (!wrap || !body) return;
    if (!window.WHO_GROWTH || meses == null || meses < 24 || meses > 228 || !(sexIdx === 0 || sexIdx === 1) || !(pesoKg > 0) || !(tallaCm > 0)) {
        wrap.classList.add('d-none');
        _pedTablaHtml = null;
        return;
    }
    var lang = (document.documentElement.lang || 'es').toLowerCase().slice(0, 2);
    var r = WHO_GROWTH.buildTable(sexIdx, meses, pesoKg, tallaCm, lang);
    if (!r || !r.html) { wrap.classList.add('d-none'); _pedTablaHtml = null; return; }
    body.innerHTML = r.html;
    _pedTablaHtml = r.html;
    wrap.classList.remove('d-none');
}

function insertarTablaPediatrica() {
    if (!_pedTablaHtml) return;
    var $ed = $('#editorInforme');
    if ($ed.length && $ed.summernote) {
        $ed.summernote('pasteHTML', '<div>' + _pedTablaHtml + '<br></div>');
        alert(ATT.ped.inserted);
    }
}

// ── Talla: helpers centralizados (soportan cm / m / ft+in) ───────────
function getTallaCm(){
    const u = $('input[name="unidadTalla"]:checked').val();
    if (u === 'ftin') {
        const ft   = parseFloat($('#tallaFt').val()) || 0;
        const inch = parseFloat($('#tallaIn').val()) || 0;
        return (ft * 12 + inch) * 2.54;
    }
    const v = parseFloat($('#talla').val()) || 0;
    return u === 'm' ? v * 100 : v;   // cm
}
function getTallaDisplay(){
    const u = $('input[name="unidadTalla"]:checked').val();
    if (u === 'ftin') {
        const ft   = parseFloat($('#tallaFt').val()) || 0;
        const inch = parseFloat($('#tallaIn').val()) || 0;
        if (!ft && !inch) return { text: '---', unit: '' };
        return { text: ft + "' " + inch + '"', unit: '' };
    }
    return { text: ($('#talla').val() || '---'), unit: u };
}
// Muestra/oculta los inputs según la unidad elegida
function toggleTallaUnidad(){
    const u = $('input[name="unidadTalla"]:checked').val();
    if (u === 'ftin') {
        document.getElementById('talla').style.display = 'none';
        document.getElementById('grpFtIn').style.setProperty('display','flex','important');
    } else {
        document.getElementById('talla').style.display = '';
        document.getElementById('grpFtIn').style.setProperty('display','none','important');
    }
    calcularIMC();
}

function calcularIMC(){
    const pesoInput  = parseFloat($('#peso').val());
    const uPeso  = $('input[name="unidadPeso"]:checked').val();
    const uTalla = $('input[name="unidadTalla"]:checked').val();
    const tallaCmVal = getTallaCm();

    var meses = edadPacienteMeses();
    var sexIdx = (typeof DATOS_CITA.pacienteSexIdx === 'number') ? DATOS_CITA.pacienteSexIdx : -1;

    if(!pesoInput || !tallaCmVal) { renderPediatricAssessment(sexIdx, meses, 0, 0); return; }
    const pesoKg  = uPeso  === 'lbs' ? pesoInput  * 0.453592 : pesoInput;
    const tallaM  = tallaCmVal / 100;
    const tallaCm = tallaCmVal;
    const imcNum  = pesoKg / (tallaM * tallaM);
    const imc     = imcNum.toFixed(2);
    $('#imc').val(imc);
    const est = $('#estado_imc');

    // Pediátrico (2-19 años): actualiza tarjeta con tabla PediTools-style + insignia.
    if (window.WHO_GROWTH && meses !== null && meses >= 24 && meses <= 228 && (sexIdx === 0 || sexIdx === 1)) {
        renderPediatricAssessment(sexIdx, meses, pesoKg, tallaCm);
        var r = WHO_BMI.classify(sexIdx, meses, imcNum);
        if (r) {
            // Regla adicional (AAP/CDC): IMC >= 35 kg/m² siempre es obesidad severa.
            if (imcNum >= 35 && r.key !== 'severeObesity') {
                r.key = 'severeObesity'; r.label = 'Severe obesity'; r.badgeClass = 'bg-dark';
            }
            var label = ATT.imc[r.key] || r.label;
            est.text(label).attr('class', 'badge p-2 d-block fs-6 ' + r.badgeClass)
               .attr('title', 'z=' + r.z.toFixed(2) + '  ·  P' + r.percentile.toFixed(1) + '  ·  WHO 2-19');
            return;
        }
    }
    // Adulto: oculta la tarjeta pediátrica
    renderPediatricAssessment(sexIdx, meses, 0, 0);

    // Adulto (>= 20 años) o pediátrico sin datos suficientes: clasificación adulto (OMS).
    est.removeAttr('title');
    if      (imcNum < 18.5) est.text(ATT.underweight).attr('class','badge p-2 d-block fs-6 bg-info');
    else if (imcNum < 25)   est.text(ATT.normal)     .attr('class','badge p-2 d-block fs-6 bg-success');
    else if (imcNum < 30)   est.text(ATT.overweight) .attr('class','badge p-2 d-block fs-6 bg-warning text-dark');
    else                    est.text(ATT.obesity)    .attr('class','badge p-2 d-block fs-6 bg-danger');
}

// Quita anchos/max-width fijos y márgenes-auto de los contenedores
// más externos del HTML de una plantilla, para que ocupe todo el ancho
// del editor en vez de verse encolumnada al centro.
function _expandirAnchoTemplate(html){
    var wrap = document.createElement('div');
    wrap.innerHTML = html;

    function limpiar(el){
        if (!el || !el.style) return;
        if (el.style.maxWidth) el.style.maxWidth = '';
        if (el.style.width && /^\s*\d+(\.\d+)?\s*px\s*$/i.test(el.style.width)) el.style.width = '';
        if (/auto/i.test(el.style.marginLeft) && /auto/i.test(el.style.marginRight)) {
            el.style.marginLeft = '';
            el.style.marginRight = '';
        }
        if (el.getAttribute && el.getAttribute('width') && /^\d+$/.test(el.getAttribute('width'))) {
            el.removeAttribute('width');
        }
    }

    // Nivel raíz y primer nivel (donde suele estar el wrapper max-width)
    Array.prototype.forEach.call(wrap.children, function(child){
        limpiar(child);
        if (child.tagName === 'DIV' || child.tagName === 'SECTION' || child.tagName === 'ARTICLE') {
            Array.prototype.forEach.call(child.children, limpiar);
        }
    });

    // <table> con width fijo → 100 %
    Array.prototype.forEach.call(wrap.querySelectorAll('table'), function(t){
        if (t.getAttribute('width') && /^\d+$/.test(t.getAttribute('width'))) t.removeAttribute('width');
        if (t.style && t.style.width && /^\s*\d+(\.\d+)?\s*px\s*$/i.test(t.style.width)) t.style.width = '100%';
        if (t.style && t.style.maxWidth) t.style.maxWidth = '';
    });

    return wrap.innerHTML;
}

// ── CARGAR PLANTILLA ─────────────────────────────────────────────────
function cargarPlantilla(id){
    if(!id) return;
    const uPeso  = $('input[name="unidadPeso"]:checked').val()  || 'kg';
    const uTalla = $('input[name="unidadTalla"]:checked').val() || 'cm';
    const pesoVal  = $('#peso').val()  || '---';
    const _tallaDisp = getTallaDisplay();
    const tallaVal  = _tallaDisp.text;   // "170.0" (cm/m) o "5' 7\"" (ft/in)
    const tallaUnit = _tallaDisp.unit;   // "cm"/"m" o "" (ft/in ya incluye símbolos)
    const imcVal   = $('#imc').val()   || '---';
    const fechaNacJS = new Date(DATOS_CITA.pacienteDOB + 'T00:00:00');
    const _pad2 = function(n){ return String(n).padStart(2,'0'); };
    const dobFormateada = isNaN(fechaNacJS.getTime()) ? ''
        : (_pad2(fechaNacJS.getMonth()+1) + '/' + _pad2(fechaNacJS.getDate()) + '/' + fechaNacJS.getFullYear());
    // Firma: nombre del profesional + credenciales (NPI, License ID)
    const credsLines = [];
    if (DATOS_CITA.firmaNpi)     credsLines.push('NPI: '        + DATOS_CITA.firmaNpi);
    if (DATOS_CITA.firmaLicense) credsLines.push('License ID: ' + DATOS_CITA.firmaLicense);
    const credsHtml = credsLines.length
        ? credsLines.map(function(l){ return '<span>' + l + '</span>'; }).join('<br>') + '<br>'
        : '';
    const firmaImgHtml = DATOS_CITA.firmaImg
        ? '<img src="' + DATOS_CITA.firmaImg + '" alt="firma" style="max-height:80px;max-width:260px;margin-bottom:4px;"><br>'
        : '';
    const firmaHtml = `<br><br>
        <div style="margin-top:40px;border-top:1px solid #ccc;padding-top:10px;font-family:Arial,sans-serif;">
            ${firmaImgHtml}<strong>${DATOS_CITA.atiendNombre}</strong><br>
            ${credsHtml}
        </div>`;
    // Edad y sexo del paciente para plantillas
    var _meses = edadPacienteMeses();
    var edadStr = '';
    if (_meses !== null && _meses >= 0) {
        var _y = Math.floor(_meses / 12);
        var _m = _meses % 12;
        edadStr = _y + ' año' + (_y === 1 ? '' : 's') + ' y ' + _m + ' mes' + (_m === 1 ? '' : 'es');
    }
    var sexoStr = (DATOS_CITA.pacienteSexIdx === 1) ? 'Femenino' : (DATOS_CITA.pacienteSexIdx === 0 ? 'Masculino' : '');

    $.ajax({
        url: 'get_plantilla_html.php', type: 'GET', data: { id: id },
        success: function(html){
            // ¿La plantilla ya trae su propio bloque de firma?
            // Marcadores: cualquiera de los placeholders de firma o los textos habituales.
            const yaTraeFirma = /\{\{firma_(nombre|credenciales|npi|licencia|imagen)\}\}|Electronically Signed By|Firmado electr[oó]nicamente/i.test(html);
            // Si la plantilla ya trae la firma pero NO tiene {{firma_imagen}}, colocamos
            // la imagen justo antes de {{firma_nombre}} (o de "Electronically Signed By")
            // para que aparezca encima del nombre del profesional.
            if (yaTraeFirma && firmaImgHtml && html.indexOf('{{firma_imagen}}') === -1) {
                if (html.indexOf('{{firma_nombre}}') !== -1) {
                    html = html.replace('{{firma_nombre}}', firmaImgHtml + '{{firma_nombre}}');
                } else {
                    html = html.replace(/(Electronically Signed By|Firmado electr[oó]nicamente por)/i, firmaImgHtml + '$1');
                }
            }
            const vars = {
                '{{fecha_actual}}': DATOS_CITA.fechaHoy,
                '{{fecha_evaluacion}}': DATOS_CITA.fechaHoy,
                '{{fecha_cita}}': DATOS_CITA.fechaCita,
                '{{hora_inicio}}': DATOS_CITA.horaInicio || '',
                '{{hora_fin}}': DATOS_CITA.horaFin || '',
                '{{edad_paciente}}': edadStr,
                '{{sexo_paciente}}': sexoStr,
                '{{paciente_nombre}}': DATOS_CITA.pacienteNombre,
                '{{paciente_dob}}': dobFormateada,
                '{{paciente_email}}': DATOS_CITA.pacienteEmail,
                '{{paciente_telefono}}': DATOS_CITA.pacienteTel,
                '{{paciente_cedula}}': DATOS_CITA.pacienteCedula,
                '{{doctor_nombre}}': DATOS_CITA.docNombre,
                '{{nombre_doctor}}': DATOS_CITA.docNombre,
                '{{apellido_doctor}}': DATOS_CITA.docApellido,
                '{{titulo_doctor}}': ATT.drTitle,
                '{{especialidad}}': DATOS_CITA.docEspecialidad,
                '{{firma_nombre}}': DATOS_CITA.atiendNombre,
                '{{firma_credenciales}}': credsLines.join(' · '),
                '{{firma_npi}}': DATOS_CITA.firmaNpi,
                '{{firma_licencia}}': DATOS_CITA.firmaLicense,
                '{{firma_imagen}}': firmaImgHtml,
                '{{practica_nombre}}': DATOS_CITA.agenciaNombre,
                '{{direccion_1}}': DATOS_CITA.agenciaDirec,
                '{{direccion_2}}': '',
                '{{ciudad}}': DATOS_CITA.agenciaNombre,
                '{{estado}}': '',
                '{{codigo_postal}}': '',
                '{{telefono_clinica}}': DATOS_CITA.agenciaTel,
                '{{tipo_consulta}}': DATOS_CITA.tipoConsulta,
                '{{diagnostico_referencia}}': DATOS_CITA.tipoConsulta,
                '{{peso}}': `${pesoVal} ${uPeso}`,
                '{{talla}}': `${tallaVal} ${tallaUnit}`.trim(),
                '{{imc}}': imcVal,
                '{{antropometria}}': `<?php echo t('att.weight'); ?>: ${pesoVal} ${uPeso}, <?php echo t('att.heightLbl'); ?>: ${tallaVal} ${tallaUnit}, <?php echo t('common.bmi'); ?>: ${imcVal}`,
                '{{bioquimica}}': ATT.ph.biochem,
                '{{hallazgos_fisicos}}': ATT.ph.physical,
                '{{historial_cliente}}': ATT.ph.clientHistory,
                '{{plan_nutricional}}': ATT.ph.nutritionPlan,
                '{{objetivos}}': ATT.ph.objectives,
                '{{recomendaciones}}': ATT.ph.recommendations,
                '{{diagnostico}}': ATT.ph.diagnosis,
                '{{tratamiento}}': ATT.ph.treatment,
                '{{diagnostico_nutricional}}': ATT.ph.nutritionDiagnosis,
                '{{intervencion}}': ATT.ph.intervention,
                '{{monitoreo}}': ATT.ph.monitoring,
                '{{historial_nutricional_seguimiento}}': ATT.ph.nutritionHistory,
                '{{datos_seguimiento}}': `<?php echo t('att.weight'); ?>: ${pesoVal} ${uPeso}, <?php echo t('att.heightLbl'); ?>: ${tallaVal} ${tallaUnit}, <?php echo t('common.bmi'); ?>: ${imcVal}`,
                '{{diagnostico_pes_seguimiento}}': ATT.ph.pesDiagnosis,
                '{{prescripcion_nutricional}}': ATT.ph.prescription,
                '{{plan_accion_seguimiento}}': ATT.ph.actionPlan,
                '{{monitoreo_indicador}}': ATT.ph.indicator,
                '{{monitoreo_meta}}': ATT.ph.goal,
                '{{monitoreo_progreso}}': ATT.ph.progress,
                '{{req_energia}}': '---',
                '{{ingesta_energia}}': '---',
                '{{req_proteina}}': '---',
                '{{ingesta_proteina}}': '---',
                '{{req_fluidos}}': '---',
                '{{ingesta_fluidos}}': '---',
            };
            for(const [tag, val] of Object.entries(vars)){
                const regex = new RegExp(tag.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),'g');
                html = html.replace(regex, val);
            }
            // Sólo agregamos el bloque de firma auto-generado si la plantilla NO
            // trae uno propio; en caso contrario ya se sustituyó/insertó dentro
            // del propio texto de la plantilla (ver `yaTraeFirma` arriba).
            if (!yaTraeFirma) {
                html += firmaHtml;
            }

            // Gráficos CDC (Height / Weight / BMI for age) para plantillas pediátricas
            // en pacientes de 2 a 20 años. Se insertan justo antes del bloque de firma.
            var esPediatric = /pediatric|pediátric/i.test(html);
            var pesoKg  = uPeso  === 'lbs' ? (parseFloat(pesoVal)  || 0) * 0.453592 : (parseFloat(pesoVal)  || 0);
            var tallaCm = getTallaCm();
            var imcNum  = (tallaCm > 0) ? (pesoKg / ((tallaCm/100)*(tallaCm/100))) : 0;
            if (esPediatric && window.CDC_GROWTH && _meses !== null && _meses >= 24 && _meses <= 240
                && (DATOS_CITA.pacienteSexIdx === 0 || DATOS_CITA.pacienteSexIdx === 1)) {
                var langChart = ATT.snLang && ATT.snLang.indexOf('es') === 0 ? 'es' : 'en';
                var partes = [
                    CDC_GROWTH.buildSvg({ kind:'stat', sexIdx:DATOS_CITA.pacienteSexIdx, ageMonths:_meses, value: tallaCm > 0 ? tallaCm : null, lang: langChart }),
                    CDC_GROWTH.buildSvg({ kind:'wt',   sexIdx:DATOS_CITA.pacienteSexIdx, ageMonths:_meses, value: pesoKg  > 0 ? pesoKg  : null, lang: langChart }),
                    CDC_GROWTH.buildSvg({ kind:'bmi',  sexIdx:DATOS_CITA.pacienteSexIdx, ageMonths:_meses, value: imcNum  > 0 ? imcNum  : null, lang: langChart })
                ];
                var graficosHtml = '<div class="cdc-charts" style="margin-top:16px;">'
                    + partes.map(function(svg){ return '<div style="margin:8px 0;">' + svg + '</div>'; }).join('')
                    + '</div>';
                // Insertar antes del texto "Electronically Signed By" / firma dibujada;
                // si no encuentra el marcador, va al final.
                if (/Electronically Signed By/i.test(html)) {
                    html = html.replace(/(Electronically Signed By)/i, graficosHtml + '$1');
                } else if (firmaImgHtml && html.indexOf(firmaImgHtml) !== -1) {
                    html = html.replace(firmaImgHtml, graficosHtml + firmaImgHtml);
                } else {
                    html += graficosHtml;
                }
            }

            html = _expandirAnchoTemplate(html);
            $('#editorInforme').summernote('code', html);
        },
        error: function(){ alert(ATT.templateLoadError); }
    });
}

// ── GUARDAR ATENCIÓN ─────────────────────────────────────────────────
function guardarAtencion(){
    const contenido = $('#editorInforme').summernote('code');
    if(contenido.replace(/<[^>]+>/g,'').trim().length < 10){
        alert(ATT.emptyReport);
        return;
    }
    const uPeso  = $('input[name="unidadPeso"]:checked').val()  || 'kg';
    let pesoVal  = parseFloat($('#peso').val())  || 0;
    const pesoKg  = uPeso  === 'lbs' ? pesoVal  * 0.453592 : pesoVal;
    const tallaCm = getTallaCm();   // canónico en cm (soporta cm/m/ft+in)
    const imcVal  = parseFloat($('#imc').val()) || 0;
    if(!confirm(ATT.confirmFinish)) return;
    $.ajax({
        url: 'guardar_atencion.php', type: 'POST',
        data: { idCita: DATOS_CITA.idCita, informe: contenido,
                peso: pesoKg.toFixed(2), talla: tallaCm.toFixed(1), imc: imcVal },
        success: function(res){
            var r = (res || '').trim();
            if (r === 'OK') {
                _limpiarBorrador();   // nota guardada en el servidor: ya no hace falta el borrador local
                alert(ATT.savedOk);
                window.location.href = 'SCH_Calendar.php';
            } else if (r.indexOf('PARCIAL:') === 0) {
                // Informe guardado, pero no se pudo marcar la cita como Atendida.
                _limpiarBorrador();
                alert(ATT.savedPartial + '\n\n' + r.substring(8).trim());
                window.location.href = 'SCH_Calendar.php';
            } else if (r === 'DATOS_INCOMPLETOS') {
                alert(ATT.emptyReport);
            } else if (r === 'SIN_SESION') {
                alert(ATT.saveConnError);
                window.location.href = 'index.php';
            } else {
                alert(ATT.saveError + ' ' + r);
            }
        },
        error: function(){ alert(ATT.saveConnError); }
    });
}

// ── AJUSTAR HORA FIN DE LA CITA ───────────────────────────────────────
function renderTimeRange(){
    var span = document.getElementById('attTimeText');
    if (!span) return;
    var ini = DATOS_CITA.horaInicio || '';
    var fin = DATOS_CITA.horaFin || '';
    if (!ini) { span.textContent = '—'; return; }
    span.textContent = fin ? (ini + '  →  ' + fin) : ini;
}

function _postAjuste(payload, feedbackOk){
    if (!DATOS_CITA.idCita) return;
    var btn = document.getElementById('btnAjustarHora');
    if (btn) btn.disabled = true;
    payload.idCita = DATOS_CITA.idCita;
    $.post('extender_cita.php', payload, function(res){
        if (btn) btn.disabled = false;
        var d = null;
        try { d = (typeof res === 'string') ? JSON.parse(res) : res; } catch(e){}
        if (d && d.ok) {
            DATOS_CITA.horaFin = d.horaFin;
            renderTimeRange();
            var inp = document.getElementById('inpHoraFin');
            if (inp) inp.value = d.horaFin;
            alert(feedbackOk.replace('{min}', d.minutos).replace('{fin}', d.horaFin));
            var dd = bootstrap.Dropdown.getInstance(btn);
            if (dd) dd.hide();
        } else {
            var err = (d && d.error) ? d.error : 'ERROR';
            if      (err === 'FUERA_DE_RANGO')   alert(ATT.extendOutOfRange);
            else if (err === 'FIN_ANTES_INICIO') alert(ATT.endBeforeStart);
            else if (err === 'HORA_INVALIDA')    alert(ATT.invalidTime);
            else if (err === 'SIN_SESION')       { alert(ATT.saveConnError); window.location.href='index.php'; }
            else                                   alert(ATT.extendError + ' ' + err);
        }
    }, 'json').fail(function(){
        if (btn) btn.disabled = false;
        alert(ATT.saveConnError);
    });
}

// Chips rápidos (+15 / +30 / +60) — mantiene el contrato antiguo
function extenderCita(min){
    _postAjuste({ minutos: (min || 30) }, ATT.extendOk);
}

// Hora fin exacta desde el input
function ajustarHoraFin(){
    var inp = document.getElementById('inpHoraFin');
    var val = inp ? inp.value : '';
    if (!/^([01]\d|2[0-3]):([0-5]\d)$/.test(val || '')) { alert(ATT.invalidTime); return; }
    _postAjuste({ horaFin: val }, ATT.adjustOk);
}

// Al abrir el dropdown, precargar el input con la hora fin actual
$(document).on('shown.bs.dropdown', function(ev){
    if (ev.target && ev.target.id === 'btnAjustarHora') {
        var inp = document.getElementById('inpHoraFin');
        if (inp && !inp.value) inp.value = DATOS_CITA.horaFin || '';
    }
});

// Renderizar rango al cargar la página
$(document).ready(renderTimeRange);

// ── CALCULADORA NUTRICIONAL ──────────────────────────────────────────
// Mover el modal a <body> para escapar del stacking context del
// .app-container (evita que el backdrop de Bootstrap tape el modal).
(function(){
    function moverAlBody(){
        var el = document.getElementById('modalNutriCalc');
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', moverAlBody);
    else moverAlBody();
})();

var _nutriCalcMounted = false;
function abrirCalculadora(){
    var modal = new bootstrap.Modal(document.getElementById('modalNutriCalc'));
    modal.show();
    if (!_nutriCalcMounted) {
        _nutriCalcMounted = true;
        NutriCalcUI.mount('#ncRoot', { lang: <?php echo json_encode(current_lang()); ?> });
    }
    // Prefill con datos del paciente actual
    var uPeso  = $('input[name="unidadPeso"]:checked').val()  || 'kg';
    var pesoV  = parseFloat($('#peso').val())  || 0;
    var pesoKg  = uPeso  === 'lbs' ? pesoV  * 0.453592 : pesoV;
    var tallaCm = getTallaCm();   // soporta cm/m/ft+in
    // Edad en años (desde meses). Para pediatría se conserva la fracción
    // (ej. 6 meses = 0.5 años) y se activa el modo pediátrico automáticamente.
    var meses = edadPacienteMeses();
    var ageExact = meses != null ? (meses / 12) : 0;
    var isPed = ageExact > 0 && ageExact < 18;
    var ageOut = isPed ? (Math.round(ageExact * 10) / 10) : Math.floor(ageExact);
    var sex = (DATOS_CITA.pacienteSexIdx === 1) ? 'F' : 'M';
    NutriCalcUI.prefill({ sex: sex, age: ageOut, weightKg: pesoKg, heightCm: tallaCm, mode: isPed ? 'ped' : 'adult' });
}
// Handler global que la calculadora invoca al pulsar "Insertar en el informe"
window._nutriInsertHandler = function(html){
    // Cerrar el modal primero: mientras está abierto, el editor no tiene el
    // cursor y summernote('pasteHTML') no inserta nada.
    var m = bootstrap.Modal.getInstance(document.getElementById('modalNutriCalc'));
    if (m) m.hide();
    var $ed = $('#editorInforme');
    // Inserción fiable: añade el bloque al final del informe (no depende del cursor).
    try {
        var cur = $ed.summernote('code') || '';
        $ed.summernote('code', cur + '<p><br></p>' + html);
        $ed.summernote('focus');
    } catch (e) {
        try { $ed.summernote('focus'); $ed.summernote('pasteHTML', html); } catch (e2) {}
    }
};

// ── IMPRIMIR ──────────────────────────────────────────────────────────
function imprimirInforme(){
    const contenido = $('#editorInforme').summernote('code');
    const ventana = window.open('','_blank','height=800,width=900');
    ventana.document.write(`<html><head>
        <title>${ATT.reportTitle} - ${DATOS_CITA.pacienteNombre}</title>
        <style>body{font-family:Arial,sans-serif;padding:40px;color:#333;}</style>
        </head><body>${contenido}</body></html>`);
    ventana.document.close();
    ventana.focus();
    setTimeout(() => ventana.print(), 500);
}

// ── DICTADO POR VOZ ───────────────────────────────────────────────────
let recognition   = null;
let dictadoActivo = false;
let savedRange    = null;   // guarda posición del cursor al iniciar dictado
let micLang       = 'es-EC';

function setLang(lang) {
    if (dictadoActivo) return; // no cambiar mientras escucha
    if (lang === 'es') {
        micLang = 'es-EC';
        document.getElementById('langES').style.background = '#0264d6';
        document.getElementById('langES').classList.remove('bg-secondary');
        document.getElementById('langEN').style.background = '';
        document.getElementById('langEN').classList.add('bg-secondary');
    } else {
        micLang = 'en-US';
        document.getElementById('langEN').style.background = '#198754';
        document.getElementById('langEN').classList.remove('bg-secondary');
        document.getElementById('langES').style.background = '';
        document.getElementById('langES').classList.add('bg-secondary');
    }
}

function toggleDictado() {
    if (!dictadoActivo) {
        iniciarDictado();
    } else {
        detenerDictado();
    }
}

function iniciarDictado() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) {
        alert(ATT.noSpeech);
        return;
    }

    // Guardar posición actual del cursor en el editor antes de empezar
    var sel = window.getSelection();
    savedRange = (sel && sel.rangeCount > 0) ? sel.getRangeAt(0).cloneRange() : null;

    recognition = new SR();
    recognition.lang         = micLang;
    recognition.continuous   = true;       // sigue escuchando sin timeout
    recognition.interimResults = true;     // muestra texto parcial en tiempo real

    recognition.onresult = function(event) {
        let interim = '';
        let finalText = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
            if (event.results[i].isFinal) {
                finalText += event.results[i][0].transcript + ' ';
            } else {
                interim += event.results[i][0].transcript;
            }
        }
        // Mostrar texto parcial en vivo
        document.getElementById('interimText').textContent = interim;

        // Insertar texto final en el editor Summernote
        if (finalText) {
            var $editable = $('.note-editable').first();
            $editable[0].focus();

            // Restaurar posición guardada; si no hay, ir al final
            var sel2 = window.getSelection();
            sel2.removeAllRanges();
            if (savedRange) {
                try { sel2.addRange(savedRange); } catch(e) { savedRange = null; }
            }
            if (!savedRange || sel2.rangeCount === 0) {
                var range = document.createRange();
                range.selectNodeContents($editable[0]);
                range.collapse(false);
                sel2.removeAllRanges();
                sel2.addRange(range);
            }

            $('#editorInforme').summernote('insertText', finalText);

            // Actualizar savedRange a la nueva posición (después del texto insertado)
            var sel3 = window.getSelection();
            savedRange = (sel3 && sel3.rangeCount > 0) ? sel3.getRangeAt(0).cloneRange() : null;

            document.getElementById('interimText').textContent = '';
        }
    };

    recognition.onerror = function(event) {
        if (event.error === 'no-speech') return; // silencio temporal, ignorar
        detenerDictado();
        if (event.error === 'not-allowed') {
            alert(ATT.micDenied);
        } else {
            console.warn('Error de reconocimiento:', event.error);
        }
    };

    // Si el browser detiene el reconocimiento y sigue activo → reiniciar
    recognition.onend = function() {
        if (dictadoActivo) recognition.start();
    };

    recognition.start();
    dictadoActivo = true;

    // UI: estado activo
    document.getElementById('btnMic').innerHTML =
        '<i class="bi bi-mic-mute-fill"></i> ' + ATT.stopDictation;
    document.getElementById('btnMic').className =
        'btn btn-danger btn-sm d-flex align-items-center gap-1';
    document.getElementById('micStatus').classList.remove('d-none');
}

function detenerDictado() {
    dictadoActivo = false;
    if (recognition) { recognition.stop(); recognition = null; }

    // UI: estado inactivo
    document.getElementById('btnMic').innerHTML =
        '<i class="bi bi-mic-fill"></i> ' + ATT.startDictation;
    document.getElementById('btnMic').className =
        'btn btn-outline-danger btn-sm d-flex align-items-center gap-1';
    document.getElementById('micStatus').classList.add('d-none');
    document.getElementById('interimText').textContent = '';
}
</script>

<!-- ── MODAL: Instrucciones de uso del dictado por voz ─────────── -->
<div class="modal fade" id="modalDictadoAyuda" tabindex="-1" aria-labelledby="modalDictadoAyudaLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0e1f55,#1a3a8c);color:#fff;">
                <h5 class="modal-title d-flex align-items-center gap-2" id="modalDictadoAyudaLabel">
                    <i class="bi bi-mic-fill"></i> <?php te('att.help.title'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php te('common.close'); ?>"></button>
            </div>
            <div class="modal-body">

                <!-- Requisitos -->
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-check2-circle me-1"></i><?php te('att.help.req.title'); ?></h6>
                <ul class="mb-3">
                    <li><?php te('att.help.req.browser'); ?></li>
                    <li><?php te('att.help.req.mic'); ?></li>
                    <li><?php te('att.help.req.https'); ?></li>
                </ul>

                <!-- Pasos -->
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-list-ol me-1"></i><?php te('att.help.steps.title'); ?></h6>
                <ol class="mb-3">
                    <li><?php te('att.help.steps.s1'); ?></li>
                    <li><?php te('att.help.steps.s2'); ?></li>
                    <li><?php te('att.help.steps.s3'); ?></li>
                    <li><?php te('att.help.steps.s4'); ?></li>
                    <li><?php te('att.help.steps.s5'); ?></li>
                </ol>

                <!-- Sugerencias generales -->
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-lightbulb me-1"></i><?php te('att.help.tips.title'); ?></h6>
                <ul class="mb-3">
                    <li><?php te('att.help.tips.t1'); ?></li>
                    <li><?php te('att.help.tips.t2'); ?></li>
                    <li><?php te('att.help.tips.t3'); ?></li>
                    <li><?php te('att.help.tips.t4'); ?></li>
                    <li><?php te('att.help.tips.t5'); ?></li>
                </ul>

                <!-- Uso desde el celular -->
                <div class="alert alert-info d-flex gap-2 align-items-start mb-3">
                    <i class="bi bi-phone fs-4 flex-shrink-0"></i>
                    <div>
                        <div class="fw-bold mb-1"><?php te('att.help.mobile.title'); ?></div>
                        <ul class="mb-0 ps-3">
                            <li><?php te('att.help.mobile.m1'); ?></li>
                            <li><?php te('att.help.mobile.m2'); ?></li>
                            <li><?php te('att.help.mobile.m3'); ?></li>
                            <li><?php te('att.help.mobile.m4'); ?></li>
                            <li><?php te('att.help.mobile.m5'); ?></li>
                        </ul>
                    </div>
                </div>

                <!-- Problemas comunes -->
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-exclamation-triangle me-1"></i><?php te('att.help.trouble.title'); ?></h6>
                <ul class="mb-0">
                    <li><strong><?php te('att.help.trouble.p1.title'); ?>:</strong> <?php te('att.help.trouble.p1.body'); ?></li>
                    <li><strong><?php te('att.help.trouble.p2.title'); ?>:</strong> <?php te('att.help.trouble.p2.body'); ?></li>
                    <li><strong><?php te('att.help.trouble.p3.title'); ?>:</strong> <?php te('att.help.trouble.p3.body'); ?></li>
                    <li><strong><?php te('att.help.trouble.p4.title'); ?>:</strong> <?php te('att.help.trouble.p4.body'); ?></li>
                </ul>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php if ($puedeOrdenar || $verExamenes): ?>
<script>
function exAtencionPost(fd, enOk) {
    fetch('examen_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        if (j.ok) location.reload(); else alert(j.error === 'EXAMEN_REQUERIDO' ? <?php echo json_encode(current_lang()==='en' ? 'Enter the exam name.' : 'Escribe el nombre del examen.'); ?> : <?php echo json_encode(current_lang()==='en' ? 'Could not save.' : 'No se pudo guardar.'); ?>);
    });
}
function exAtencionCrear() {
    var fd = new FormData(); fd.append('accion','crear'); fd.append('idPaciente', <?php echo (int)$idPacienteA; ?>); fd.append('idCita', <?php echo (int)$idCitaInt; ?>);
    fd.append('tipo', document.getElementById('exTipo').value); fd.append('examen', document.getElementById('exNombre').value); fd.append('indicacion', document.getElementById('exIndic').value);
    exAtencionPost(fd);
}
function exAtencionRealizado(id) {
    var res = prompt(<?php echo json_encode(current_lang()==='en' ? 'Result / observation (optional):' : 'Resultado u observación (opcional):'); ?>, '');
    if (res === null) return;
    var fd = new FormData(); fd.append('accion','realizar'); fd.append('idExamen', id); fd.append('resultado', res); exAtencionPost(fd);
}
</script>
<?php endif; ?>
<?php if ($puedeRegistro): ?>
<script>
(function(){
    var en = <?php echo json_encode($enR); ?>, idCita = <?php echo (int)$idCitaInt; ?>;
    function filas(){ return Array.prototype.slice.call(document.querySelectorAll('#rgFilas .rg-fila')); }
    function val(f, k){ var e = f.querySelector('[data-k="'+k+'"]'); return e ? e.value : ''; }
    window.rgAgregar = function(){
        var base = document.querySelector('#rgFilas .rg-fila'); if (!base) return;
        var n = base.cloneNode(true); n.querySelectorAll('textarea,input').forEach(function(e){ e.value = ''; });
        document.getElementById('rgFilas').appendChild(n);
    };
    window.rgQuitar = function(btn){
        var f = btn.closest('.rg-fila');
        if (filas().length > 1) f.remove(); else f.querySelectorAll('textarea,input').forEach(function(e){ e.value = ''; });
    };
    function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML.replace(/\n/g,'<br>'); }
    window.rgGuardar = function(){
        var fd = new FormData(); fd.append('idCita', idCita);
        fd.append('servicio', document.getElementById('rgServicio').value); fd.append('fecha', document.getElementById('rgFecha').value);
        fd.append('tratante', document.getElementById('rgTratante').value);
        filas().forEach(function(f, i){ ['indicacion','cantidad','recomendacion'].forEach(function(k){ fd.append('items['+i+']['+k+']', val(f,k)); }); });
        var m = document.getElementById('rgMsg'), b = document.getElementById('rgGuardar'); b.disabled = true; m.className = 'small text-muted'; m.textContent = '…';
        fetch('registro_atencion_guardar.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
            b.disabled = false;
            if (j.ok) { m.className = 'small text-success'; m.textContent = en ? 'Saved.' : 'Registro guardado.'; }
            else { m.className = 'small text-danger'; m.textContent = (en ? 'Could not save: ' : 'No se pudo guardar: ') + (j.error || ''); }
        }).catch(function(){ b.disabled = false; m.className = 'small text-danger'; m.textContent = en ? 'Connection error.' : 'Error de conexión.'; });
    };
    window.rgInsertarEnNota = function(){
        var rows = '';
        filas().forEach(function(f){
            var a = val(f,'indicacion'), c = val(f,'cantidad'), r = val(f,'recomendacion');
            if (!a && !c && !r) return;
            rows += '<tr><td style="border:1px solid #999;padding:4px;vertical-align:top;">'+esc(a)+'</td><td style="border:1px solid #999;padding:4px;vertical-align:top;">'+esc(c)+'</td><td style="border:1px solid #999;padding:4px;vertical-align:top;">'+esc(r)+'</td></tr>';
        });
        if (!rows) { alert(en ? 'There are no rows to insert.' : 'No hay filas para insertar.'); return; }
        var html = '<p><b>'+(en?'Service':'Servicio')+':</b> '+esc(document.getElementById('rgServicio').value)+' &nbsp; <b>'+(en?'Date':'Fecha')+':</b> '+esc(document.getElementById('rgFecha').value)+' &nbsp; <b>'+(en?'Provider':'Tratante')+':</b> '+esc(document.getElementById('rgTratante').value)+'</p>'
            + '<table style="border-collapse:collapse;width:100%;"><thead><tr><th style="border:1px solid #999;padding:4px;">'+(en?'Instructions':'Indicaciones')+'</th><th style="border:1px solid #999;padding:4px;width:90px;">'+(en?'Quantity':'Cantidad')+'</th><th style="border:1px solid #999;padding:4px;">'+(en?'Recommendations':'Recomendaciones')+'</th></tr></thead><tbody>'+rows+'</tbody></table><p><br></p>';
        var $ed = $('#editorInforme');
        try { $ed.summernote('code', ($ed.summernote('code') || '') + html); } catch(e) { try { $ed.summernote('pasteHTML', html); } catch(e2) {} }
    };
})();
</script>
<?php endif; ?>
<?php if ($modDeriva && puede('derivar.crear')): $enM = (current_lang() === 'en'); ?>
<div class="modal fade" id="modalDerivar" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-arrow-right-circle me-1"></i><?php echo $enM ? 'Refer patient' : 'Derivar paciente'; ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="btn-group w-100 mb-3" role="group">
            <input type="radio" class="btn-check" name="derTipo" id="derTipoP" value="PROFESIONAL" checked>
            <label class="btn btn-outline-primary" for="derTipoP"><i class="bi bi-person-badge"></i> <?php echo $enM ? 'Another professional' : 'Otro profesional'; ?></label>
            <input type="radio" class="btn-check" name="derTipo" id="derTipoS" value="SUERO">
            <label class="btn btn-outline-primary" for="derTipoS"><i class="bi bi-droplet-half"></i> <?php echo $enM ? 'IV therapy' : 'Colocación de sueros'; ?></label>
        </div>
        <div id="derBloqueP" class="mb-3">
            <label class="form-label"><?php echo $enM ? 'Professional' : 'Profesional'; ?></label>
            <select id="derDoctor" class="form-select"><option value=""><?php echo $enM ? 'Select…' : 'Seleccionar…'; ?></option>
                <?php foreach ($doctoresDerivar as $dd): ?><option value="<?php echo (int)$dd['id']; ?>"><?php echo h($dd['nombre']); ?></option><?php endforeach; ?></select>
            <div class="form-text"><?php echo $enM ? 'An appointment for today is created for them, with the shared medical history.' : 'Se crea una cita de hoy para el profesional, con la historia clínica compartida.'; ?></div>
        </div>
        <div id="derBloqueS" class="mb-3" style="display:none;">
            <label class="form-label"><?php echo $enM ? 'Orders' : 'Órdenes de suero'; ?></label>
            <div id="derItems"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="derAddItem"><i class="bi bi-plus"></i> <?php echo $enM ? 'Add order' : 'Agregar orden'; ?></button>
        </div>
        <label class="form-label"><?php echo $enM ? 'Reason / notes' : 'Motivo / indicaciones'; ?></label>
        <textarea id="derMotivo" class="form-control" rows="3"></textarea>
        <div id="derError" class="text-danger small mt-2"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $enM ? 'Cancel' : 'Cancelar'; ?></button>
        <button type="button" class="btn btn-primary" id="derEnviar"><i class="bi bi-send"></i> <?php echo $enM ? 'Refer' : 'Derivar'; ?></button></div>
</div></div></div>
<script>
(function(){
    var en = <?php echo json_encode($enM); ?>, idCita = <?php echo (int)$idCitaInt; ?>;
    var items = document.getElementById('derItems');
    var errores = {MOTIVO_REQUERIDO: en?'Enter the reason.':'Escribe el motivo.', PROFESIONAL_REQUERIDO: en?'Select the professional.':'Selecciona el profesional.',
        SUERO_REQUERIDO: en?'Add at least one order.':'Agrega al menos una orden.', SIN_PERMISO: en?'No permission.':'Sin permiso.', MISMO_PROFESIONAL: en?'Choose a different professional.':'Elige otro profesional.'};
    function addItem(){
        var d = document.createElement('div'); d.className = 'row g-2 mb-2 der-item';
        d.innerHTML = '<div class="col-md-4"><input class="form-control form-control-sm" data-k="producto" placeholder="'+(en?'IV product':'Suero / producto')+'"></div>'
            + '<div class="col-md-3"><input class="form-control form-control-sm" data-k="dosis" placeholder="'+(en?'Dose':'Dosis')+'"></div>'
            + '<div class="col-md-2"><input class="form-control form-control-sm" data-k="via" placeholder="'+(en?'Route':'Vía')+'"></div>'
            + '<div class="col-md-3"><input class="form-control form-control-sm" data-k="indicaciones" placeholder="'+(en?'Instructions':'Indicaciones')+'"></div>';
        items.appendChild(d);
    }
    document.getElementById('derAddItem').onclick = addItem; addItem();
    function tipo(){ return document.querySelector('input[name="derTipo"]:checked').value; }
    function toggle(){ var s = tipo()==='SUERO'; document.getElementById('derBloqueP').style.display = s?'none':''; document.getElementById('derBloqueS').style.display = s?'':'none'; }
    document.querySelectorAll('input[name="derTipo"]').forEach(function(r){ r.onchange = toggle; });
    document.getElementById('derEnviar').onclick = function(){
        var fd = new FormData(); fd.append('idCita', idCita); fd.append('tipo', tipo());
        fd.append('motivo', document.getElementById('derMotivo').value);
        fd.append('idDoctorDestino', document.getElementById('derDoctor').value);
        items.querySelectorAll('.der-item').forEach(function(row, i){
            row.querySelectorAll('input').forEach(function(inp){ fd.append('items['+i+']['+inp.dataset.k+']', inp.value); });
        });
        var btn = this; btn.disabled = true;
        fetch('derivar_guardar.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
            btn.disabled = false;
            if (j.ok) { location.reload(); }
            else { document.getElementById('derError').textContent = errores[j.error] || ((en?'Could not refer: ':'No se pudo derivar: ') + j.error); }
        }).catch(function(){ btn.disabled = false; document.getElementById('derError').textContent = en?'Connection error.':'Error de conexión.'; });
    };
})();
</script>
<?php endif; ?>
<?php if ($triajeCita): ?>
<script>
// Precarga peso/talla del triaje (en kg/cm) si la atención aún no los tiene
$(function(){
    var tp = <?php echo json_encode($triajeCita['PESO_KG'] !== null ? (float)$triajeCita['PESO_KG'] : null); ?>,
        tt = <?php echo json_encode($triajeCita['TALLA_CM'] !== null ? (float)$triajeCita['TALLA_CM'] : null); ?>;
    if (tp && !$('#peso').val()) { $('#uKg').prop('checked', true); $('#peso').val(tp); }
    if (tt && !$('#talla').val()) { $('#uCm').prop('checked', true); toggleTallaUnidad(); $('#talla').val(tt); }
    calcularIMC();
});
</script>
<?php endif; ?>
</body>
</html>
