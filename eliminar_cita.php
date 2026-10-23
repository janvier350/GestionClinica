<?php
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once("class/email_cita.php");
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
$alcance = isset($_POST['alcance']) ? trim($_POST['alcance']) : 'solo';
$motivo  = isset($_POST['motivo'])  ? trim($_POST['motivo'])  : '';
if ($alcance !== 'todas') $alcance = 'solo';
$idUser  = (int)($_SESSION['iduser'] ?? 0);

if (!$idCita) {
    echo 'DATOS_INCOMPLETOS';
    exit;
}

// ¿Existe la columna IDSERIE?
$dbName = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
$tieneSerie = (int)$conexion->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='AG_CITA' AND COLUMN_NAME='IDSERIE'"
)->fetch_assoc()['c'] > 0;

// ¿Existen las columnas de auditoría de cancelación?
$tieneAuditCancel = (int)$conexion->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='AG_CITA' AND COLUMN_NAME='MOTIVO_CANCELACION'"
)->fetch_assoc()['c'] > 0;
$setCancel = $tieneAuditCancel
    ? ", MOTIVO_CANCELACION = ?, FECHA_CANCELACION = NOW(), CANCELADO_POR = ?"
    : "";

// Obtener los datos de la cita ANTES de eliminarla (para el correo de cancelación)
$colSerie = $tieneSerie ? ', A.IDSERIE' : '';
$stmt_info = $conexion->prepare(
    "SELECT P.IDPACIENTE, P.NOMBRES, P.APELLIDOS, P.EMAIL,
            A.FECHA_CITA, A.HORA_INICIO, A.HORA_FIN,
            TC.NOMBRES AS TIPO_CONSULTA,
            CONCAT(D.NOMBRES,' ',D.APELLIDOS) AS DOCTOR
            $colSerie
     FROM AG_CITA A
     INNER JOIN AG_PACIENTE P      ON A.IDPACIENTE     = P.IDPACIENTE
     LEFT  JOIN AG_TIPOCONSULTA TC ON A.IDTIPOCONSULTA = TC.IDTIPOCONSULTA
     LEFT  JOIN ADM_USUARIO D      ON A.IDDOCTOR       = D.IDADM_USUARIO
     WHERE A.IDCITA = ? AND A.ESTADO = 'A'"
);
$stmt_info->bind_param("i", $idCita);
$stmt_info->execute();
$info = $stmt_info->get_result()->fetch_assoc();
$stmt_info->close();

if (!$info) {
    echo 'NO_ENCONTRADA';
    exit;
}

$idSerie      = $tieneSerie ? (int)($info['IDSERIE'] ?? 0) : 0;
$fechaOrig    = $info['FECHA_CITA'];
$canceladasEx = 0;

if ($alcance === 'todas' && $idSerie > 0) {
    // Cancelar esta + todas las futuras de la serie (borrado lógico).
    $stmt = $conexion->prepare(
        "UPDATE AG_CITA SET ESTADO = 'I', ESTADO_CITA = 'Cancelado'$setCancel
         WHERE (IDSERIE = ? OR IDCITA = ?)
           AND ESTADO = 'A'
           AND FECHA_CITA >= ?"
    );
    if ($tieneAuditCancel) {
        $stmt->bind_param("siiis", $motivo, $idUser, $idSerie, $idCita, $fechaOrig);
    } else {
        $stmt->bind_param("iis", $idSerie, $idCita, $fechaOrig);
    }
    if (!$stmt->execute()) {
        echo 'ERROR: ' . $stmt->error;
        $stmt->close();
        exit;
    }
    $canceladasEx = max(0, $stmt->affected_rows - 1);
    $stmt->close();
} else {
    // Cancelar solo esta cita (borrado lógico).
    $stmt = $conexion->prepare(
        "UPDATE AG_CITA SET ESTADO = 'I', ESTADO_CITA = 'Cancelado'$setCancel
         WHERE IDCITA = ? AND ESTADO = 'A'"
    );
    if ($tieneAuditCancel) {
        $stmt->bind_param("sii", $motivo, $idUser, $idCita);
    } else {
        $stmt->bind_param("i", $idCita);
    }
    if (!$stmt->execute()) {
        echo 'ERROR: ' . $stmt->error;
        $stmt->close();
        exit;
    }
    $stmt->close();
}

// ── Preparar y enviar correo de cancelación ─────────────────────────
$nombrePaciente = trim(($info['NOMBRES'] ?? '') . ' ' . ($info['APELLIDOS'] ?? ''));
$correoPaciente = $info['EMAIL'] ?? '';
$tipoConsulta   = $info['TIPO_CONSULTA'] ?? 'Consulta';
$doctorNombre   = $info['DOCTOR'] ?? '';
$horaIni        = substr($info['HORA_INICIO'] ?? '', 0, 5);
$horaFin        = substr($info['HORA_FIN'] ?? '', 0, 5);

// Formatear fecha en español
$fechaObj  = DateTime::createFromFormat('Y-m-d', $info['FECHA_CITA']);
$diasES    = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$mesesES   = ['','enero','febrero','marzo','abril','mayo','junio',
              'julio','agosto','septiembre','octubre','noviembre','diciembre'];
$fechaBonita = $fechaObj
    ? $diasES[(int)$fechaObj->format('w')] . ', ' .
      (int)$fechaObj->format('j') . ' de ' .
      $mesesES[(int)$fechaObj->format('n')] . ' de ' .
      $fechaObj->format('Y')
    : $info['FECHA_CITA'];

// Sin correo registrado: la cita ya se eliminó, solo avisamos
if (!$correoPaciente) {
    echo 'OK_SIN_CORREO';
    exit;
}

$langPaciente = patient_lang($conexion, (int)($info['IDPACIENTE'] ?? 0));
$emailCita = build_cita_email('cancelada', $langPaciente, [
    'nombre'       => $nombrePaciente,
    'fecha'        => $info['FECHA_CITA'] ?? '',
    'hora'         => $horaIni,
    'horaFin'      => $horaFin,
    'tipoConsulta' => $tipoConsulta,
    'doctorNombre' => $doctorNombre,
]);
$htmlBody = $emailCita['html'];
$textBody = $emailCita['text'];

$mail = new PHPMailer(true);
try {
    mailer_configurar($mail);   // SMTP + remitente desde Panel de Control → Correo


    $mail->addAddress($correoPaciente, $nombrePaciente);

    $mail->isHTML(true);
    $mail->Subject  = '=?UTF-8?B?' . base64_encode($emailCita['subject']) . '?=';
    $mail->Body     = $htmlBody;
    $mail->AltBody  = $textBody;

    $mail->send();

    echo 'OK';
} catch (Exception $e) {
    // La cita YA fue eliminada; solo el correo falló
    error_log("PHPMailer error (cancelación) para $correoPaciente: " . $mail->ErrorInfo);
    echo 'OK_SIN_CORREO';
}

$conexion->close();
