<?php
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
$conexion = conectarse();

if(!isset($_SESSION["rol"])){
    header("Location: break.php");
    exit();
}

$now = time();
if(isset($_SESSION['expire']) && $now > $_SESSION['expire']){
    session_destroy();
    header("Location: expirada.php");
    exit();
}

$colorTipoDefault = '#3788d8';

// ¿Existe la columna IDSERIE? (la agrega migrar_serie_cita.php)
$dbNameCita = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
$tieneSerieCita = (int)$conexion->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='$dbNameCita' AND TABLE_NAME='AG_CITA' AND COLUMN_NAME='IDSERIE'"
)->fetch_assoc()['c'] > 0;
$selIdSerie = $tieneSerieCita ? ', A.IDSERIE' : ", NULL AS IDSERIE";

$query = "SELECT
            A.IDCITA,
            A.IDPACIENTE,
            A.IDTIPOCONSULTA,
            A.IDDOCTOR,
            A.IDAGENCIA
            $selIdSerie,
            CONCAT(B.NOMBRES, ' ', B.APELLIDOS) AS PACIENTE,
            B.TELEFONO,
            C.NOMBRES AS TIPO_CONSULTA,
            C.COLOR AS TIPO_COLOR,
            A.FECHA_CITA,
            A.HORA_INICIO,
            A.HORA_FIN,
            A.ESTADO_CITA,
            A.COMENTARIO,
            CONCAT(D.NOMBRES, ' ', D.APELLIDOS) AS DOCTOR,
            E.DESCRIPCION AS AGENCIA
          FROM AG_CITA A
          INNER JOIN AG_PACIENTE B     ON A.IDPACIENTE      = B.IDPACIENTE
          INNER JOIN AG_TIPOCONSULTA C ON A.IDTIPOCONSULTA  = C.IDTIPOCONSULTA
          INNER JOIN ADM_USUARIO D     ON A.IDDOCTOR        = D.IDADM_USUARIO
          LEFT  JOIN ADM_AGENCIA E     ON A.IDAGENCIA       = E.IDAGENCIA
          WHERE A.ESTADO = 'A'
            AND A.FECHA_CITA >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
// Nota: el calendario solo carga citas de los últimos 3 meses en adelante para
// no traer miles de citas históricas (causaba lentitud/timeout). Las citas
// antiguas siguen visibles en el historial del paciente.

$resultado = $conexion->query($query);
$eventos   = array();

// Colores de estado (editables desde gestionar_colores_estado.php).
// Se cargan de la tabla estado_cita_colores si existe; si no, se usan los
// valores por defecto históricos.
$colDefault = [
    'pendiente'             => '#3b4252',
    'confirmada'            => '#6f42c1',
    'atendida'              => '#28a745',
    'cancelada'             => '#fd7e14',
    'cancelacion_tardia'    => '#ffc107',
    'cancelado_profesional' => '#ff8a80',
    'no_asistio'            => '#dc3545',
    'no_contesto'           => '#6c757d',
    'default'               => '#007bff',
];
$colEstado = $colDefault;
$txtEstado = [];  // clave => color de letra

// 1) Paleta elegida por el usuario actual (sistema de paletas por usuario)
$paletaCargada = false;
if (($conexion->query("SHOW TABLES LIKE 'paletas_estado'")->num_rows ?? 0) > 0) {
    $idUserCal = (int)($_SESSION['iduser'] ?? 0);
    $idPal = 0;
    if ($idUserCal > 0) {
        $rq = $conexion->query("SELECT IDPALETA_ESTADO FROM ADM_USUARIO WHERE IDADM_USUARIO=$idUserCal LIMIT 1");
        if ($rq && ($rr=$rq->fetch_assoc())) $idPal = (int)($rr['IDPALETA_ESTADO'] ?? 0);
    }
    // Si el usuario no eligió, usar la paleta de sistema "Profesional"
    if ($idPal <= 0) {
        $rq = $conexion->query("SELECT id FROM paletas_estado WHERE es_sistema=1 AND nombre='Profesional' LIMIT 1");
        if ($rq && ($rr=$rq->fetch_assoc())) $idPal = (int)$rr['id'];
    }
    if ($idPal > 0) {
        $rc = $conexion->query("SELECT clave, color, text_color FROM paleta_estado_colores WHERE id_paleta=$idPal");
        if ($rc && $rc->num_rows > 0) {
            while ($x = $rc->fetch_assoc()) {
                $colEstado[$x['clave']] = $x['color'];
                if (!empty($x['text_color'])) $txtEstado[$x['clave']] = $x['text_color'];
            }
            $paletaCargada = true;
        }
    }
}
// 2) Fallback: tabla global estado_cita_colores (compatibilidad)
if (!$paletaCargada && ($conexion->query("SHOW TABLES LIKE 'estado_cita_colores'")->num_rows ?? 0) > 0) {
    $hayTxt = ($conexion->query("SHOW COLUMNS FROM estado_cita_colores LIKE 'text_color'")->num_rows ?? 0) > 0;
    $rc = $conexion->query("SELECT clave, color".($hayTxt?", text_color":"")." FROM estado_cita_colores");
    if ($rc) while ($x = $rc->fetch_assoc()) {
        $colEstado[$x['clave']] = $x['color'];
        if ($hayTxt && !empty($x['text_color'])) $txtEstado[$x['clave']] = $x['text_color'];
    }
}
// Mapea un ESTADO_CITA a su clave de color
function claveEstado($e) {
    switch ($e) {
        case 'Pendiente':
        case 'Reagendada':                return 'pendiente';
        case 'Confirmada':                return 'confirmada';
        case 'A':                         return 'atendida';
        case 'Cancelada':
        case 'Cancelado':                 return 'cancelada';
        case 'Atrasado':
        case 'Cancelación Tardía':        return 'cancelacion_tardia';
        case 'Cancelado por Profesional': return 'cancelado_profesional';
        case 'No Asistió':                return 'no_asistio';
        case 'No contestó':               return 'no_contesto';
        default:                          return 'default';
    }
}

while ($row = $resultado->fetch_assoc()) {
    $claveE = claveEstado($row['ESTADO_CITA']);
    $colorEstado = $colEstado[$claveE] ?? $colEstado['default'];
    $colorTexto  = $txtEstado[$claveE] ?? '#ffffff';

    $colorTipo = !empty($row['TIPO_COLOR']) ? $row['TIPO_COLOR'] : $colorTipoDefault;

    $eventos[] = array(
        'id'              => $row['IDCITA'],
        'title'           => $row['PACIENTE'],
        'start'           => $row['FECHA_CITA'] . 'T' . $row['HORA_INICIO'],
        'end'             => $row['FECHA_CITA'] . 'T' . $row['HORA_FIN'],
        'backgroundColor' => $colorEstado,
        'borderColor'     => $colorTipo,
        'textColor'       => $colorTexto,
        'extendedProps'   => array(
            'cita'      => $row['ESTADO_CITA'],
            'medico'    => $row['DOCTOR'],
            'consulta'  => $row['TIPO_CONSULTA'],
            'telefono'  => $row['TELEFONO'],
            'comentario'=> $row['COMENTARIO'],
            'agencia'   => $row['AGENCIA'],
            'idpaciente'=> $row['IDPACIENTE'],
            'idtipoconsulta' => $row['IDTIPOCONSULTA'],
            'iddoctor'       => $row['IDDOCTOR'],
            'idagencia'      => $row['IDAGENCIA'],
            'idserie'        => $row['IDSERIE'] ?? null,
        )
    );
}

$tiposConsultaActivos = array();
$resTipos = $conexion->query("SELECT IDTIPOCONSULTA, NOMBRES, COLOR FROM AG_TIPOCONSULTA WHERE ESTADO = 'A' ORDER BY NOMBRES");
while ($t = $resTipos->fetch_assoc()) {
    $tiposConsultaActivos[] = array(
        'id'     => $t['IDTIPOCONSULTA'],
        'nombre' => $t['NOMBRES'],
        'color'  => !empty($t['COLOR']) ? $t['COLOR'] : $colorTipoDefault,
    );
}

$sqlDoctores = "SELECT U.IDADM_USUARIO, U.NOMBRES, U.APELLIDOS
                FROM ADM_USUARIO U
                INNER JOIN ADM_ROL R ON U.IDADM_ROL = R.IDADM_ROL
                WHERE R.CARGO = 'DOCTOR'
                  AND U.ESTADO = 'A'
                ORDER BY U.NOMBRES";
$resultDoctores  = $conexion->query($sqlDoctores);
$doctoresActivos = array();
while ($d = $resultDoctores->fetch_assoc()) {
    $doctoresActivos[] = array(
        'id'     => $d['IDADM_USUARIO'],
        'nombre' => $d['NOMBRES'] . ' ' . $d['APELLIDOS'],
    );
}

$agenciasActivas = array();
$resAgencias = $conexion->query("SELECT IDAGENCIA, DESCRIPCION FROM ADM_AGENCIA WHERE ESTADO = 1 ORDER BY DESCRIPCION");
while ($a = $resAgencias->fetch_assoc()) {
    $agenciasActivas[] = array(
        'id'     => $a['IDAGENCIA'],
        'nombre' => $a['DESCRIPCION'],
    );
}

// Ausencias / no disponibilidad de los doctores (vacaciones y bloqueos de horas).
$ausencias = array();
if ((int)($conexion->query("SHOW TABLES LIKE 'ausencias_doctor'")->num_rows ?? 0) > 0) {
    $ra = $conexion->query(
        "SELECT a.IDDOCTOR, a.tipo, a.fecha_inicio, a.fecha_fin, a.hora_inicio, a.hora_fin, a.motivo,
                CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS doctor
         FROM ausencias_doctor a
         LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = a.IDDOCTOR
         WHERE a.estado = 1"
    );
    if ($ra) while ($x = $ra->fetch_assoc()) {
        $ausencias[] = array(
            'idDoctor'     => (int)$x['IDDOCTOR'],
            'doctor'       => $x['doctor'],
            'tipo'         => $x['tipo'],
            'fecha_inicio' => $x['fecha_inicio'],
            'fecha_fin'    => $x['fecha_fin'],
            'hora_inicio'  => $x['hora_inicio'] ? substr($x['hora_inicio'],0,5) : null,
            'hora_fin'     => $x['hora_fin'] ? substr($x['hora_fin'],0,5) : null,
            'motivo'       => $x['motivo'],
        );
    }
}
?>
<!doctype html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="utf-8">
    <!-- Favicon de la app -->
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="images/favicon.png">
    <link rel="apple-touch-icon" href="images/favicon.png">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, shrink-to-fit=no">
    <title><?php te('cal.pageTitle'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="./fullcalendar/main.css" rel="stylesheet">
    <link href="./main.css" rel="stylesheet">
    <script src="js/jquery.min.js"></script>
    <style>
        /* ── Modal Agendar Cita: campos claros y con borde (independiente del CDN) ── */
        #editModal .modal-body .form-label { font-weight: 600; color: #33475b; margin-bottom: .3rem; }
        #editModal .form-control,
        #editModal .form-select { min-height: 46px; border: 1px solid #ced4da; border-radius: .5rem; }

        /* Select2: garantizar apariencia aunque el tema del CDN no cargue
           (aplica a todos los modales: agendar y detalle de la cita). */
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single {
            height: 46px;
            display: flex;
            align-items: center;
            border: 1px solid #ced4da;
            border-radius: .5rem;
            background-color: #fff;
            padding: 0 .75rem;
        }
        .select2-container--open .select2-selection--single {
            border-color: #86b7fe;
            box-shadow: 0 0 0 .2rem rgba(13,110,253,.15);
        }
        .select2-selection--single .select2-selection__rendered {
            line-height: 44px !important;
            padding: 0 !important;
            color: #212529;
            font-size: 1rem;
        }
        .select2-selection__placeholder { color: #6c757d; }
        .select2-selection--single .select2-selection__arrow { height: 44px !important; right: 10px; }
        /* Dropdown de búsqueda */
        .select2-dropdown { border: 1px solid #ced4da; border-radius: .5rem; box-shadow: 0 4px 14px rgba(0,0,0,.12); }
        .select2-search__field { border: 1px solid #ced4da !important; border-radius: .375rem; padding: .4rem .6rem; }
        .select2-results__option { padding: .5rem .75rem; }
        .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #1976d2; }
        /* Limita la altura de la lista y habilita el scroll interno (evita que
           la lista larga de horarios se salga de la pantalla sin poder bajar). */
        .select2-results__options { max-height: 40vh !important; overflow-y: auto !important; }

        .fc-event { cursor: pointer; font-size: calc(0.85em * var(--cal-font-scale, 1)); padding: 2px 5px; }
        /* El mismo control A-/A/A+ escala el texto del modal de Gestión de Cita */
        #eventModal .modal-body { font-size: calc(1rem * var(--cal-font-scale, 1)); }
        #eventModal .btn { transition: all 0.3s ease; white-space: nowrap; }
        #eventModal .btn:hover { transform: translateY(-2px); box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        /* Franja izquierda = tipo de consulta (el fondo del evento sigue indicando el estado) */
        .fc-daygrid-event, .fc-timegrid-event, .fc-list-event-dot {
            border-width: 0 0 0 5px !important;
            border-style: solid !important;
            border-radius: 3px;
        }
        .fc-list-event-dot { border-radius: 50%; border-width: 5px !important; }
        /* Leyenda de colores */
        .leyenda-calendario { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; font-size: 0.8rem; }
        .leyenda-item { display: flex; align-items: center; gap: 5px; }
        .leyenda-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
        .leyenda-dot-borde { width: 12px; height: 12px; border-radius: 50%; display: inline-block; background: #fff; border: 3px solid #999; box-sizing: border-box; }
        .leyenda-franja { width: 16px; height: 12px; display: inline-block; background: #fff; border: 1px solid #ddd; border-left: 5px solid #999; box-sizing: border-box; border-radius: 2px; }
        .leyenda-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .04em; color: #888; font-weight: 600; margin-right: 4px; }

        /* ── Vista por Doctor ─────────────────────────────────────── */
        .cv-scroll { overflow-x: auto; border: 1px solid #dee2e6; border-radius: 4px; }
        .cv-grid { position: relative; }
        .cv-col-head {
            position: absolute; box-sizing: border-box;
            border-right: 1px solid #dee2e6; border-bottom: 1px solid #dee2e6;
            background: #f8f9fa; font-size: 0.78rem; text-align: center;
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .cv-col-head.cv-day-head { font-weight: 600; background: #eef1f5; }
        .cv-col-head.cv-day-head.cv-today { background: #fff3cd; }
        .cv-time-label {
            position: absolute; width: 64px; box-sizing: border-box;
            font-size: 0.72rem; color: #6c757d; text-align: right; padding-right: 6px;
            border-right: 1px solid #dee2e6; border-bottom: 1px solid #f1f1f1;
        }
        .cv-cell {
            position: absolute; box-sizing: border-box;
            border-right: 1px solid #f1f1f1; border-bottom: 1px solid #f1f1f1;
        }
        .cv-cell.cv-day-end { border-right: 1px solid #dee2e6; }
        .cv-event {
            position: absolute; box-sizing: border-box; border-radius: 4px;
            color: #fff; font-size: 0.72rem; padding: 2px 4px; overflow: hidden;
            cursor: pointer; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        .cv-event b { display: block; }
        .cv-doctores { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; font-size: 0.85rem; }
        .cv-doctores label { display: flex; align-items: center; gap: 5px; margin: 0; cursor: pointer; }

        /* ════════ Mejoras responsive móvil + citas legibles ════════ */
        .fc-event-main-custom { padding: 2px 4px; line-height: 1.25; overflow: hidden; }
        .fc-event-time-custom { font-weight: 700; font-size: 0.78em; }
        .fc-event-title-custom { font-weight: 600; font-size: 0.82em; white-space: normal; word-break: break-word; }
        .fc-event-sub-custom { font-size: 0.72em; opacity: 0.95; white-space: normal; }

        /* Vista de Mes: mostrar las citas como bloques legibles (no líneas) */
        .fc-daygrid-event { white-space: normal !important; align-items: stretch; }
        .fc-daygrid-event .fc-event-main-custom { padding: 3px 5px; }
        .fc-daygrid-event-harness { margin-bottom: 2px; }
        .fc-daygrid-day-events { min-height: 2em; }

        /* Vista Día/Semana: filas de hora más altas para aprovechar la pantalla (estilo agenda) */
        .fc .fc-timegrid-slot { height: 2.8em; }
        .fc .fc-timegrid-slot-label { font-size: 0.8rem; }

        /* Lista de consultas anteriores en el modal de cita (estilo tarjetas) */
        .cita-hist-lista { max-height: 280px; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; }
        .cita-hist-card { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid #f1f1f1; }
        .cita-hist-card:last-child { border-bottom: none; }
        .cita-hist-main { flex: 1 1 auto; min-width: 0; }
        .cita-hist-fecha { font-weight: 600; font-size: 0.82em; color: #343a40; }
        .cita-hist-sub { font-size: 0.74em; color: #777; white-space: normal; }
        .cita-hist-estado { flex: 0 0 auto; }

        /* Lista de citas para móvil (Vista por Doctor) */
        .cv-list { display: none; }
        .cv-list-day { font-weight: 700; font-size: 0.9rem; margin: 14px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #dee2e6; color: #343a40; }
        .cv-list-card { display: flex; border-radius: 6px; overflow: hidden; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); cursor: pointer; }
        .cv-list-color { width: 6px; flex: 0 0 6px; }
        .cv-list-body { flex: 1; padding: 8px 10px; color: #fff; }
        .cv-list-body .hora { font-weight: 700; font-size: 0.82rem; }
        .cv-list-body .pac { font-weight: 600; font-size: 0.9rem; }
        .cv-list-body .sub { font-size: 0.76rem; opacity: 0.95; }

        @media (max-width: 768px) {
            .app-main__inner { padding: 10px !important; }
            .card-body { padding: 8px !important; }

            /* Cabecera y botones que no se monten */
            .page-title-heading > div { flex-wrap: wrap; }

            /* Leyenda más compacta para ganar espacio arriba */
            .leyenda-calendario { gap: 6px 10px; font-size: 0.68rem; margin-bottom: 4px; }
            .leyenda-label { font-size: 0.62rem; width: 100%; margin-bottom: 2px; }

            /* Barra del calendario en columna para que quepan todos los botones */
            .fc .fc-toolbar.fc-header-toolbar { flex-direction: column; gap: 8px; align-items: stretch; }
            .fc .fc-toolbar-title { font-size: 1.1rem; text-align: center; }
            .fc .fc-button { padding: 4px 8px; font-size: 0.8rem; }
            .fc .fc-toolbar-chunk { display: flex; justify-content: center; }

            /* En móvil: ocultar la cuadrícula horizontal de Vista por Doctor y mostrar la lista */
            .cv-scroll { display: none; }
            .cv-list { display: block; }
        }

        /* ── Buscador de pacientes (estilo Kalix) ── */
        .cal-patient-search { min-width: 280px; }
        @media (max-width: 575px){ .cal-patient-search { min-width: 100%; width: 100%; } }
        .cal-search-results {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 1055;
            background: #fff; border: 1px solid #e6e9f0; border-radius: 10px;
            max-height: 360px; overflow-y: auto; margin-top: 4px;
        }
        .cal-search-results .csr-item { padding: 9px 12px; cursor: pointer; border-bottom: 1px solid #f1f3f8; }
        .cal-search-results .csr-item:last-child { border-bottom: 0; }
        .cal-search-results .csr-item:hover,
        .cal-search-results .csr-item.active { background: #f0f4ff; }
        .cal-search-results .csr-name { font-weight: 600; color: #23324d; }
        .cal-search-results .csr-meta { font-size: .78rem; color: #6c757d; }
        .cal-search-results .csr-empty { padding: 10px 12px; color: #6c757d; font-size: .85rem; }
    </style>
</head>
<body>

<div class="app-container app-theme-white body-tabs-shadow fixed-sidebar fixed-header">

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
            <div class="app-header-left">
                <ul class="header-menu nav">
                    <li class="nav-item">
                        <a href="javascript:void(0);" class="nav-link">
                            <i class="nav-link-icon fa fa-database"></i> Estadistica
                        </a>
                    </li>
                    <li class="dropdown nav-item">
                        <a href="javascript:void(0);" class="nav-link">
                            <i class="nav-link-icon fa fa-cog"></i> Configuracion
                        </a>
                    </li>
                </ul>
            </div>
            <div class="app-header-right">
                <div class="header-btn-lg pr-0">
                    <div class="widget-content p-0">
                        <div class="widget-content-wrapper">
                            <div class="widget-content-left">
                                <div class="btn-group">
                                    <a data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="p-0 btn">
                                        <img width="42" class="rounded-circle">
                                        <i class="fa fa-angle-down ml-2 opacity-8"></i>
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <a href="perfil.php" class="dropdown-item"><?php te('hdr.userProfile'); ?></a>
                                        <button type="button" class="dropdown-item"><?php te('hdr.settings'); ?></button>
                                        <div class="dropdown-divider"></div>
                                        <a href="salir.php" class="dropdown-item"><?php te('menu.logout'); ?></a>
                                    </div>
                                </div>
                            </div>
                            <div class="widget-content-left ml-3 header-user-info">
                                <div class="widget-heading">
                                    <?php echo htmlspecialchars($_SESSION["username"] ?? ''); ?>
                                </div>
                                <div class="widget-subheading">
                                    <?php echo htmlspecialchars($_SESSION["rol"] ?? 'Usuario'); ?> — <?php echo date('m/d/Y'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-main">
        <div class="app-sidebar sidebar-shadow">
            <?php include("./menu/menu_adm.php"); ?>
        </div>

        <div class="app-main__outer">
            <div class="app-main__inner">
                <div class="app-page-title">
                    <div class="page-title-wrapper">
                        <div class="page-title-heading">
                            <div class="page-title-icon">
                                <i class="pe-7s-date icon-gradient bg-warm-flame"></i>
                            </div>
                            <div>
                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#editModal">
                                    <i class="bi bi-calendar-plus"></i> <?php te('cal.newAppointment'); ?>
                                </button>
                                <div class="btn-group ms-2" role="group">
                                    <button type="button" id="btnVistaCalendario" class="btn btn-outline-primary active">
                                        <i class="bi bi-calendar3"></i> <?php te('cal.viewCalendar'); ?>
                                    </button>
                                    <button type="button" id="btnVistaDoctor" class="btn btn-outline-primary">
                                        <i class="bi bi-people"></i> <?php te('cal.viewByDoctor'); ?>
                                    </button>
                                </div>
                                <div class="page-title-subheading"><?php te('cal.subheading'); ?></div>
                            </div>
                        </div>
                        <div class="page-title-actions">
                            <button type="button" class="btn btn-outline-info btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalAyudaAgenda">
                                <i class="bi bi-question-circle me-1"></i><?php te('help.howItWorks'); ?>
                            </button>
                            <div class="cal-patient-search position-relative">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input type="text" id="calPacienteBuscar" class="form-control"
                                           placeholder="<?php te('cal.searchPatientTop'); ?>" autocomplete="off">
                                    <button type="button" class="btn btn-outline-secondary d-none" id="calPacienteClear" title="<?php te('plist.clear'); ?>">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                                <div id="calPacienteResultados" class="cal-search-results shadow d-none"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="main-card mb-3 card" id="viewFullCalendar">
                    <div class="card-body">
                        <!-- Leyenda de colores (colapsable, para ganar espacio arriba) -->
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#leyendaColores1" aria-expanded="false">
                                <i class="bi bi-palette"></i> <?php te('cal.legendToggle'); ?>
                            </button>
                            <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="<?php te('cal.fontSize'); ?>">
                                <span class="d-flex align-items-center text-muted me-1" style="font-size:.8rem;"><i class="bi bi-fonts me-1"></i><?php te('cal.fontSize'); ?></span>
                                <button class="btn btn-outline-secondary" type="button" onclick="calFont(-1)" title="<?php te('cal.fontSmaller'); ?>">A&minus;</button>
                                <button class="btn btn-outline-secondary" type="button" onclick="calFont(0)" title="<?php te('cal.fontReset'); ?>">A</button>
                                <button class="btn btn-outline-secondary" type="button" onclick="calFont(1)" title="<?php te('cal.fontLarger'); ?>" style="font-size:1.05rem;">A+</button>
                            </div>
                        </div>
                        <div class="collapse" id="leyendaColores1">
                            <div class="leyenda-calendario">
                                <span class="leyenda-label"><?php te('cal.legendStatus'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#212529"></span> <?php te('cal.legendPendingResched'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#6f42c1"></span> <?php echo estado_label('Confirmada'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#28a745"></span> <?php echo estado_label('Atendida'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#fd7e14"></span> <?php echo estado_label('Cancelada'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#ffc107"></span> <?php echo estado_label('Cancelación Tardía'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#ff8a80"></span> <?php echo estado_label('Cancelado por Profesional'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#dc3545"></span> <?php echo estado_label('No Asistió'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#6c757d"></span> <?php echo estado_label('No contestó'); ?></span>
                            </div>
                            <div class="leyenda-calendario">
                                <span class="leyenda-label"><?php te('cal.legendType'); ?></span>
                                <?php foreach ($tiposConsultaActivos as $tc): ?>
                                    <span class="leyenda-item"><span class="leyenda-franja" style="border-left-color:<?php echo htmlspecialchars($tc['color']); ?>"></span> <?php echo htmlspecialchars($tc['nombre']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div id="calendar1"></div>
                    </div>
                </div>

                <div class="main-card mb-3 card d-none" id="viewPorDoctor">
                    <div class="card-body">
                        <!-- Leyenda de colores (colapsable, para ganar espacio arriba) -->
                        <button class="btn btn-sm btn-outline-secondary mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#leyendaColores2" aria-expanded="false">
                            <i class="bi bi-palette"></i> <?php te('cal.legendToggle'); ?>
                        </button>
                        <div class="collapse" id="leyendaColores2">
                            <div class="leyenda-calendario">
                                <span class="leyenda-label"><?php te('cal.legendStatus'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#212529"></span> <?php te('cal.legendPendingResched'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#6f42c1"></span> <?php echo estado_label('Confirmada'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#28a745"></span> <?php echo estado_label('Atendida'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#fd7e14"></span> <?php echo estado_label('Cancelada'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#ffc107"></span> <?php echo estado_label('Cancelación Tardía'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#ff8a80"></span> <?php echo estado_label('Cancelado por Profesional'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#dc3545"></span> <?php echo estado_label('No Asistió'); ?></span>
                                <span class="leyenda-item"><span class="leyenda-dot" style="background:#6c757d"></span> <?php echo estado_label('No contestó'); ?></span>
                            </div>
                            <div class="leyenda-calendario">
                                <span class="leyenda-label"><?php te('cal.legendType'); ?></span>
                                <?php foreach ($tiposConsultaActivos as $tc): ?>
                                    <span class="leyenda-item"><span class="leyenda-franja" style="border-left-color:<?php echo htmlspecialchars($tc['color']); ?>"></span> <?php echo htmlspecialchars($tc['nombre']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="cv-toolbar d-flex align-items-center gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="cvToday"><?php te('cal.today'); ?></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="cvPrev"><i class="bi bi-chevron-left"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="cvNext"><i class="bi bi-chevron-right"></i></button>
                            <strong id="cvRangeLabel" class="ms-2"></strong>
                        </div>
                        <div class="cv-scroll">
                            <div id="cvGrid" class="cv-grid"></div>
                        </div>
                        <div class="cv-list" id="cvList"></div>
                        <div class="cv-doctores mt-3" id="cvDoctorFiltros">
                            <span class="fw-semibold me-2"><?php te('cal.doctors'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL AGENDAR CITA ─────────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php te('cal.newAppointment'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="insertCita" method="POST" action="class/Insert_cita.php" onsubmit="return confirmarRecurrencia();">
                    <div class="mb-3">
                        <label class="form-label"><?php te('cal.appDate'); ?></label>
                        <input type="date" class="form-control" name="fechafactura" id="fechafactura">
                        <script>document.getElementById('fechafactura').value = new Date().toISOString().substring(0, 10);</script>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php te('cal.patient'); ?></label>
                        <select class="form-select select-busqueda" name="IdPaciente" required>
                            <option value=""><?php te('cal.searchPatient'); ?></option>
                            <?php
                            $queryP = $conexion->query("SELECT IDPACIENTE, NOMBRES, APELLIDOS FROM AG_PACIENTE WHERE ESTADO = 'A' ORDER BY NOMBRES");
                            while ($v = $queryP->fetch_assoc()):
                            ?>
                            <option value="<?php echo $v['IDPACIENTE']; ?>"><?php echo htmlspecialchars($v['NOMBRES'].' '.$v['APELLIDOS']); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <div id="citaIcd10Info" class="small mt-1"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php te('cal.startTime'); ?></label>
                        <select class="form-select select-busqueda" name="timeIni" id="timeIni" required>
                            <option value=""><?php te('cal.selectTime'); ?></option>
                            <?php
                            $inicio    = new DateTime('07:00');
                            $fin       = new DateTime('22:30');
                            $intervalo = new DateInterval('PT30M');
                            foreach (new DatePeriod($inicio, $intervalo, $fin) as $hora):
                                $v = $hora->format('H:i');
                            ?>
                            <option value="<?php echo $v; ?>"><?php echo $hora->format('h:i A'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php te('cal.consultType'); ?></label>
                        <select class="form-select select-busqueda" name="Idconsulta" required>
                            <option value=""><?php te('cal.searchType'); ?></option>
                            <?php
                            $queryC = $conexion->query("SELECT IDTIPOCONSULTA, NOMBRES FROM AG_TIPOCONSULTA WHERE ESTADO = 'A' ORDER BY NOMBRES");
                            while ($v = $queryC->fetch_assoc()):
                            ?>
                            <option value="<?php echo $v['IDTIPOCONSULTA']; ?>"><?php echo htmlspecialchars($v['NOMBRES']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php te('cal.doctor'); ?></label>
                        <select class="form-select select-busqueda" name="IdDoctor" required>
                            <option value=""><?php te('cal.searchDoctor'); ?></option>
                            <?php foreach ($doctoresActivos as $v): ?>
                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php te('cal.location'); ?></label>
                        <select class="form-select select-busqueda" name="IdAgencia" required>
                            <option value=""><?php te('cal.selectLocation'); ?></option>
                            <?php foreach ($agenciasActivas as $v): ?>
                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- ── Recurrencia (opcional) ─────────────────────────────── -->
                    <div class="mb-2">
                        <label class="form-label mb-1"><i class="bi bi-arrow-repeat me-1"></i><?php te('cal.recur.title'); ?></label>
                        <select class="form-select" name="recurrencia" id="recurrenciaSel"
                                onchange="toggleRecurrenciaUI()">
                            <option value="none"><?php te('cal.recur.none'); ?></option>
                            <option value="daily"><?php te('cal.recur.daily'); ?></option>
                            <option value="weekly"><?php te('cal.recur.weekly'); ?></option>
                            <option value="biweekly"><?php te('cal.recur.biweekly'); ?></option>
                            <option value="monthly"><?php te('cal.recur.monthly'); ?></option>
                            <option value="custom"><?php te('cal.recur.custom'); ?></option>
                        </select>
                    </div>
                    <div id="recurCustomWrap" class="mb-2 d-none">
                        <label class="form-label mb-1"><?php te('cal.recur.everyN'); ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="recurEvery" id="recurEvery" min="1" max="90" value="10">
                            <span class="input-group-text"><?php te('cal.recur.days'); ?></span>
                        </div>
                    </div>
                    <div id="recurEndWrap" class="mb-3 d-none">
                        <label class="form-label mb-1"><?php te('cal.recur.endMode'); ?></label>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5">
                                <select class="form-select" name="recurEndMode" id="recurEndMode"
                                        onchange="toggleRecurrenciaUI()">
                                    <option value="count"><?php te('cal.recur.byCount'); ?></option>
                                    <option value="date"><?php te('cal.recur.byDate'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-7" id="recurCountWrap">
                                <div class="input-group">
                                    <input type="number" class="form-control" name="recurCount" id="recurCount" min="1" max="260" value="4">
                                    <span class="input-group-text"><?php te('cal.recur.sessions'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-7 d-none" id="recurEndDateWrap">
                                <input type="date" class="form-control" name="recurEndDate" id="recurEndDate">
                            </div>
                        </div>
                        <div class="form-text mt-1" id="recurPreview"></div>
                    </div>

                    <div id="ausAviso" class="alert alert-warning py-2 d-none"></div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="dobleReserva" id="dobleReserva" value="1">
                        <label class="form-check-label small" for="dobleReserva">
                            <i class="bi bi-layers me-1"></i><?php te('cal.allowOverlap'); ?>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-calendar-check"></i> <?php te('cal.scheduleBtn'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL: ¿Cómo agendar citas? (guía para doctores) ────────────── -->
<div class="modal fade" id="modalAyudaAgenda" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title"><i class="bi bi-calendar3 me-2"></i><?php te('help.scheduleTitle'); ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (current_lang() === 'en'): ?>
          <h6 class="fw-bold text-info"><i class="bi bi-plus-circle me-1"></i>Schedule a new appointment</h6>
          <ol style="line-height:1.9;">
            <li>Click a <b>day</b> on the calendar (or the schedule button) to open the form.</li>
            <li>Choose <b>date, patient, time, consultation type, doctor and location</b>.</li>
            <li><b>Recurrence:</b> leave <b>"Do not repeat"</b> for a single appointment. For a series pick <b>weekly / biweekly / monthly</b> and how many. It asks you to confirm how many will be created.</li>
          </ol>
          <div class="alert alert-warning py-2">To schedule, the patient must already have <b>at least one ICD-10 diagnosis</b> and a <b>complete address</b> (street, or city + state).</div>
          <h6 class="fw-bold text-info"><i class="bi bi-arrow-repeat me-1"></i>Move / change an appointment</h6>
          <ul style="line-height:1.9;">
            <li><b>Drag</b> the appointment to another day/time, or open it and press <b>Reschedule</b>. The patient gets an email with the new time. <span class="text-muted">(To avoid accidental moves, a confirmation appears before saving.)</span></li>
            <li>For a <b>series</b>, choose <b>"all future visits"</b> to move the whole series to the new weekday keeping its cadence (e.g. all stay on Fridays), or <b>"only this one"</b>.</li>
          </ul>
          <h6 class="fw-bold text-info"><i class="bi bi-check2-square me-1"></i>Status &amp; attending</h6>
          <ul style="line-height:1.9;">
            <li>Open an appointment to <b>Attend</b> it (any time — even before its hour, or before the previous patient arrives), <b>Confirm</b>, <b>Cancel</b>, or set <b>Other status</b>.</li>
            <li><b>No answer</b>: if you call and the patient doesn't pick up, mark <b>"No answer"</b> and move on — the appointment stays open so you can retry or reschedule.</li>
          </ul>
        <?php else: ?>
          <h6 class="fw-bold text-info"><i class="bi bi-plus-circle me-1"></i>Agendar una cita nueva</h6>
          <ol style="line-height:1.9;">
            <li>Haz clic en un <b>día</b> del calendario (o en el botón de agendar) para abrir el formulario.</li>
            <li>Elige <b>fecha, paciente, hora, tipo de consulta, doctor y location</b>.</li>
            <li><b>Recurrencia:</b> deja <b>"No repetir"</b> para una sola cita. Para una serie elige <b>semanal / quincenal / mensual</b> y cuántas. Te pide confirmar cuántas se crearán.</li>
          </ol>
          <div class="alert alert-warning py-2">Para agendar, el paciente debe tener ya <b>al menos un diagnóstico ICD-10</b> y la <b>dirección completa</b> (calle, o ciudad + estado).</div>
          <h6 class="fw-bold text-info"><i class="bi bi-arrow-repeat me-1"></i>Mover / cambiar una cita</h6>
          <ul style="line-height:1.9;">
            <li><b>Arrastra</b> la cita a otro día/hora, o ábrela y pulsa <b>Reagendar</b>. Al paciente le llega un correo con la nueva hora. <span class="text-muted">(Para evitar movimientos accidentales, sale una confirmación antes de guardar.)</span></li>
            <li>En una <b>serie</b>, elige <b>"todas las futuras"</b> para mover toda la serie al nuevo día conservando la cadencia (ej. que todas queden los viernes), o <b>"solo esta"</b>.</li>
          </ul>
          <h6 class="fw-bold text-info"><i class="bi bi-check2-square me-1"></i>Estados y atención</h6>
          <ul style="line-height:1.9;">
            <li>Abre una cita para <b>Atender</b> (a cualquier hora — aunque sea antes de su hora o antes de que llegue el paciente anterior), <b>Confirmar</b>, <b>Cancelar</b>, o poner <b>Otro estado</b>.</li>
            <li><b>No contestó</b>: si llamas y el paciente no responde, márcala como <b>"No contestó"</b> y sigue con otro — la cita queda abierta para reintentar o reagendar.</li>
          </ul>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
      </div>
    </div>
  </div>
</div>
<script>(function(){var m=document.getElementById('modalAyudaAgenda');if(m&&m.parentNode!==document.body)document.body.appendChild(m);})();</script>

<!-- ── MODAL GESTIÓN CITA ─────────────────────────────────────────── -->
<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php te('cal.manageTitle'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEstado" method="POST" action="class/actualizar_estado.php" onsubmit="return validarFormulario();">
                <div class="modal-body">
                    <div id="eventDetails" class="mb-3"></div>

                    <!-- Datos del paciente (edad, estatura, IMC, etc.) cargados por AJAX -->
                    <div id="citaPacienteInfo" class="mb-2"></div>

                    <!-- Diagnósticos ICD-10 del paciente (para el biller): ver y añadir -->
                    <div id="citaDiagWrap" class="rounded mb-2" style="background:#fff;position:relative;border:2px solid #0d6efd;">
                        <div class="fw-bold px-2 py-1" style="background:#0d6efd;color:#fff;font-size:.95em;border-radius:.2rem .2rem 0 0;">
                            <i class="bi bi-clipboard2-pulse me-1"></i><?php te('cal.diag.title'); ?>
                        </div>
                        <div class="p-2">
                            <div id="citaDiagLista" class="d-flex flex-wrap gap-2 mb-2"></div>
                            <select id="citaDiagSelect" class="form-select"><option value=""></option></select>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn btn-primary btn-sm" onclick="agregarDiagCita()"><i class="bi bi-plus-lg"></i> <?php te('cal.diag.add'); ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Reagendar (oculto por defecto) -->
                    <div id="reagendarSection" class="d-none border rounded p-3 bg-light mt-2">
                        <h6 class="mb-3"><i class="bi bi-calendar2-event"></i> <?php te('cal.newDateTime'); ?></h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php te('cal.date'); ?></label>
                                <input type="date" id="nuevaFecha" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php te('cal.time'); ?></label>
                                <select id="nuevaHora" class="form-select"></select>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-warning flex-grow-1" onclick="guardarReagenda()">
                                <i class="bi bi-calendar-check"></i> <?php te('cal.saveNewDate'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="cerrarReagendar()">
                                <?php te('common.cancel'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Panel Editar Cita (oculto por defecto) -->
                    <div id="editarSection" class="d-none border rounded p-3 bg-light mt-2">
                        <h6 class="mb-1"><i class="bi bi-pencil-square"></i> <?php te('cal.editAppt'); ?></h6>
                        <p class="text-muted small mb-3"><?php te('cal.editApptHelp'); ?></p>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php te('cal.date'); ?></label>
                                <input type="date" id="editFecha" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php te('cal.time'); ?></label>
                                <input type="time" id="editHora" class="form-control" min="07:00" max="22:30">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php te('cal.consultTypeShort'); ?></label>
                                <select id="editTipoConsulta" class="form-select">
                                    <?php foreach ($tiposConsultaActivos as $tc): ?>
                                    <option value="<?php echo $tc['id']; ?>"><?php echo htmlspecialchars($tc['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php te('cal.doctor'); ?></label>
                                <select id="editDoctor" class="form-select">
                                    <?php foreach ($doctoresActivos as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php te('cal.location'); ?></label>
                                <select id="editAgencia" class="form-select">
                                    <option value="0"><?php te('cal.unassigned'); ?></option>
                                    <?php foreach ($agenciasActivas as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-info text-white flex-grow-1" onclick="guardarEdicion()">
                                <i class="bi bi-check2-square"></i> <?php te('common.saveChanges'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="cerrarEditar()">
                                <?php te('common.cancel'); ?>
                            </button>
                        </div>
                    </div>

                    <input type="hidden" id="idCita"     name="id">
                    <input type="hidden" id="estadoCita" name="estado">
                    <input type="hidden" id="motivoCancel" name="motivo">
                </div>
                <div class="modal-footer" id="modalFooterBtns">
                    <button id="btnAtender" class="btn btn-primary" type="button" onclick="irAConsulta()">
                        <i class="bi bi-person-check-fill"></i> <?php te('cal.attendPatient'); ?>
                    </button>
                    <a id="btnHistorial" href="#" class="btn btn-purple d-none"
                       style="background:#6f42c1;color:#fff;">
                        <i class="bi bi-clipboard2-pulse"></i> <?php te('cal.viewHistory'); ?>
                    </a>
                    <button id="btnEditarPaciente" class="btn btn-outline-primary" type="button" onclick="editarPacienteDesdeCita()">
                        <i class="bi bi-person-vcard"></i> <?php te('cal.editPatient'); ?>
                    </button>
                    <button id="btnEditar" class="btn btn-info text-white" type="button" onclick="toggleEditar()">
                        <i class="bi bi-pencil-square"></i> <?php te('common.edit'); ?>
                    </button>
                    <button id="btnReagendar" class="btn btn-warning" type="button" onclick="toggleReagendar()">
                        <i class="bi bi-calendar2-event"></i> <?php te('cal.reschedule'); ?>
                    </button>
                    <button id="btnConfirmar" class="btn btn-success" type="submit" onclick="setEstado('Confirmada')">
                        <i class="bi bi-check-circle"></i> <?php te('cal.confirm'); ?>
                    </button>
                    <button id="btnCancelar" class="btn btn-danger" type="submit" onclick="setEstado('Cancelada')">
                        <i class="bi bi-x-circle"></i> <?php te('common.cancel'); ?>
                    </button>
                    <div id="btnMasEstados" class="btn-group">
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                            <?php te('cal.otherStatus'); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item" type="submit" onclick="setEstado('Reagendada')"><?php echo estado_label('Reagendada'); ?></button></li>
                            <li><button class="dropdown-item" type="submit" onclick="setEstado('Cancelación Tardía')"><?php echo estado_label('Cancelación Tardía'); ?></button></li>
                            <li><button class="dropdown-item" type="submit" onclick="setEstado('Cancelado por Profesional')"><?php echo estado_label('Cancelado por Profesional'); ?></button></li>
                            <li><button class="dropdown-item" type="submit" onclick="setEstado('No Asistió')"><?php echo estado_label('No Asistió'); ?></button></li>
                            <li><button class="dropdown-item" type="submit" onclick="setEstado('No contestó')"><?php echo estado_label('No contestó'); ?></button></li>
                        </ul>
                    </div>
                    <button id="btnRestablecer" class="btn btn-success d-none" type="button" onclick="restablecerCitaModal()">
                        <i class="bi bi-arrow-counterclockwise"></i> <?php te('cal.restore'); ?>
                    </button>
                    <button id="btnEliminar" class="btn btn-outline-danger" type="button" onclick="eliminarCita()">
                        <i class="bi bi-trash"></i> <?php te('cal.delete'); ?>
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── MODAL EDITAR PACIENTE ──────────────────────────────────────── -->
<div class="modal fade" id="modalEditarPaciente" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#5a2d82;">
                <h6 class="modal-title text-white mb-0">
                    <i class="bi bi-pencil-square me-2"></i><?php te('plist.editTitleModal'); ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formEditarPaciente">
                    <input type="hidden" id="epId" name="idPaciente">

                    <h6 class="text-muted mb-2"><i class="bi bi-person-vcard"></i> <?php te('pf.patientData'); ?></h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.firstName'); ?> *</label>
                            <input type="text" id="epNombres" name="nombres" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.lastName'); ?> *</label>
                            <input type="text" id="epApellidos" name="apellidos" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.id'); ?></label>
                            <input type="text" id="epCedula" name="cedula" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.phone'); ?></label>
                            <input type="text" id="epTelefono" name="telefono" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.dob'); ?></label>
                            <input type="date" id="epFecNac" name="fecNac" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.email'); ?></label>
                            <input type="email" id="epEmail" name="email" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.sex'); ?></label>
                            <input type="text" id="epSex" name="sex" class="form-control" placeholder="M / F">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.gender'); ?></label>
                            <input type="text" id="epGender" name="gender" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold"><?php te('pf.address'); ?></label>
                            <input type="text" id="epAddress" name="address" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.city'); ?></label>
                            <input type="text" id="epCity" name="city" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold"><?php te('pf.state'); ?></label>
                            <input type="text" id="epState" name="state" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold"><?php te('pf.zip'); ?></label>
                            <input type="text" id="epZip" name="zip" class="form-control" maxlength="15">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold"><i class="bi bi-translate"></i> <?php te('pf.language'); ?></label>
                            <select id="epIdioma" name="idioma" class="form-select">
                                <option value="es"><?php te('lang.spanish'); ?></option>
                                <option value="en"><?php te('lang.english'); ?></option>
                            </select>
                        </div>
                    </div>

                    <h6 class="text-muted mb-2"><i class="bi bi-journal-text"></i> <?php te('pf.notes'); ?></h6>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?php te('pf.alert'); ?>
                        </label>
                        <textarea id="epAlerta" name="alerta" class="form-control" rows="2"
                                  placeholder="<?php te('pf.alertPh'); ?>"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?php te('pf.importantNotes'); ?></label>
                        <textarea id="epNotes" name="notes" class="form-control" rows="3"
                                  placeholder="<?php te('pf.importantPh'); ?>"></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small fw-semibold"><?php te('pf.billingNotes'); ?></label>
                        <textarea id="epAddNotes" name="addNotes" class="form-control" rows="2"
                                  placeholder="<?php te('pf.billingPh'); ?>"></textarea>
                    </div>
                    <div id="epAlertaAviso" class="small text-muted d-none mt-1">
                        <i class="bi bi-info-circle"></i> <?php te('plist.alertNotSavedPre'); ?> <strong><?php te('pf.alert'); ?></strong> <?php te('plist.alertNotSavedMid'); ?>
                        <a href="migrar_notas_paciente.php" target="_blank"><?php te('plist.notesMigration'); ?></a>.
                    </div>

                    <!-- 📞 Contactos adicionales: teléfonos/correos extra -->
                    <hr class="my-3">
                    <h6 class="text-muted mb-2"><i class="bi bi-telephone-plus"></i> <?php te('pcreate.extraContacts'); ?></h6>
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1"><?php te('pcreate.ct.type'); ?></label>
                            <select id="pcTipo" class="form-select form-select-sm">
                                <option value="telefono"><?php te('pf.phone'); ?></option>
                                <option value="email"><?php te('pf.email'); ?></option>
                            </select>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label small mb-1"><?php te('pcreate.ct.value'); ?></label>
                            <input type="text" id="pcValor" class="form-control form-control-sm" maxlength="160">
                        </div>
                        <div class="col-8 col-md-3">
                            <label class="form-label small mb-1"><?php te('pcreate.ct.label'); ?></label>
                            <input type="text" id="pcEtiqueta" class="form-control form-control-sm" maxlength="60" placeholder="<?php te('pcreate.ct.labelPh'); ?>">
                        </div>
                        <div class="col-4 col-md-2">
                            <button type="button" class="btn btn-sm btn-success w-100" onclick="agregarContacto()"><?php te('pcreate.addBtn'); ?></button>
                        </div>
                    </div>
                    <div id="pcLista"><div class="text-muted small">—</div></div>
                </form>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.cancel'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="epGuardar" onclick="guardarPacienteCita()">
                    <i class="bi bi-check-lg"></i> <?php te('common.saveChanges'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL VER INFORME ──────────────────────────────────────────── -->
<div class="modal fade" id="modalInforme" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i><?php te('plist.reportTitle'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="cuerpoInforme">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
            <div class="modal-footer py-2">
                <button id="btnEditarInforme" type="button" class="btn btn-outline-warning btn-sm" onclick="editarInforme()">
                    <i class="bi bi-pencil-square"></i> <?php te('common.edit'); ?>
                </button>
                <button id="btnGuardarInforme" type="button" class="btn btn-success btn-sm d-none" onclick="guardarInformeEditado()">
                    <i class="bi bi-check-lg"></i> <?php te('common.saveChanges'); ?>
                </button>
                <button id="btnCancelarInforme" type="button" class="btn btn-outline-secondary btn-sm d-none" onclick="cancelarEdicionInforme()">
                    <?php te('plist.reportCancel'); ?>
                </button>
                <button type="button" onclick="exportarInformePDF()" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-filetype-pdf"></i> <?php te('plist.exportPdf'); ?>
                </button>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> <?php te('common.print'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php te('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts — cargados UNA sola vez, en orden correcto -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/modal_stack.js?v=<?php echo @filemtime(__DIR__.'/js/modal_stack.js'); ?>"></script>
<script src="./fullcalendar/main.js"></script>
<script src="./fullcalendar/locales/es.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script type="text/javascript" src="./assets/scripts/main.js"></script>

<script>
// ── i18n (inyectado desde PHP) ───────────────────────────────────────
const CAL_LANG = <?php echo json_encode(current_lang()); ?>;
const CAL_DIAS  = <?php echo json_encode(current_lang() === 'es'
    ? ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado']
    : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']); ?>;
const CAL_MESES = <?php echo json_encode(current_lang() === 'es'
    ? ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']
    : ['January','February','March','April','May','June','July','August','September','October','November','December']); ?>;
const ESTADO_LABELS = {
    'Pendiente':                   <?php echo json_encode(estado_label('Pendiente')); ?>,
    'Reagendada':                  <?php echo json_encode(estado_label('Reagendada')); ?>,
    'Confirmada':                  <?php echo json_encode(estado_label('Confirmada')); ?>,
    'A':                           <?php echo json_encode(estado_label('Atendida')); ?>,
    'Cancelada':                   <?php echo json_encode(estado_label('Cancelada')); ?>,
    'Cancelado':                   <?php echo json_encode(estado_label('Cancelado')); ?>,
    'Atrasado':                    <?php echo json_encode(estado_label('Atrasado')); ?>,
    'Cancelación Tardía':          <?php echo json_encode(estado_label('Cancelación Tardía')); ?>,
    'Cancelado por Profesional':   <?php echo json_encode(estado_label('Cancelado por Profesional')); ?>,
    'No Asistió':                  <?php echo json_encode(estado_label('No Asistió')); ?>,
    'No contestó':                 <?php echo json_encode(estado_label('No contestó')); ?>,
};
const TC = <?php echo json_encode(array(
    'lblPatient'      => t('cal.js.lblPatient'),
    'lblPhone'        => t('cal.js.lblPhone'),
    'lblStart'        => t('cal.js.lblStart'),
    'lblDoctor'       => t('cal.js.lblDoctor'),
    'lblLocation'     => t('cal.js.lblLocation'),
    'lblStatus'       => t('cal.js.lblStatus'),
    'lblType'         => t('cal.js.lblType'),
    'lblComment'      => t('cal.js.lblComment'),
    'notRegisteredM'  => t('cal.js.notRegisteredM'),
    'notRegisteredF'  => t('cal.js.notRegisteredF'),
    'confirmWhatsapp' => t('cal.js.confirmWhatsapp'),
    'noWhatsapp'      => t('cal.js.noWhatsapp'),
    'loadingPatient'  => t('cal.js.loadingPatient'),
    'couldNotLoadPat' => t('cal.js.couldNotLoadPat'),
    'consultations'   => t('cal.js.consultations'),
    'noApptsWeek'     => t('cal.js.noApptsWeek'),
    'unassigned'      => t('cal.unassigned'),
    'waPre'           => t('cal.js.waPre'),
    'waMid'           => t('cal.js.waMid'),
    'waAt'            => t('cal.js.waAt'),
    'waEnd'           => t('cal.js.waEnd'),
    'selectDateTime'  => t('cal.js.selectDateTime'),
    'confirmReschedPre'=> t('cal.js.confirmReschedPre'),
    'at'              => t('cal.js.at'),
    'reschedOk'       => t('cal.js.reschedOk'),
    'reschedNoEmail'  => t('cal.js.reschedNoEmail'),
    'reschedError'    => t('cal.js.reschedError'),
    'confirmSavePre'  => t('cal.js.confirmSavePre'),
    'updatedOk'       => t('cal.js.updatedOk'),
    'updatedEmail'    => t('cal.js.updatedEmail'),
    'updatedNoEmail'  => t('cal.js.updatedNoEmail'),
    'editError'       => t('cal.js.editError'),
    'confirmDelete'   => t('cal.js.confirmDelete'),
    'deletedOk'       => t('cal.js.deletedOk'),
    'deletedNoEmail'  => t('cal.js.deletedNoEmail'),
    'deleteError'     => t('cal.js.deleteError'),
    'deleteConnError' => t('cal.js.deleteConnError'),
    'noPatientId'     => t('cal.js.noPatientId'),
    'patientUpdatedOk'=> t('cal.js.patientUpdatedOk'),
    'formIncomplete'  => t('cal.js.formIncomplete'),
    'cancelReasonPrompt'   => t('cal.js.cancelReasonPrompt'),
    'cancelReasonRequired' => t('cal.js.cancelReasonRequired'),
    'completeFields'  => t('cal.js.completeFields'),
    'timeRange'       => t('cal.js.timeRange'),
    'apptGone'        => t('cal.js.apptGone'),
    'slotTaken'       => t('cal.js.slotTaken'),
    'slotTakenAsk'    => t('cal.js.slotTakenAsk'),
    'apptDiagLabel'   => t('cal.js.apptDiagLabel'),
    'apptDiagNone'    => t('cal.js.apptDiagNone'),
    'apptGonePrev'    => t('cal.js.apptGonePrev'),
    'reschedule'      => t('cal.reschedule'),
    'editAppt'        => t('cal.editAppt'),
    'deleteLabel'     => t('cal.delete'),
    'recurConfirm'    => t('cal.js.recurConfirm'),
    'recurConfirmDate'=> t('cal.js.recurConfirmDate'),
    'dragClosed'      => t('cal.js.dragClosed'),
    'saving'          => t('cal.js.saving'),
    'ausVac'          => t('cal.js.ausVac'),
    'ausBlq'          => t('cal.js.ausBlq'),
    'ausConfirm'      => t('cal.js.ausConfirm'),
    'restoreConfirm'  => t('cal.js.restoreConfirm'),
    'restoreOk'       => t('cal.js.restoreOk'),
    'restoreError'    => t('cal.js.restoreError'),
    'connError'       => t('common.js.connError'),
    'diagNone'        => t('cal.diag.none'),
    'diagRemove'      => t('cal.diag.remove'),
    'diagSearchPh'    => t('cal.diag.searchPh'),
    'diagSaveError'   => t('cal.diag.saveError'),
    'loadError'       => t('plist.js.loadError'),
    'loadHttp'        => t('plist.js.loadHttp'),
    'nameRequired'    => t('plist.js.nameRequired'),
    'updatedNoAlert'  => t('plist.js.updatedNoAlert'),
    'incomplete'      => t('plist.js.incomplete'),
    'saveError'       => t('plist.js.saveError'),
)); ?>;

// ── Helpers ──────────────────────────────────────────────────────────
function estadoBadge(estado) {
    const map = {
        'Pendiente':                   '#212529',
        'Reagendada':                  '#212529',
        'Confirmada':                  '#6f42c1',
        'A':                           '#28a745',
        'Cancelada':                   '#fd7e14',
        'Cancelado':                   '#fd7e14',
        'Atrasado':                    '#ffc107',
        'Cancelación Tardía':          '#ffc107',
        'Cancelado por Profesional':   '#ff8a80',
        'No Asistió':                  '#dc3545',
        'No contestó':                 '#6c757d',
    };
    const color = map[estado] || '#6c757d';
    const label = ESTADO_LABELS[estado] || estado;
    return `<span class="badge" style="background:${color}">${label}</span>`;
}

function setEstado(estado) {
    document.getElementById('estadoCita').value = estado;
}

function irAConsulta() {
    const id = document.getElementById('idCita').value;
    window.location.href = `atencion_paciente.php?idCita=${id}`;
}

// ── Reagendar ────────────────────────────────────────────────────────
function generarHoras(selectId) {
    const sel = document.getElementById(selectId);
    if (sel.options.length > 0) return; // ya generadas
    let h = 7, m = 0;
    while (h < 22 || (h === 22 && m === 0)) {
        const val  = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
        const ampm = h < 12 ? 'AM' : 'PM';
        const h12  = h > 12 ? h - 12 : (h === 0 ? 12 : h);
        const lbl  = `${String(h12).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        sel.add(new Option(lbl, val));
        m += 30;
        if (m >= 60) { m = 0; h++; }
    }
}

function toggleReagendar() {
    const section = document.getElementById('reagendarSection');
    const hidden  = section.classList.contains('d-none');
    cerrarEditar();
    section.classList.toggle('d-none', !hidden);
    if (hidden) {
        generarHoras('nuevaHora');
        document.getElementById('nuevaFecha').value = new Date().toISOString().substring(0, 10);
    }
}

function cerrarReagendar() {
    document.getElementById('reagendarSection').classList.add('d-none');
}

async function guardarReagenda() {
    const id    = document.getElementById('idCita').value;
    const fecha = document.getElementById('nuevaFecha').value;
    const hora  = document.getElementById('nuevaHora').value;

    if (!fecha || !hora) {
        alert(TC.selectDateTime);
        return;
    }

    // Formatear fecha para confirmar (formato EE. UU.: mes/día/año)
    const [y, mo, d] = fecha.split('-');
    if (!confirm(`${TC.confirmReschedPre} ${mo}/${d}/${y} ${TC.at} ${hora}?`)) return;

    // Si es serie, preguntar alcance
    let alcance = 'solo';
    if (citaActual && citaActual.idserie) {
        alcance = await askSerieScope(TC.reschedule);
        if (!alcance) return;
    }

    $.post('reagendar_cita.php', { idCita: id, fecha: fecha, hora: hora, alcance: alcance }, function(res) {
        res = res.trim();
        if (res === 'OK') {
            alert(TC.reschedOk);
            location.reload();
        } else if (res === 'OK_SIN_CORREO') {
            alert(TC.reschedNoEmail);
            location.reload();
        } else if (res === 'NO_ENCONTRADA') {
            alert(TC.apptGone);
            location.reload();
        } else {
            alert(TC.reschedError + res);
        }
    }).fail(function() {
        alert(TC.connError);
    });
}

// ── Restablecer una cita cancelada por error (vuelve a Pendiente) ────
function restablecerCitaModal(){
    var id = document.getElementById('idCita').value;
    if (!id) return;
    if (!confirm(TC.restoreConfirm)) return;
    $.post('restablecer_cita.php', { idCita: id }, function(res){
        if (res && res.ok) { alert(TC.restoreOk); location.reload(); }
        else { alert(TC.restoreError + ((res && res.error) ? res.error : '')); }
    }, 'json').fail(function(){ alert(TC.connError); });
}

// ── Reagendar arrastrando la cita en el calendario ───────────────────
async function reagendarPorArrastre(info) {
    const ev    = info.event;
    const start = ev.start;
    if (!start) { info.revert(); return; }

    // Ignorar arrastres accidentales (soltó en el mismo día/hora): no hacer nada.
    if (info.oldEvent && info.oldEvent.start && info.oldEvent.start.getTime() === start.getTime()) {
        info.revert();
        return;
    }

    const pad   = function (n) { return String(n).padStart(2, '0'); };
    const fecha = start.getFullYear() + '-' + pad(start.getMonth() + 1) + '-' + pad(start.getDate());
    const hora  = pad(start.getHours()) + ':' + pad(start.getMinutes());

    // Confirmar el cambio (fecha en formato mes/día/año)
    if (!confirm(`${TC.confirmReschedPre} ${pad(start.getMonth() + 1)}/${pad(start.getDate())}/${start.getFullYear()} ${TC.at} ${hora}?`)) {
        info.revert();
        return;
    }

    // Si la cita es parte de una serie, preguntar alcance
    let alcance = 'solo';
    const idserie = (ev.extendedProps || {}).idserie;
    if (idserie) {
        alcance = await askSerieScope(TC.reschedule);
        if (!alcance) { info.revert(); return; }
    }

    $.post('reagendar_cita.php', { idCita: ev.id, fecha: fecha, hora: hora, alcance: alcance }, function (res) {
        res = (res || '').trim();
        if (res === 'OK') {
            alert(TC.reschedOk);
            location.reload();
        } else if (res === 'OK_SIN_CORREO') {
            alert(TC.reschedNoEmail);
            location.reload();
        } else if (res === 'NO_ENCONTRADA') {
            alert(TC.apptGone);
            location.reload();
        } else {
            alert(TC.reschedError + res);
            info.revert();
        }
    }).fail(function () {
        alert(TC.connError);
        info.revert();
    });
}

// ── Editar cita (corregir datos sin cambiar el estado) ───────────────
function toggleEditar() {
    const section = document.getElementById('editarSection');
    const hidden  = section.classList.contains('d-none');
    cerrarReagendar();
    section.classList.toggle('d-none', !hidden);
    if (hidden && citaActual) {
        document.getElementById('editFecha').value        = citaActual.fecha;
        document.getElementById('editHora').value         = citaActual.hora;
        document.getElementById('editTipoConsulta').value = citaActual.idtipoconsulta || '';
        document.getElementById('editDoctor').value       = citaActual.iddoctor || '';
        document.getElementById('editAgencia').value      = citaActual.idagencia || '0';
    }
}

function cerrarEditar() {
    document.getElementById('editarSection').classList.add('d-none');
}

async function guardarEdicion() {
    const id      = document.getElementById('idCita').value;
    const fecha   = document.getElementById('editFecha').value;
    const hora    = document.getElementById('editHora').value;
    const tipo    = document.getElementById('editTipoConsulta').value;
    const doctor  = document.getElementById('editDoctor').value;
    const agencia = document.getElementById('editAgencia').value;

    if (!fecha || !hora || !tipo || !doctor) {
        alert(TC.completeFields);
        return;
    }

    if (hora < '07:00' || hora > '22:30') {
        alert(TC.timeRange);
        return;
    }

    const [y, mo, d] = fecha.split('-');
    if (!confirm(`${TC.confirmSavePre} ${d}/${mo}/${y} ${TC.at} ${hora}.`)) return;

    // Si es serie, preguntar alcance
    let alcance = 'solo';
    if (citaActual && citaActual.idserie) {
        alcance = await askSerieScope(TC.editAppt);
        if (!alcance) return;
    }

    _postEdicionCita(id, fecha, hora, tipo, doctor, agencia, alcance, false);
}

function _postEdicionCita(id, fecha, hora, tipo, doctor, agencia, alcance, dobleReserva) {
    $.post('editar_cita.php', {
        idCita: id,
        fecha: fecha,
        hora: hora,
        idTipoConsulta: tipo,
        idDoctor: doctor,
        idAgencia: agencia,
        alcance: alcance,
        dobleReserva: dobleReserva ? 1 : 0
    }, function(res) {
        res = res.trim();
        if (res === 'OK') {
            alert(TC.updatedOk);
            location.reload();
        } else if (res === 'OK_MODIFICADA') {
            alert(TC.updatedEmail);
            location.reload();
        } else if (res === 'OK_SIN_CORREO') {
            alert(TC.updatedNoEmail);
            location.reload();
        } else if (res === 'HORARIO_OCUPADO') {
            // Ofrecer doble reserva (las asistentes resuelven los choques).
            if (confirm(TC.slotTakenAsk)) {
                _postEdicionCita(id, fecha, hora, tipo, doctor, agencia, alcance, true);
            }
        } else if (res === 'HORA_FUERA_RANGO') {
            alert(TC.timeRange);
        } else if (res === 'NO_ENCONTRADA') {
            alert(TC.apptGone);
            location.reload();
        } else {
            alert(TC.editError + res);
        }
    }).fail(function() {
        alert(TC.connError);
    });
}

// ── Eliminar cita (borra del calendario y notifica al paciente) ──────
async function eliminarCita() {
    const id = document.getElementById('idCita').value;
    if (!id) return;

    if (!confirm(TC.confirmDelete)) return;

    // Motivo de cancelación obligatorio.
    const motivo = prompt(TC.cancelReasonPrompt);
    if (motivo === null) return;                       // canceló el diálogo
    if (motivo.trim() === '') { alert(TC.cancelReasonRequired); return; }

    // Si es serie, preguntar alcance
    let alcance = 'solo';
    if (citaActual && citaActual.idserie) {
        alcance = await askSerieScope(TC.deleteLabel);
        if (!alcance) return;
    }

    $.post('eliminar_cita.php', { idCita: id, alcance: alcance, motivo: motivo.trim() }, function(res) {
        res = res.trim();
        if (res === 'OK') {
            alert(TC.deletedOk);
            location.reload();
        } else if (res === 'OK_SIN_CORREO') {
            alert(TC.deletedNoEmail);
            location.reload();
        } else if (res === 'NO_ENCONTRADA') {
            alert(TC.apptGonePrev);
            location.reload();
        } else {
            alert(TC.deleteError + res);
        }
    }).fail(function() {
        alert(TC.deleteConnError);
    });
}

// ── Editar Paciente desde el modal de Gestión de Cita ────────────────
let epModal = null;

// Abre el modal de edición del paciente por su ID (desde una cita o el buscador).
function editarPacientePorId(idPaciente) {
    if (!idPaciente) { alert(TC.noPatientId); return; }

    if (!epModal) epModal = new bootstrap.Modal(document.getElementById('modalEditarPaciente'));

    document.getElementById('formEditarPaciente').reset();
    document.getElementById('epId').value = idPaciente;

    $.getJSON('get_paciente.php', { id: idPaciente })
        .done(function(p) {
            if (p.error) { alert(TC.loadError + p.error); return; }
            document.getElementById('epNombres').value   = p.NOMBRES   || '';
            document.getElementById('epApellidos').value = p.APELLIDOS || '';
            document.getElementById('epCedula').value    = p.CEDULA    || '';
            document.getElementById('epTelefono').value  = p.TELEFONO  || '';
            document.getElementById('epFecNac').value    = p.FECHANACIMIENTO || '';
            document.getElementById('epEmail').value     = p.EMAIL     || '';
            document.getElementById('epSex').value       = p.SEX       || '';
            document.getElementById('epGender').value    = p.GENDER    || '';
            document.getElementById('epAddress').value   = p.ADDRESS   || '';
            document.getElementById('epCity').value      = p.CITY      || '';
            document.getElementById('epState').value     = p.STATE     || '';
            document.getElementById('epZip').value       = p.ZIP       || '';
            document.getElementById('epIdioma').value    = (p.IDIOMA === 'en') ? 'en' : 'es';
            document.getElementById('epAlerta').value    = p.ALERTA    || '';
            document.getElementById('epNotes').value     = p.NOTES     || '';
            document.getElementById('epAddNotes').value  = p.ADDNOTES  || '';

            document.getElementById('epAlertaAviso').classList.toggle('d-none', p._tieneAlerta !== false);

            // Teléfonos/correos adicionales del paciente
            cargarContactos(idPaciente);

            // Ocultar otros modales abiertos (cita / historial del buscador) para
            // que no se monten los backdrops.
            ['eventModal','mnuModalHistorial'].forEach(function(mid){
                var el = document.getElementById(mid);
                if (el) { var inst = bootstrap.Modal.getInstance(el); if (inst) inst.hide(); }
            });

            epModal.show();
        })
        .fail(function(xhr) {
            alert(TC.loadHttp + xhr.status + ').');
        });
}
// Compatibilidad: el botón "Editar paciente" dentro de la cita.
function editarPacienteDesdeCita() {
    editarPacientePorId(citaActual ? citaActual.idpaciente : null);
}
// Disponible para el buscador global (menú) y el del calendario.
window.editarPacientePorId = editarPacientePorId;

function guardarPacienteCita() {
    const nombres   = document.getElementById('epNombres').value.trim();
    const apellidos = document.getElementById('epApellidos').value.trim();
    if (!nombres || !apellidos) {
        alert(TC.nameRequired);
        return;
    }

    const btn = document.getElementById('epGuardar');
    btn.disabled = true;

    $.post('editar_paciente.php', $('#formEditarPaciente').serialize(), function(res) {
        res = res.trim();
        if (res === 'OK' || res === 'OK_SIN_ALERTA') {
            if (res === 'OK_SIN_ALERTA') {
                alert(TC.updatedNoAlert);
            } else {
                alert(TC.patientUpdatedOk);
            }
            location.reload();
        } else if (res === 'DATOS_INCOMPLETOS') {
            alert(TC.incomplete);
            btn.disabled = false;
        } else {
            alert(TC.saveError + res);
            btn.disabled = false;
        }
    }).fail(function() {
        alert(TC.connError);
        btn.disabled = false;
    });
}

// ── Contactos adicionales (teléfonos / correos extra) en el modal de cita ──
function _ctEsc(s){ return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
const CT = {
    none:      <?php echo json_encode(current_lang()==='en'?'No additional contacts.':'Sin contactos adicionales.'); ?>,
    loading:   <?php echo json_encode(current_lang()==='en'?'Loading…':'Cargando…'); ?>,
    remove:    <?php echo json_encode(current_lang()==='en'?'Remove':'Quitar'); ?>,
    valueReq:  <?php echo json_encode(current_lang()==='en'?'Enter the phone or email.':'Escribe el teléfono o correo.'); ?>,
    confirmDel:<?php echo json_encode(current_lang()==='en'?'Remove this contact?':'¿Quitar este contacto?'); ?>,
    saveError: <?php echo json_encode(current_lang()==='en'?'Could not save: ':'No se pudo guardar: '); ?>,
    connError: <?php echo json_encode(current_lang()==='en'?'Connection error.':'Error de conexión.'); ?>
};
function renderContactos(list){
    const cont = document.getElementById('pcLista'); if(!cont) return;
    if(!list || !list.length){ cont.innerHTML = '<div class="text-muted small">'+_ctEsc(CT.none)+'</div>'; return; }
    let html = '<ul class="list-group list-group-flush">';
    list.forEach(function(c){
        const icon = c.tipo === 'email' ? 'bi-envelope' : 'bi-telephone';
        const etq  = c.etiqueta ? ' <span class="badge bg-light text-dark border ms-1">'+_ctEsc(c.etiqueta)+'</span>' : '';
        html += '<li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">'
             +  '<span><i class="bi '+icon+' me-2 text-muted"></i>'+_ctEsc(c.valor)+etq+'</span>'
             +  '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="'+_ctEsc(CT.remove)+'" onclick="eliminarContacto('+parseInt(c.id,10)+')"><i class="bi bi-x"></i></button>'
             +  '</li>';
    });
    html += '</ul>'; cont.innerHTML = html;
}
function cargarContactos(idPaciente){
    const cont = document.getElementById('pcLista'); if(!cont) return;
    cont.innerHTML = '<div class="text-muted small">'+_ctEsc(CT.loading)+'</div>';
    $.getJSON('paciente_contactos.php', { accion:'listar', idPaciente: idPaciente })
        .done(function(res){ if(res && res.ok) renderContactos(res.lista); else cont.innerHTML = '<div class="text-muted small">—</div>'; })
        .fail(function(){ cont.innerHTML = '<div class="text-danger small">'+_ctEsc(CT.connError)+'</div>'; });
}
function agregarContacto(){
    const idPaciente = document.getElementById('epId').value;
    const tipo  = document.getElementById('pcTipo').value;
    const valor = document.getElementById('pcValor').value.trim();
    const etiqueta = document.getElementById('pcEtiqueta').value.trim();
    if(!idPaciente) return;
    if(valor === ''){ alert(CT.valueReq); return; }
    $.post('paciente_contactos.php', { accion:'agregar', idPaciente: idPaciente, tipo: tipo, valor: valor, etiqueta: etiqueta }, function(res){
        if(res && res.ok){ renderContactos(res.lista); document.getElementById('pcValor').value=''; document.getElementById('pcEtiqueta').value=''; }
        else { alert(CT.saveError + (res && res.error ? res.error : '')); }
    }, 'json').fail(function(){ alert(CT.connError); });
}
function eliminarContacto(id){
    const idPaciente = document.getElementById('epId').value;
    if(!confirm(CT.confirmDel)) return;
    $.post('paciente_contactos.php', { accion:'eliminar', idPaciente: idPaciente, id: id }, function(res){
        if(res && res.ok){ renderContactos(res.lista); }
        else { alert(CT.saveError + (res && res.error ? res.error : '')); }
    }, 'json').fail(function(){ alert(CT.connError); });
}

const ESTADOS_CANCELACION = ['Cancelada','Cancelado','Cancelación Tardía','Cancelado por Profesional','No Asistió'];

function validarFormulario() {
    const id     = document.getElementById('idCita').value;
    const estado = document.getElementById('estadoCita').value;
    if (!id || !estado) {
        alert(TC.formIncomplete);
        return false;
    }
    // Al cancelar, pedir obligatoriamente el motivo de cancelación.
    if (ESTADOS_CANCELACION.indexOf(estado) !== -1) {
        const motivo = prompt(TC.cancelReasonPrompt);
        if (motivo === null) return false;            // canceló el diálogo
        if (motivo.trim() === '') { alert(TC.cancelReasonRequired); return false; }
        document.getElementById('motivoCancel').value = motivo.trim();
    } else {
        document.getElementById('motivoCancel').value = '';
    }
    return true;
}

// ── Datos compartidos (FullCalendar + Vista por Doctor) ───────────────
const eventosAll      = <?php echo json_encode($eventos); ?>;
const doctoresActivos = <?php echo json_encode($doctoresActivos); ?>;
const ausenciasAll    = <?php echo json_encode($ausencias); ?>;
// ¿El doctor tiene vacación (día completo) o bloqueo (hora) en esa fecha/hora?
function ausenciaConflicto(idDoctor, fecha, hora){
    idDoctor = String(idDoctor||''); if(!idDoctor || !fecha) return null;
    for (var i=0;i<ausenciasAll.length;i++){
        var a = ausenciasAll[i];
        if (String(a.idDoctor) !== idDoctor) continue;
        if (a.tipo === 'vacacion'){
            if (fecha >= a.fecha_inicio && fecha <= a.fecha_fin) return { tipo:'vacacion', doctor:a.doctor, motivo:a.motivo };
        } else if (a.tipo === 'bloqueo'){
            if (fecha === a.fecha_inicio && hora && a.hora_inicio && a.hora_fin && hora >= a.hora_inicio && hora < a.hora_fin)
                return { tipo:'bloqueo', doctor:a.doctor, motivo:a.motivo, hora_inicio:a.hora_inicio, hora_fin:a.hora_fin };
        }
    }
    return null;
}
// Doctor con la sesión iniciada (si el usuario logueado es DOCTOR): su columna se muestra por defecto
const loginDoctorId   = <?php echo json_encode((strtoupper($_SESSION['rol'] ?? '') === 'DOCTOR') ? (string)($_SESSION['iduser'] ?? '') : ''); ?>;

// ── Modal de gestión de cita (usado por ambas vistas) ──────────────────
const ESTADOS_CERRADOS = ['A', 'Cancelada', 'Cancelado', 'Cancelación Tardía', 'Cancelado por Profesional', 'No Asistió'];

let citaActual = null; // datos de la cita abierta en el modal (para prellenar el panel de edición)

function abrirModalCita(id, title, startDate, p) {
    const est      = p.cita || 'Pendiente';
    const atendida = est === 'A';
    const cerrada  = ESTADOS_CERRADOS.includes(est);

    // Limpiar y formatear teléfono para WhatsApp
    let tel = p.telefono ? p.telefono.replace(/\D/g, '') : '';
    if (tel.length === 9 && tel.startsWith('9'))        tel = '593' + tel;
    else if (tel.length === 10 && tel.startsWith('09')) tel = '593' + tel.substring(1);

    const msg = encodeURIComponent(
        `${TC.waPre} ${p.consulta} ${TC.waMid} ` +
        `${startDate.toLocaleDateString('en-US')} ${TC.waAt} ` +
        `${startDate.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}. ${TC.waEnd}`
    );

    document.getElementById('eventDetails').innerHTML = `
        <div class="row">
            <div class="col-md-8">
                <p><strong>${TC.lblPatient}</strong> ${title}</p>
                <p><strong>${TC.lblPhone}</strong> ${p.telefono || TC.notRegisteredM}</p>
                <p><strong>${TC.lblStart}</strong> ${startDate.toLocaleString()}</p>
                <p><strong>${TC.lblDoctor}</strong> ${p.medico}</p>
                <p><strong>${TC.lblLocation}</strong> ${p.agencia || TC.notRegisteredF}</p>
                <p><strong>${TC.lblStatus}</strong> ${estadoBadge(est)}</p>
            </div>
            <div class="col-md-4 text-center">
                ${tel
                    ? `<a href="https://wa.me/${tel}?text=${msg}" target="_blank"
                        class="btn btn-outline-success btn-lg mb-2">
                        <i class="bi bi-whatsapp"></i> ${TC.confirmWhatsapp}
                       </a>`
                    : `<p class="text-danger small">${TC.noWhatsapp}</p>`
                }
            </div>
        </div>
        <hr>
        <p><strong>${TC.lblType}</strong> ${p.consulta}</p>
        ${p.comentario ? `<p><strong>${TC.lblComment}</strong> ${p.comentario}</p>` : ''}
    `;

    // Controlar visibilidad de botones según estado
    document.getElementById('idCita').value     = id;
    document.getElementById('estadoCita').value = est;

    // Guardar datos actuales de la cita para el panel de edición
    citaActual = {
        id: id,
        fecha: `${startDate.getFullYear()}-${String(startDate.getMonth() + 1).padStart(2, '0')}-${String(startDate.getDate()).padStart(2, '0')}`,
        hora: `${String(startDate.getHours()).padStart(2, '0')}:${String(startDate.getMinutes()).padStart(2, '0')}`,
        idtipoconsulta: p.idtipoconsulta,
        iddoctor: p.iddoctor,
        idagencia: p.idagencia,
        idpaciente: p.idpaciente,
        idserie: p.idserie || null
    };

    const btnAtender     = document.getElementById('btnAtender');
    const btnHistorial   = document.getElementById('btnHistorial');
    const btnEditar      = document.getElementById('btnEditar');
    const btnReagendar    = document.getElementById('btnReagendar');
    const btnConfirmar   = document.getElementById('btnConfirmar');
    const btnCancelar    = document.getElementById('btnCancelar');
    const btnMasEstados  = document.getElementById('btnMasEstados');
    const btnRestablecer = document.getElementById('btnRestablecer');

    // Mostrar "Restablecer" solo si la cita está cancelada (para deshacer una cancelación por error)
    const esCancelada = ['Cancelada','Cancelado','Cancelación Tardía','Cancelado por Profesional','No Asistió','No contestó'].indexOf(est) !== -1;
    if (btnRestablecer) btnRestablecer.classList.toggle('d-none', !esCancelada);

    // Cerrar paneles reagendar/editar al abrir una nueva cita
    cerrarReagendar();
    cerrarEditar();

    if (cerrada) {
        btnAtender.classList.add('d-none');
        btnEditar.classList.add('d-none');
        btnReagendar.classList.add('d-none');
        btnConfirmar.classList.add('d-none');
        btnCancelar.classList.add('d-none');
        btnMasEstados.classList.add('d-none');
    } else {
        btnAtender.classList.remove('d-none');
        btnEditar.classList.remove('d-none');
        btnReagendar.classList.remove('d-none');
        btnConfirmar.classList.remove('d-none');
        btnCancelar.classList.remove('d-none');
        btnMasEstados.classList.remove('d-none');
    }

    if (atendida) {
        btnHistorial.href = `historial_atenciones.php?q=${encodeURIComponent(title)}`;
        btnHistorial.classList.remove('d-none');
    } else {
        btnHistorial.classList.add('d-none');
    }

    // Cargar datos del paciente (edad, estatura, IMC, estadísticas) reusando el endpoint de informes
    cargarInfoPacienteCita(p.idpaciente);
    // Diagnósticos ICD-10 del paciente (ver/añadir en la cita)
    cargarDiagnosticosCita(p.idpaciente);

    new bootstrap.Modal(document.getElementById('eventModal')).show();
}

// ── Diagnósticos ICD-10 en el modal de la cita (para el biller) ──────
function escHtml(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
var _icd10Cat = null;           // catálogo cargado una sola vez
function _pintaDiagChips(list){
    var c = document.getElementById('citaDiagLista'); if(!c) return;
    c.innerHTML = '';
    if(!list || !list.length){ c.innerHTML = '<span class="badge bg-secondary text-white" style="font-size:.85em;font-weight:600;">'+TC.diagNone+'</span>'; return; }
    list.forEach(function(it){
        var chip = document.createElement('span');
        chip.className = 'badge bg-primary text-white d-inline-flex align-items-center';
        chip.style.cssText = 'gap:6px;padding:7px 8px 7px 12px;font-size:.92em;font-weight:600;';
        chip.innerHTML = '<span><strong>'+escHtml(it.codigo)+'</strong> '+escHtml(it.descripcion||'')+'</span>';
        var b = document.createElement('button');
        b.type='button'; b.className='btn btn-sm p-0 px-1 text-white'; b.style.cssText='line-height:1;font-size:1.05em;opacity:.9;';
        b.innerHTML='&times;'; b.title=TC.diagRemove;
        b.onclick=function(){ eliminarDiagCita(it.id); };
        chip.appendChild(b); c.appendChild(chip);
    });
}
function _cargaCatalogoIcd10(cb){
    if (_icd10Cat){ cb(); return; }
    fetch('get_icd10_list.php').then(function(r){return r.json();}).then(function(rows){
        _icd10Cat = rows || [];
        var sel = document.getElementById('citaDiagSelect');
        if (sel){
            var opts = '<option value=""></option>';
            _icd10Cat.forEach(function(r){ opts += '<option value="'+parseInt(r.id,10)+'">'+escHtml(r.codigo+' — '+r.descripcion)+'</option>'; });
            sel.innerHTML = opts;
            if (window.jQuery && $(sel).select2){ $(sel).select2({ theme:'bootstrap-5', dropdownParent: $('#citaDiagWrap'), width:'100%', placeholder: TC.diagSearchPh }); }
        }
        cb();
    }).catch(function(){ cb(); });
}
function cargarDiagnosticosCita(idPaciente){
    document.getElementById('citaDiagWrap').dataset.idpac = idPaciente || '';
    _pintaDiagChips([]);
    _cargaCatalogoIcd10(function(){
        if(window.jQuery){ $('#citaDiagSelect').val('').trigger('change'); }
    });
    if(!idPaciente) return;
    fetch('paciente_icd10.php?accion=listar&idPaciente=' + encodeURIComponent(idPaciente))
        .then(function(r){return r.json();})
        .then(function(res){ if(res && res.ok) _pintaDiagChips(res.lista); })
        .catch(function(){});
}
function agregarDiagCita(){
    var idPac = document.getElementById('citaDiagWrap').dataset.idpac;
    var idIcd = parseInt(document.getElementById('citaDiagSelect').value||'0',10);
    if(!idPac || !idIcd) return;
    $.post('paciente_icd10.php', { accion:'agregar', idPaciente:idPac, idIcd10:idIcd }, function(res){
        if(res && res.ok){ _pintaDiagChips(res.lista); $('#citaDiagSelect').val('').trigger('change'); }
        else { alert(TC.diagSaveError + (res && res.error ? res.error : '')); }
    }, 'json').fail(function(){ alert(TC.connError); });
}
function eliminarDiagCita(idIcd){
    var idPac = document.getElementById('citaDiagWrap').dataset.idpac;
    if(!idPac) return;
    $.post('paciente_icd10.php', { accion:'eliminar', idPaciente:idPac, idIcd10:idIcd }, function(res){
        if(res && res.ok){ _pintaDiagChips(res.lista); }
        else { alert(TC.diagSaveError + (res && res.error ? res.error : '')); }
    }, 'json').fail(function(){ alert(TC.connError); });
}

// Trae el bloque de datos del paciente desde get_historial_paciente.php (igual al de informes)
function cargarInfoPacienteCita(idPaciente) {
    const cont = document.getElementById('citaPacienteInfo');
    if (!cont) return;
    if (!idPaciente) { cont.innerHTML = ''; return; }
    cont.innerHTML = '<div class="text-muted small py-2"><span class="spinner-border spinner-border-sm me-1"></span> ' + TC.loadingPatient + '</div>';
    fetch('get_historial_paciente.php?id=' + encodeURIComponent(idPaciente))
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var style = tmp.querySelector('style');           // estilos (.info-label, .badge-atendida, etc.)
            var info  = tmp.querySelector('.border-bottom');   // bloque superior: datos + estadísticas
            var filas = tmp.querySelectorAll('table tbody tr'); // consultas del paciente

            var out = '';
            if (info) {
                // Edad del paciente destacada (para que se vea grande en la cita)
                var _edad = info.getAttribute('data-edad');
                var _edadLbl = info.getAttribute('data-edad-lbl') || '';
                if (_edad) {
                    out += '<div class="d-flex align-items-center gap-2 mb-2 px-3 py-2 rounded" style="background:#fff3cd;border:2px solid #ffda6a;">' +
                               '<i class="bi bi-person-badge" style="font-size:1.5em;color:#664d03;"></i>' +
                               '<span style="font-size:1.6em;font-weight:800;color:#664d03;line-height:1;">' + _edad + '</span>' +
                               '<span style="font-size:1em;font-weight:600;color:#664d03;">' + _edadLbl + '</span>' +
                           '</div>';
                }
                out += '<div class="border rounded overflow-hidden">' + (style ? style.outerHTML : '') + info.outerHTML + '</div>';
            }

            // Consultas anteriores como tarjetas (estilo agenda)
            if (filas.length) {
                out += '<div class="mt-3">' +
                       '<div class="fw-semibold mb-2"><i class="bi bi-clock-history"></i> ' + TC.consultations + ' (' + filas.length + ')</div>' +
                       '<div class="cita-hist-lista">';
                filas.forEach(function (tr) {
                    var td = tr.querySelectorAll('td');
                    if (td.length < 5) return;
                    var fecha  = td[0].textContent.trim();
                    var hora   = td[1].textContent.trim().replace(/\s+/g, ' ');
                    var tipo   = td[2].textContent.trim();
                    var doctor = td[3].textContent.trim();
                    var badge  = td[4].innerHTML.trim();   // conserva el estado con su color
                    var sub    = tipo + (doctor && doctor !== '—' ? ' — ' + doctor : '');
                    // Botón "Ver informe" si la cita tiene informe registrado
                    var informeHtml = '';
                    if (td.length > 6) {
                        var btn = td[6].querySelector('button');
                        if (btn) {
                            // Importante: forzar type="button" para que NO envíe el formulario de la cita
                            btn.setAttribute('type', 'button');
                            informeHtml = btn.outerHTML;
                        }
                    }
                    out += '<div class="cita-hist-card">' +
                               '<div class="cita-hist-main">' +
                                   '<div class="cita-hist-fecha">' + fecha + ' · ' + hora + '</div>' +
                                   '<div class="cita-hist-sub">' + sub + '</div>' +
                               '</div>' +
                               '<div class="cita-hist-estado d-flex flex-column align-items-end gap-1">' +
                                   badge + informeHtml +
                               '</div>' +
                           '</div>';
                });
                out += '</div></div>';
            }

            cont.innerHTML = out;
        })
        .catch(function () {
            cont.innerHTML = '<p class="text-muted small mb-0">' + TC.couldNotLoadPat + '</p>';
        });
}

// Ver informe de una atención (mismo endpoint que el listado de pacientes)
var informeIdActual = null;
var informeHtmlOriginal = '';

function _resetBotonesInforme(){
    document.getElementById('btnEditarInforme').classList.remove('d-none');
    document.getElementById('btnGuardarInforme').classList.add('d-none');
    document.getElementById('btnCancelarInforme').classList.add('d-none');
    var body = document.getElementById('cuerpoInforme');
    body.contentEditable = 'false';
    body.style.outline = '';
    body.style.background = '';
}

function exportarInformePDF() {
    if (!informeIdActual) return;
    window.open('informe_pdf.php?id=' + encodeURIComponent(informeIdActual), '_blank');
}

function verInforme(idHistorial) {
    informeIdActual = idHistorial;
    informeHtmlOriginal = '';
    _resetBotonesInforme();
    document.getElementById('cuerpoInforme').innerHTML =
        '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>';
    new bootstrap.Modal(document.getElementById('modalInforme')).show();
    $.get('get_informe_html.php', { id: idHistorial })
        .done(function (html) {
            document.getElementById('cuerpoInforme').innerHTML = html;
            informeHtmlOriginal = html;
        })
        .fail(function (xhr) {
            document.getElementById('cuerpoInforme').innerHTML =
                '<div class="alert alert-danger m-3">No se pudo cargar el informe (HTTP ' + xhr.status + ').</div>';
        });
}

function editarInforme(){
    var body = document.getElementById('cuerpoInforme');
    if (!body || !informeIdActual) return;
    body.contentEditable = 'true';
    body.style.outline = '2px dashed #ffc107';
    body.style.background = '#fffdf5';
    body.focus();
    document.getElementById('btnEditarInforme').classList.add('d-none');
    document.getElementById('btnGuardarInforme').classList.remove('d-none');
    document.getElementById('btnCancelarInforme').classList.remove('d-none');
}

function cancelarEdicionInforme(){
    if (!confirm('¿Descartar los cambios del informe?')) return;
    document.getElementById('cuerpoInforme').innerHTML = informeHtmlOriginal;
    _resetBotonesInforme();
}

function guardarInformeEditado(){
    var body = document.getElementById('cuerpoInforme');
    if (!body || !informeIdActual) return;
    var contenido = body.innerHTML;
    if (contenido.replace(/<[^>]+>/g,'').trim().length < 5) { alert('El informe no puede estar vacío.'); return; }
    var btn = document.getElementById('btnGuardarInforme');
    btn.disabled = true;
    $.post('actualizar_atencion.php', { idHistorial: informeIdActual, informe: contenido }, function(res){
        btn.disabled = false;
        res = (res || '').trim();
        if (res === 'OK') {
            informeHtmlOriginal = contenido;
            _resetBotonesInforme();
            alert('Informe guardado.');
        } else {
            alert('No se pudo guardar el informe: ' + res);
        }
    }).fail(function(){ btn.disabled = false; alert('Error de conexión.'); });
}

document.addEventListener('DOMContentLoaded', function(){
    var m = document.getElementById('modalInforme');
    if (m) m.addEventListener('hidden.bs.modal', _resetBotonesInforme);
});

// ── FullCalendar ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    // En celular arranca en vista de Día (más legible que el mes apretado)
    var esMovilInit = window.innerWidth <= 768;

    var calendarEl = document.getElementById('calendar1');
    var calendar   = new FullCalendar.Calendar(calendarEl, {
        locale: CAL_LANG,
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,timeGridDay'
        },
        initialView: esMovilInit ? 'timeGridDay' : 'dayGridMonth',
        eventDisplay: 'block',
        eventMinHeight: 70,
        height: 'auto',
        slotMinTime: '06:00:00',
        slotMaxTime: '22:00:00',
        expandRows: true,
        nowIndicator: true,
        // Arrastrar la cita a otro día/hora la reagenda (con confirmación).
        editable: true,
        eventDurationEditable: false,
        // Salvaguardas contra movimientos accidentales:
        eventDragMinDistance: 12,     // hay que arrastrar un poco antes de que cuente
        longPressDelay: 500,          // en táctil: mantener presionado para arrastrar
        eventLongPressDelay: 500,
        events: eventosAll,

        // No permitir arrastrar citas ya cerradas (atendidas/canceladas).
        eventAllow: function (dropInfo, draggedEvent) {
            var est = (draggedEvent.extendedProps || {}).cita || 'Pendiente';
            return ESTADOS_CERRADOS.indexOf(est) === -1;
        },

        // Reagendar por arrastre (misma lógica y correo que el botón "Reagendar").
        eventDrop: function (info) {
            reagendarPorArrastre(info);
        },

        // Render personalizado en TODAS las vistas (mes, semana y día):
        // hora inicio-fin, paciente, doctor y location sin recortar
        eventContent: function (arg) {
            var esc = function (s) {
                return (s == null ? '' : String(s))
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            };
            // Vacaciones (fondo de día completo): etiqueta oscura legible, sin hora.
            if (arg.event.display === 'background') {
                var bg = document.createElement('div');
                bg.style.cssText = 'padding:2px 6px;font-size:.72rem;font-weight:700;color:#212529;';
                bg.innerHTML = esc(arg.event.title);
                return { domNodes: [bg] };
            }
            var p = arg.event.extendedProps;
            // Ausencia (vacaciones día completo o bloqueo de horas): evento visible, no es una cita.
            if (p && p.esAusencia) {
                var ab = document.createElement('div');
                ab.style.cssText = 'padding:2px 6px;font-size:.72rem;font-weight:700;color:#212529;line-height:1.2;';
                if (arg.event.allDay) {
                    ab.innerHTML = esc(arg.event.title);
                } else {
                    var fmtB = function (d) { return d ? d.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) : ''; };
                    ab.innerHTML = '<div>' + fmtB(arg.event.start) + ' - ' + fmtB(arg.event.end) + '</div>' +
                                   '<div>' + esc(arg.event.title) + '</div>';
                }
                return { domNodes: [ab] };
            }
            var fmt = function (d) {
                return d ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
            };
            var rango = fmt(arg.event.start) + (arg.event.end ? ' - ' + fmt(arg.event.end) : '');
            var div = document.createElement('div');
            div.className = 'fc-event-main-custom';
            div.innerHTML =
                '<div class="fc-event-time-custom">' + rango + '</div>' +
                '<div class="fc-event-title-custom">' + esc(arg.event.title) + '</div>' +
                '<div class="fc-event-sub-custom">Dr. ' + esc(p.medico || '') + '</div>' +
                '<div class="fc-event-sub-custom">L: ' + esc(p.agencia || TC.unassigned) + '</div>';
            return { domNodes: [div] };
        },

        eventClick: function (info) {
            // Ignorar vacaciones (fondo) y bloqueos de horas: no abren el modal de cita.
            if (info.event.display === 'background') return;
            if (info.event.extendedProps && info.event.extendedProps.esAusencia) return;
            abrirModalCita(info.event.id, info.event.title, info.event.start, info.event.extendedProps);
        },

        // Clic en una fecha → abre "Agendar cita" con la fecha precargada
        dateClick: function (info) {
            var f = document.getElementById('fechafactura');
            if (f) f.value = info.dateStr.substring(0, 10);
            var m = document.getElementById('editModal');
            if (m && window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(m).show();
            }
        }
    });

    calendar.render();

    // ── Tamaño de letra de las citas (A-/A/A+, persistente por equipo) ──
    (function(){
        var MIN = 1.0, MAX = 2.2, STEP = 0.15, KEY = 'calFontScale';
        function get(){
            try { var v = parseFloat(localStorage.getItem(KEY)); return isNaN(v) ? 1 : Math.min(MAX, Math.max(MIN, v)); }
            catch(e){ return 1; }
        }
        function apply(v){
            document.documentElement.style.setProperty('--cal-font-scale', v);
            try { calendar.updateSize(); } catch(e){}
        }
        // Expuesta globalmente para los botones A-/A/A+ del encabezado.
        window.calFont = function(dir){
            var v = (dir === 0) ? 1 : get() + (dir > 0 ? STEP : -STEP);
            v = Math.min(MAX, Math.max(MIN, Math.round(v * 100) / 100));
            try { localStorage.setItem(KEY, v); } catch(e){}
            apply(v);
        };
        apply(get());
    })();

    // ── Vacaciones / no disponibilidad: fondo de color en el calendario ──
    (function(){
        function addDays(ymd, n){ var d = new Date(ymd + 'T00:00:00'); d.setDate(d.getDate()+n);
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
        (ausenciasAll || []).forEach(function(a){
            if (a.tipo === 'vacacion'){
                // Vacaciones: evento de día completo visible (texto oscuro, no clicable/arrastrable).
                calendar.addEvent({
                    start: a.fecha_inicio, end: addDays(a.fecha_fin, 1), allDay: true,
                    title: '🏖️ ' + (a.doctor || ''),
                    backgroundColor: '#ffd8a8', borderColor: '#f5b878', textColor: '#212529',
                    editable: false,
                    extendedProps: { esAusencia: true, doctor: a.doctor, motivo: a.motivo }
                });
            } else if (a.hora_inicio && a.hora_fin){
                // Bloqueo de horas: evento visible (se ve en Mes, Semana y Día), no clicable.
                calendar.addEvent({
                    start: a.fecha_inicio + 'T' + a.hora_inicio, end: a.fecha_inicio + 'T' + a.hora_fin,
                    title: '⛔ ' + (a.doctor || ''),
                    backgroundColor: '#f1aeb5', borderColor: '#e35d6a', textColor: '#212529',
                    editable: false,
                    extendedProps: { esAusencia: true, doctor: a.doctor, motivo: a.motivo }
                });
            }
        });
    })();

    inicializarVistaPorDoctor();

    document.getElementById('btnVistaCalendario').addEventListener('click', function () {
        document.getElementById('viewFullCalendar').classList.remove('d-none');
        document.getElementById('viewPorDoctor').classList.add('d-none');
        this.classList.add('active');
        document.getElementById('btnVistaDoctor').classList.remove('active');
        calendar.updateSize();
    });

    document.getElementById('btnVistaDoctor').addEventListener('click', function () {
        document.getElementById('viewFullCalendar').classList.add('d-none');
        document.getElementById('viewPorDoctor').classList.remove('d-none');
        this.classList.add('active');
        document.getElementById('btnVistaCalendario').classList.remove('active');
        renderVistaPorDoctor();
    });
});

// ── Vista por Doctor (semana en columnas por doctor) ───────────────────
const CV_START_HOUR    = 6;
const CV_END_HOUR      = 21;
const CV_SLOT_MINUTES  = 30;
const CV_SLOT_HEIGHT   = 32;
const CV_COL_WIDTH     = 140;
const CV_TIME_COL_W    = 64;
const CV_DAY_HEAD_H    = 30;
const CV_DOC_HEAD_H    = 28;
const CV_HEADER_H      = CV_DAY_HEAD_H + CV_DOC_HEAD_H;
const CV_DIAS          = CAL_DIAS;
const CV_MESES         = CAL_MESES;

let cvWeekStart      = startOfWeek(new Date());
const cvDoctoresOcultos = new Set();

function startOfWeek(d) {
    const r = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    r.setDate(r.getDate() - r.getDay());
    return r;
}

function cvEscapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function mismaFecha(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function cvRangeLabel(weekStart) {
    const end = new Date(weekStart);
    end.setDate(end.getDate() + 6);
    const sd = weekStart.getDate(), ed = end.getDate(), yr = end.getFullYear();
    const sm = CV_MESES[weekStart.getMonth()], em = CV_MESES[end.getMonth()];
    if (CAL_LANG === 'es') {
        if (weekStart.getMonth() === end.getMonth()) return `${sd} - ${ed} de ${em} ${yr}`;
        return `${sd} de ${sm} - ${ed} de ${em} ${yr}`;
    }
    if (weekStart.getMonth() === end.getMonth()) return `${em} ${sd} - ${ed}, ${yr}`;
    return `${sm} ${sd} - ${em} ${ed}, ${yr}`;
}

function inicializarVistaPorDoctor() {
    const cont = document.getElementById('cvDoctorFiltros');

    // Si quien inicia sesión es un doctor y está en la lista, se muestra
    // solo su columna por defecto (las demás quedan ocultas).
    const soloMiColumna = loginDoctorId &&
        doctoresActivos.some(function (d) { return String(d.id) === String(loginDoctorId); });

    doctoresActivos.forEach(function (doc) {
        const id = 'cvDoc_' + doc.id;
        const esMio = String(doc.id) === String(loginDoctorId);
        const marcado = soloMiColumna ? esMio : true;
        if (soloMiColumna && !esMio) cvDoctoresOcultos.add(doc.nombre);

        const label = document.createElement('label');
        label.innerHTML = `<input type="checkbox" id="${id}"${marcado ? ' checked' : ''}> ${doc.nombre}`;
        if (esMio) {
            label.classList.add('fw-semibold');
            label.title = 'Doctor con la sesión iniciada';
        }
        cont.appendChild(label);
        label.querySelector('input').addEventListener('change', function (e) {
            if (e.target.checked) cvDoctoresOcultos.delete(doc.nombre);
            else cvDoctoresOcultos.add(doc.nombre);
            renderVistaPorDoctor();
        });
    });

    document.getElementById('cvToday').addEventListener('click', function () {
        cvWeekStart = startOfWeek(new Date());
        renderVistaPorDoctor();
    });
    document.getElementById('cvPrev').addEventListener('click', function () {
        cvWeekStart.setDate(cvWeekStart.getDate() - 7);
        renderVistaPorDoctor();
    });
    document.getElementById('cvNext').addEventListener('click', function () {
        cvWeekStart.setDate(cvWeekStart.getDate() + 7);
        renderVistaPorDoctor();
    });
}

function renderVistaPorDoctor() {
    const doctores = doctoresActivos.filter(d => !cvDoctoresOcultos.has(d.nombre));
    const grid      = document.getElementById('cvGrid');
    const nDoc      = Math.max(doctores.length, 1);
    const nSlots    = ((CV_END_HOUR - CV_START_HOUR) * 60) / CV_SLOT_MINUTES;
    const totalCols = 7 * nDoc;
    const gridW     = CV_TIME_COL_W + totalCols * CV_COL_WIDTH;
    const gridH      = CV_HEADER_H + nSlots * CV_SLOT_HEIGHT;

    document.getElementById('cvRangeLabel').textContent = cvRangeLabel(cvWeekStart);

    grid.innerHTML = '';
    grid.style.width  = gridW + 'px';
    grid.style.height = gridH + 'px';

    const hoy = new Date();

    // Cabeceras de día y doctor
    for (let dia = 0; dia < 7; dia++) {
        const fecha = new Date(cvWeekStart);
        fecha.setDate(fecha.getDate() + dia);
        const left      = CV_TIME_COL_W + dia * nDoc * CV_COL_WIDTH;
        const width     = nDoc * CV_COL_WIDTH;
        const esHoy     = mismaFecha(fecha, hoy);

        const dayHead = document.createElement('div');
        dayHead.className = 'cv-col-head cv-day-head' + (esHoy ? ' cv-today' : '');
        dayHead.style.left = left + 'px'; dayHead.style.top = '0px';
        dayHead.style.width = width + 'px'; dayHead.style.height = CV_DAY_HEAD_H + 'px';
        dayHead.textContent = `${CV_DIAS[fecha.getDay()]} ${fecha.getDate()}`;
        grid.appendChild(dayHead);

        doctores.forEach(function (doc, i) {
            const docHead = document.createElement('div');
            docHead.className = 'cv-col-head';
            docHead.style.left = (left + i * CV_COL_WIDTH) + 'px';
            docHead.style.top  = CV_DAY_HEAD_H + 'px';
            docHead.style.width = CV_COL_WIDTH + 'px';
            docHead.style.height = CV_DOC_HEAD_H + 'px';
            docHead.textContent = doc.nombre;
            grid.appendChild(docHead);
        });
    }

    // Etiquetas de hora + líneas de fondo
    for (let s = 0; s < nSlots; s++) {
        const minutos = CV_START_HOUR * 60 + s * CV_SLOT_MINUTES;
        const h = Math.floor(minutos / 60), m = minutos % 60;
        const ampm = h < 12 ? 'AM' : 'PM';
        const h12  = (h % 12) === 0 ? 12 : h % 12;
        const top  = CV_HEADER_H + s * CV_SLOT_HEIGHT;

        const lbl = document.createElement('div');
        lbl.className = 'cv-time-label';
        lbl.style.left = '0px'; lbl.style.top = top + 'px';
        lbl.style.height = CV_SLOT_HEIGHT + 'px';
        lbl.textContent = `${h12}:${String(m).padStart(2,'0')} ${ampm}`;
        grid.appendChild(lbl);

        for (let c = 0; c < totalCols; c++) {
            const cell = document.createElement('div');
            const esFinDia = (c + 1) % nDoc === 0;
            cell.className = 'cv-cell' + (esFinDia ? ' cv-day-end' : '');
            cell.style.left = (CV_TIME_COL_W + c * CV_COL_WIDTH) + 'px';
            cell.style.top  = top + 'px';
            cell.style.width = CV_COL_WIDTH + 'px';
            cell.style.height = CV_SLOT_HEIGHT + 'px';
            grid.appendChild(cell);
        }
    }

    // Eventos de la semana visible
    const weekEnd = new Date(cvWeekStart);
    weekEnd.setDate(weekEnd.getDate() + 7);

    eventosAll.forEach(function (ev) {
        const inicio = new Date(ev.start);
        const fin    = new Date(ev.end);
        if (inicio < cvWeekStart || inicio >= weekEnd) return;

        const docIdx = doctores.findIndex(d => d.nombre === ev.extendedProps.medico);
        if (docIdx === -1) return;

        const diaIdx = Math.floor((inicio - cvWeekStart) / 86400000);
        const minutosInicio = inicio.getHours() * 60 + inicio.getMinutes() - CV_START_HOUR * 60;
        const duracionMin   = Math.max((fin - inicio) / 60000, CV_SLOT_MINUTES / 2);

        const left   = CV_TIME_COL_W + (diaIdx * nDoc + docIdx) * CV_COL_WIDTH + 2;
        const top    = CV_HEADER_H + (minutosInicio / CV_SLOT_MINUTES) * CV_SLOT_HEIGHT;
        const height = Math.max((duracionMin / CV_SLOT_MINUTES) * CV_SLOT_HEIGHT - 2, 18);

        const div = document.createElement('div');
        div.className = 'cv-event';
        div.style.left   = left + 'px';
        div.style.top    = top + 'px';
        div.style.width  = (CV_COL_WIDTH - 4) + 'px';
        div.style.height = height + 'px';
        div.style.background  = ev.backgroundColor;
        div.style.color       = ev.textColor || '#ffffff';
        div.style.borderLeft  = '5px solid ' + (ev.borderColor || '#333');
        div.innerHTML = `<b>${inicio.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</b>${cvEscapeHtml(ev.title)}`;
        div.addEventListener('click', function () {
            abrirModalCita(ev.id, ev.title, inicio, ev.extendedProps);
        });
        grid.appendChild(div);
    });

    // ── Lista para móvil (misma semana, agrupada por día) ──────────────
    renderVistaPorDoctorLista(doctores, weekEnd);
}

// Lista de citas para celular: tarjetas agrupadas por día
function renderVistaPorDoctorLista(doctores, weekEnd) {
    const cont = document.getElementById('cvList');
    if (!cont) return;
    cont.innerHTML = '';

    const fmt = (d) => d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    for (let dia = 0; dia < 7; dia++) {
        const fecha = new Date(cvWeekStart);
        fecha.setDate(fecha.getDate() + dia);
        const finDia = new Date(fecha);
        finDia.setDate(finDia.getDate() + 1);

        // Citas de este día con doctor visible, ordenadas por hora
        const citasDia = eventosAll.filter(function (ev) {
            const inicio = new Date(ev.start);
            if (inicio < fecha || inicio >= finDia) return false;
            return doctores.some(d => d.nombre === ev.extendedProps.medico);
        }).sort((a, b) => new Date(a.start) - new Date(b.start));

        if (citasDia.length === 0) continue;

        const titulo = document.createElement('div');
        titulo.className = 'cv-list-day';
        titulo.textContent = CAL_LANG === 'es'
            ? `${CV_DIAS[fecha.getDay()]} ${fecha.getDate()} de ${CV_MESES[fecha.getMonth()]}`
            : `${CV_DIAS[fecha.getDay()]}, ${CV_MESES[fecha.getMonth()]} ${fecha.getDate()}`;
        cont.appendChild(titulo);

        citasDia.forEach(function (ev) {
            const inicio = new Date(ev.start);
            const fin    = new Date(ev.end);
            const card = document.createElement('div');
            card.className = 'cv-list-card';
            card.innerHTML =
                `<div class="cv-list-color" style="background:${ev.borderColor || '#333'}"></div>` +
                `<div class="cv-list-body" style="background:${ev.backgroundColor};color:${ev.textColor || '#ffffff'}">` +
                    `<div class="hora">${fmt(inicio)} - ${fmt(fin)}</div>` +
                    `<div class="pac">${cvEscapeHtml(ev.title)}</div>` +
                    `<div class="sub">${cvEscapeHtml(ev.extendedProps.consulta || '')} — Dr. ${cvEscapeHtml(ev.extendedProps.medico || '')}</div>` +
                `</div>`;
            card.addEventListener('click', function () {
                abrirModalCita(ev.id, ev.title, inicio, ev.extendedProps);
            });
            cont.appendChild(card);
        });
    }

    if (!cont.children.length) {
        cont.innerHTML = '<p class="text-muted text-center my-3">' + TC.noApptsWeek + '</p>';
    }
}

// ── Select2 en modal Agendar ─────────────────────────────────────────
// Destruir cualquier instancia previa antes de crear una nueva evita que,
// al reabrir el modal, se apilen contenedores select2 duplicados (lo que
// hacía que la selección de Doctor "rebotara" en algunas pantallas).
function _destruirSelect2Agendar() {
    $('#editModal .select-busqueda').each(function () {
        if ($(this).hasClass('select2-hidden-accessible')) {
            try { $(this).select2('destroy'); } catch (e) {}
        }
    });
}
function _initSelect2Agendar() {
    _destruirSelect2Agendar();
    $('#editModal .select-busqueda').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#editModal'),
        width: '100%'
    });
}
// Muestra el diagnóstico ICD-10 del paciente al elegirlo en el formulario de
// agendar (así "se conecta" el diagnóstico con la cita). Sólo informativo.
function _cargarIcd10Cita() {
    var sel  = document.querySelector('#editModal [name="IdPaciente"]');
    var cont = document.getElementById('citaIcd10Info');
    if (!sel || !cont) return;
    var id = sel.value;
    if (!id) { cont.innerHTML = ''; return; }
    cont.innerHTML = '<span class="text-muted">…</span>';
    $.getJSON('paciente_icd10.php', { accion: 'listar', idPaciente: id })
      .done(function (res) {
          if (res && res.ok && res.lista && res.lista.length) {
              var chips = res.lista.map(function (d) {
                  return '<span class="badge bg-info text-dark me-1 mb-1">' + escHtml(d.codigo) + ' — ' + escHtml(d.descripcion || '') + '</span>';
              }).join('');
              cont.innerHTML = '<div class="text-muted mb-1"><i class="bi bi-clipboard2-pulse me-1"></i>' + escHtml(TC.apptDiagLabel) + '</div>' + chips;
          } else {
              cont.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(TC.apptDiagNone) + '</span>';
          }
      })
      .fail(function () { cont.innerHTML = ''; });
}
$(document).ready(function () {
    $('#editModal').on('shown.bs.modal', _initSelect2Agendar);
    $('#editModal').on('hidden.bs.modal', function () {
        _destruirSelect2Agendar();
        var c = document.getElementById('citaIcd10Info'); if (c) c.innerHTML = '';
    });
    // Al cambiar el paciente (select2 dispara change en el <select>), mostrar su ICD-10.
    $(document).on('change', '#editModal [name="IdPaciente"]', _cargarIcd10Cita);
});

// ── Recurrencia (mostrar/ocultar controles) ──────────────────────────
function toggleRecurrenciaUI() {
    var sel  = document.getElementById('recurrenciaSel');
    var wrap = document.getElementById('recurEndWrap');
    var custom = document.getElementById('recurCustomWrap');
    if (!sel || !wrap) return;
    var isRecur = sel.value !== 'none';
    wrap.classList.toggle('d-none', !isRecur);
    custom.classList.toggle('d-none', sel.value !== 'custom');
    var mode = document.getElementById('recurEndMode').value;
    document.getElementById('recurCountWrap').classList.toggle('d-none', mode !== 'count');
    document.getElementById('recurEndDateWrap').classList.toggle('d-none', mode !== 'date');
}
document.addEventListener('DOMContentLoaded', toggleRecurrenciaUI);

// ── Confirmar antes de crear una serie recurrente + evitar doble envío ─
var _citaEnviando = false;
// ── Aviso de vacaciones/no disponibilidad del doctor al agendar ──────
function _ausTextoConflicto(c){
    if (!c) return '';
    if (c.tipo === 'vacacion') return TC.ausVac.replace('{doctor}', c.doctor || '');
    return TC.ausBlq.replace('{doctor}', c.doctor || '').replace('{ini}', c.hora_inicio || '').replace('{fin}', c.hora_fin || '');
}
function revisarAusenciaForm(){
    var box = document.getElementById('ausAviso'); if(!box) return;
    var idDoc = (document.querySelector('#insertCita [name="IdDoctor"]') || {}).value || '';
    var fecha = (document.getElementById('fechafactura') || {}).value || '';
    var hora  = (document.getElementById('timeIni') || {}).value || '';
    var c = ausenciaConflicto(idDoc, fecha, hora);
    if (c) { box.innerHTML = '<i class="bi bi-airplane me-1"></i>' + _ausTextoConflicto(c); box.classList.remove('d-none'); }
    else   { box.classList.add('d-none'); box.innerHTML = ''; }
}
document.addEventListener('DOMContentLoaded', function(){
    var f = document.getElementById('insertCita'); if(!f) return;
    ['IdDoctor','fechafactura','timeIni'].forEach(function(n){
        var el = f.querySelector('[name="'+n+'"]') || document.getElementById(n);
        if (el) el.addEventListener('change', revisarAusenciaForm);
    });
});

function confirmarRecurrencia() {
    if (_citaEnviando) return false;   // ya se está enviando: bloquea el doble clic
    // Aviso si el doctor está de vacaciones / no disponible ese día u hora.
    var _idDoc = (document.querySelector('#insertCita [name="IdDoctor"]') || {}).value || '';
    var _fecha = (document.getElementById('fechafactura') || {}).value || '';
    var _hora  = (document.getElementById('timeIni') || {}).value || '';
    var _c = ausenciaConflicto(_idDoc, _fecha, _hora);
    if (_c && !confirm(_ausTextoConflicto(_c) + '\n\n' + TC.ausConfirm)) return false;
    var sel = document.getElementById('recurrenciaSel');
    if (sel && sel.value !== 'none') {
        var tipo = sel.options[sel.selectedIndex].text;
        var modo = (document.getElementById('recurEndMode') || {}).value || 'count';
        var msg;
        if (modo === 'count') {
            var n = parseInt((document.getElementById('recurCount') || {}).value || '1', 10);
            n = Math.max(1, Math.min(isNaN(n) ? 1 : n, 260));
            msg = TC.recurConfirm.replace('{n}', n).replace('{tipo}', tipo);
        } else {
            msg = TC.recurConfirmDate.replace('{tipo}', tipo);
        }
        if (!confirm(msg)) return false;
    }
    // A partir de aquí el formulario se envía: bloquear reenvíos.
    _citaEnviando = true;
    setTimeout(function () {
        var btn = document.querySelector('#insertCita button[type="submit"]');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> ' + (TC.saving || ''); }
    }, 0);
    return true;
}
</script>

<!-- ── Buscador de pacientes (estilo Kalix) → abre el Historial de atenciones ── -->
<script>
(function(){
    const input = document.getElementById('calPacienteBuscar');
    const box   = document.getElementById('calPacienteResultados');
    const clear = document.getElementById('calPacienteClear');
    if(!input) return;

    const CAL_SEARCH_NONE = <?php echo json_encode(t('cal.searchNone')); ?>;
    let timer = null, items = [], activeIdx = -1;

    function hide(){ box.classList.add('d-none'); box.innerHTML=''; items=[]; activeIdx=-1; }
    function irA(id){
        // Abre la ficha del paciente en un modal editable, sobre el calendario.
        if (typeof window.editarPacientePorId === 'function') { window.editarPacientePorId(id); }
        else { window.location.href = 'pacientes_crud.php?id=' + encodeURIComponent(id); }
    }
    function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

    function render(data){
        items = Array.isArray(data) ? data : [];
        if(!items.length){
            box.innerHTML = '<div class="csr-empty">' + escapeHtml(CAL_SEARCH_NONE) + '</div>';
            box.classList.remove('d-none');
            return;
        }
        box.innerHTML = items.map(function(p,i){
            var meta = [p.cedula, p.telefono].filter(Boolean).join('  ·  ');
            return '<div class="csr-item" data-id="'+p.id+'" data-i="'+i+'">'
                 + '<div class="csr-name">' + escapeHtml(p.nombre) + '</div>'
                 + (meta ? '<div class="csr-meta">' + escapeHtml(meta) + '</div>' : '')
                 + '</div>';
        }).join('');
        box.classList.remove('d-none');
        Array.prototype.forEach.call(box.querySelectorAll('.csr-item'), function(el){
            el.addEventListener('mousedown', function(e){ e.preventDefault(); irA(el.getAttribute('data-id')); });
        });
        activeIdx = -1;
    }

    input.addEventListener('input', function(){
        var q = input.value.trim();
        clear.classList.toggle('d-none', q.length === 0);
        if(timer) clearTimeout(timer);
        if(q.length < 2){ hide(); return; }
        timer = setTimeout(function(){
            fetch('buscar_pacientes.php?q=' + encodeURIComponent(q))
                .then(function(r){ return r.json(); })
                .then(render)
                .catch(hide);
        }, 220);
    });

    input.addEventListener('keydown', function(e){
        if(box.classList.contains('d-none')) return;
        var els = box.querySelectorAll('.csr-item');
        if(!els.length) return;
        if(e.key === 'ArrowDown'){ e.preventDefault(); activeIdx = Math.min(activeIdx+1, els.length-1); }
        else if(e.key === 'ArrowUp'){ e.preventDefault(); activeIdx = Math.max(activeIdx-1, 0); }
        else if(e.key === 'Enter'){ if(activeIdx>=0 && items[activeIdx]){ e.preventDefault(); irA(items[activeIdx].id); } return; }
        else if(e.key === 'Escape'){ hide(); return; }
        else return;
        Array.prototype.forEach.call(els, function(el,i){ el.classList.toggle('active', i===activeIdx); });
        if(els[activeIdx]) els[activeIdx].scrollIntoView({block:'nearest'});
    });

    clear.addEventListener('click', function(){ input.value=''; clear.classList.add('d-none'); hide(); input.focus(); });
    document.addEventListener('click', function(e){ if(!e.target.closest('.cal-patient-search')) hide(); });
})();
</script>

<!-- ── MODAL: Alcance de la acción para series recurrentes ────── -->
<div class="modal fade" id="serieScopeModal" tabindex="-1" aria-labelledby="serieScopeLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serieScopeLabel"><i class="bi bi-arrow-repeat me-2"></i><span id="serieScopeTitle"><?php te('cal.serie.title'); ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php te('common.close'); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><?php te('cal.serie.body1'); ?></p>
                <p class="text-muted small mb-0"><?php te('cal.serie.body2'); ?></p>
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" id="btnScopeSolo"><?php te('cal.serie.onlyThis'); ?></button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php te('common.cancel'); ?></button>
                    <button type="button" class="btn btn-warning" id="btnScopeTodas"><?php te('cal.serie.allFuture'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Diálogo de alcance para series recurrentes ─────────────────────
// Uso: askSerieScope(actionLabel).then(scope => 'solo' | 'todas' | null)
function askSerieScope(actionLabel) {
    return new Promise(function(resolve) {
        var m = document.getElementById('serieScopeModal');
        var titleSpan = document.getElementById('serieScopeTitle');
        if (actionLabel) titleSpan.textContent = actionLabel;
        var modal = bootstrap.Modal.getOrCreateInstance(m);
        var solo  = document.getElementById('btnScopeSolo');
        var todas = document.getElementById('btnScopeTodas');
        var done = false;
        function cleanup() {
            solo.removeEventListener('click', onSolo);
            todas.removeEventListener('click', onTodas);
            m.removeEventListener('hidden.bs.modal', onHide);
        }
        function onSolo()  { done = true; modal.hide(); cleanup(); resolve('solo'); }
        function onTodas() { done = true; modal.hide(); cleanup(); resolve('todas'); }
        function onHide()  { if (!done) { cleanup(); resolve(null); } }
        solo.addEventListener('click', onSolo);
        todas.addEventListener('click', onTodas);
        m.addEventListener('hidden.bs.modal', onHide);
        modal.show();
    });
}
</script>

</body>
</html>
