<?php
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once("class/email_cita.php");
require_once("class/auditoria.php");
$conexion = conectarse();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

if (!isset($_SESSION["rol"])) {
    echo 'SIN_SESION';
    exit;
}

$idCita  = isset($_POST['idCita'])  ? (int)$_POST['idCita']  : 0;
$fecha   = isset($_POST['fecha'])   ? trim($_POST['fecha'])  : '';
$hora    = isset($_POST['hora'])    ? trim($_POST['hora'])   : '';
$alcance = isset($_POST['alcance']) ? trim($_POST['alcance']) : 'solo';
if ($alcance !== 'todas') $alcance = 'solo';

if (!$idCita || !$fecha || !$hora) {
    echo 'DATOS_INCOMPLETOS';
    exit;
}

// Validar formato de fecha (YYYY-MM-DD) y hora (HH:MM)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
    echo 'FORMATO_INVALIDO';
    exit;
}

// Hora fin = 1 hora después (toda cita dura 1 hora, como Kalix)
$horaFin = date('H:i', strtotime($hora) + 60 * 60);

// ¿Existe la columna IDSERIE? (para saber si podemos aplicar a toda la serie futura)
$dbName = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
$tieneSerie = (int)$conexion->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='AG_CITA' AND COLUMN_NAME='IDSERIE'"
)->fetch_assoc()['c'] > 0;

// Leer la fecha/hora actuales ANTES de reagendar, para incluir la anterior en el correo
$colSerie = $tieneSerie ? ', IDSERIE' : '';
$stmt_old = $conexion->prepare(
    "SELECT FECHA_CITA, HORA_INICIO $colSerie FROM AG_CITA WHERE IDCITA = ? AND ESTADO = 'A'"
);
$stmt_old->bind_param("i", $idCita);
$stmt_old->execute();
$citaVieja = $stmt_old->get_result()->fetch_assoc();
$stmt_old->close();

if (!$citaVieja) {
    echo 'NO_ENCONTRADA';
    exit;
}

$fechaAnterior = $citaVieja['FECHA_CITA'];
$horaAnterior  = substr($citaVieja['HORA_INICIO'] ?? '', 0, 5);
$idSerie       = $tieneSerie ? (int)($citaVieja['IDSERIE'] ?? 0) : 0;

if ($alcance === 'todas' && $idSerie > 0) {
    // Mover ESTA cita y TODAS las futuras de la serie por el mismo desfase de
    // días, de modo que toda la serie se mantenga alineada (mismo día de la
    // semana y misma cadencia). Ej.: si la cita del viernes se mueve al
    // miércoles, todas las futuras pasan a miércoles conservando el intervalo.
    $deltaDays = (int)round((strtotime($fecha) - strtotime($fechaAnterior)) / 86400);
    $stmt = $conexion->prepare(
        "UPDATE AG_CITA
         SET FECHA_CITA  = DATE_ADD(FECHA_CITA, INTERVAL ? DAY),
             HORA_INICIO = ?,
             HORA_FIN    = ?,
             ESTADO_CITA = 'Pendiente'
         WHERE (IDSERIE = ? OR IDCITA = ?)
           AND ESTADO = 'A'
           AND FECHA_CITA >= ?"
    );
    $stmt->bind_param("issiis", $deltaDays, $hora, $horaFin, $idSerie, $idCita, $fechaAnterior);
} else {
    // Reagendar solo esta cita
    $stmt = $conexion->prepare(
        "UPDATE AG_CITA
         SET FECHA_CITA  = ?,
             HORA_INICIO = ?,
             HORA_FIN    = ?,
             ESTADO_CITA = 'Pendiente'
         WHERE IDCITA = ? AND ESTADO = 'A'"
    );
    $stmt->bind_param("sssi", $fecha, $hora, $horaFin, $idCita);
}

if (!$stmt->execute()) {
    echo 'ERROR: ' . $stmt->error;
    $stmt->close();
    exit;
}
$stmt->close();

auditar($conexion, 'Agenda', 'reagendar', 'AG_CITA', $idCita,
    ($alcance === 'todas' ? 'Reagendó la serie' : 'Reagendó la cita').
    ' de '.$fechaAnterior.' '.$horaAnterior.' a '.$fecha.' '.$hora);

// ── Notificar al paciente del reagendamiento ────────────────────────
$stmt_info = $conexion->prepare(
    "SELECT P.IDPACIENTE, P.NOMBRES, P.APELLIDOS, P.EMAIL,
            TC.NOMBRES AS TIPO_CONSULTA,
            CONCAT(D.NOMBRES,' ',D.APELLIDOS) AS DOCTOR
     FROM AG_CITA A
     INNER JOIN AG_PACIENTE P      ON A.IDPACIENTE     = P.IDPACIENTE
     LEFT  JOIN AG_TIPOCONSULTA TC ON A.IDTIPOCONSULTA = TC.IDTIPOCONSULTA
     LEFT  JOIN ADM_USUARIO D      ON A.IDDOCTOR       = D.IDADM_USUARIO
     WHERE A.IDCITA = ?"
);
$stmt_info->bind_param("i", $idCita);
$stmt_info->execute();
$info = $stmt_info->get_result()->fetch_assoc();
$stmt_info->close();

$nombrePaciente = trim(($info['NOMBRES'] ?? '') . ' ' . ($info['APELLIDOS'] ?? ''));
$correoPaciente = $info['EMAIL'] ?? '';
$tipoConsulta   = $info['TIPO_CONSULTA'] ?? 'Consulta';
$doctorNombre   = $info['DOCTOR'] ?? '';

// Sin correo registrado: la cita ya se reagendó, solo avisamos
if (!$correoPaciente) {
    echo 'OK_SIN_CORREO';
    $conexion->close();
    exit;
}

$langPaciente = patient_lang($conexion, (int)($info['IDPACIENTE'] ?? 0));
$emailCita = build_cita_email('reagendada', $langPaciente, [
    'nombre'        => $nombrePaciente,
    'fecha'         => $fecha,
    'hora'          => $hora,
    'horaFin'       => $horaFin,
    'tipoConsulta'  => $tipoConsulta,
    'doctorNombre'  => $doctorNombre,
    'fechaAnterior' => $fechaAnterior,
    'horaAnterior'  => $horaAnterior,
]);
$htmlBody = $emailCita['html'];
$textBody = $emailCita['text'];

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'mail.srossnutritions.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'citamedica@srossnutritions.com';
    $mail->Password   = 'QVseUdgYE7TAGRF6bUQf';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';

    $mail->SMTPOptions = ['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]];

    $mail->setFrom('citamedica@srossnutritions.com', $emailCita['fromName']);
    $mail->addAddress($correoPaciente, $nombrePaciente);
    $mail->addReplyTo('citamedica@srossnutritions.com', $emailCita['replyName']);

    $mail->isHTML(true);
    $mail->Subject  = '=?UTF-8?B?' . base64_encode($emailCita['subject']) . '?=';
    $mail->Body     = $htmlBody;
    $mail->AltBody  = $textBody;

    $mail->send();

    echo 'OK';
} catch (Exception $e) {
    // La cita YA fue reagendada; solo el correo falló
    error_log("PHPMailer error (reagenda) para $correoPaciente: " . $mail->ErrorInfo);
    echo 'OK_SIN_CORREO';
}

$conexion->close();
