<?php
/**
 * turno_accion.php — Acciones de la atención rápida (JSON).
 * GET  accion=buscar&cedula=…            (rapida.ingresar)  → paciente existente / validez de la cédula
 * POST accion=crear …                    (rapida.ingresar)
 * POST accion=cobrar idTurno,monto,metodo,referencia   (rapida.cobrar)
 * POST accion=cancelar idTurno           (rapida.ingresar)
 * POST accion=tomar idTurno              (rapida.atender)  → url a la que ir
 * POST accion=terminar idTurno           (rapida.atender)
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/turnos.php");
header('Content-Type: application/json; charset=utf-8');
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION['rol'])) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { echo json_encode(['ok'=>false,'error'=>'SIN_SESION']); exit; }
if (!modulo_activo('atencion_rapida')) { echo json_encode(['ok'=>false,'error'=>'MODULO_APAGADO']); exit; }
turnos_asegurar_tablas($conexion);
$uid = (int)($_SESSION['iduser'] ?? 0);
$acc = $_REQUEST['accion'] ?? '';
$deny = function($k) { if (!puede($k)) { echo json_encode(['ok'=>false,'error'=>'SIN_PERMISO']); exit; } };

if ($acc === 'buscar') {
    $deny('rapida.ingresar');
    $ced = trim((string)($_GET['cedula'] ?? ''));
    $p = turno_buscar_cedula($conexion, $ced);
    $enEspera = false;
    if ($p) { $q = $conexion->query("SELECT 1 FROM turno WHERE IDPACIENTE = ".(int)$p['IDPACIENTE']." AND ESTADO IN ('Triaje','Espera','Atencion') AND DATE(FECHA_INGRESO)=CURDATE() LIMIT 1"); $enEspera = $q && $q->fetch_assoc() ? true : false; }
    echo json_encode(['ok'=>true, 'paciente'=>$p ? ['id'=>(int)$p['IDPACIENTE'], 'nombres'=>$p['NOMBRES'], 'apellidos'=>$p['APELLIDOS'], 'telefono'=>$p['TELEFONO']] : null,
        'diez_digitos'=>(bool)preg_match('/^\d{10}$/', $ced), 'cedula_valida'=>cedula_ec_valida($ced), 'en_espera'=>$enEspera], JSON_UNESCAPED_UNICODE); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'METODO']); exit; }

if ($acc === 'crear') {
    $deny('rapida.ingresar');
    if (!empty($_POST['cobrar_ahora'])) $deny('rapida.cobrar');
    echo json_encode(turno_crear($conexion, $_POST, $uid), JSON_UNESCAPED_UNICODE);
} elseif ($acc === 'cobrar') {
    $deny('rapida.cobrar');
    echo json_encode(turno_cobrar($conexion, (int)($_POST['idTurno'] ?? 0), $_POST['monto'] ?? 0, $_POST['metodo'] ?? 'Efectivo', $_POST['referencia'] ?? '', $uid), JSON_UNESCAPED_UNICODE);
} elseif ($acc === 'cancelar') {
    $deny('rapida.ingresar');
    echo json_encode(['ok'=>turno_cancelar($conexion, (int)($_POST['idTurno'] ?? 0), $uid)]);
} elseif ($acc === 'tomar') {
    $deny('rapida.atender');
    $r = turno_tomar($conexion, (int)($_POST['idTurno'] ?? 0), $uid);
    if ($r['ok']) {
        // Servicios de enfermería (p. ej. sueroterapia) → mesa de Sueros; el resto → pantalla de atención
        $idT = (int)$_POST['idTurno'];
        $q = $conexion->query("SELECT COUNT(*) c FROM turno_servicio S INNER JOIN AG_TIPOCONSULTA X ON X.IDTIPOCONSULTA=S.IDTIPOCONSULTA WHERE S.IDTURNO=$idT AND X.ROL_ATIENDE='ENFERMERA'");
        $esEnf = $q && (int)$q->fetch_assoc()['c'] > 0 && strtoupper($_SESSION['rol']) === 'ENFERMERA';
        $r['url'] = $esEnf ? 'sueros.php' : 'atencion_paciente.php?idCita=' . (int)$r['idCita'];
    }
    echo json_encode($r);
} elseif ($acc === 'terminar') {
    $deny('rapida.atender');
    echo json_encode(['ok'=>turno_terminar($conexion, (int)($_POST['idTurno'] ?? 0), $uid)]);
} else { echo json_encode(['ok'=>false,'error'=>'ACCION']); }
