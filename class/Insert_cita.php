<?php
require_once("funciones.php");
require_once("conexionBD.php");
require_once("email_cita.php");
require_once(__DIR__ . "/auditoria.php");
$conexion = conectarse();
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';

// Recoger y sanitizar datos del formulario
$fechafactura = $conexion->real_escape_string($_POST['fechafactura'] ?? '');
$IdPaciente   = (int)($_POST['IdPaciente'] ?? 0);
$timeIni      = $conexion->real_escape_string($_POST['timeIni']      ?? '');
$Idconsulta   = (int)($_POST['Idconsulta'] ?? 0);
$IdDoctor     = (int)($_POST['IdDoctor']   ?? 0);
$IdAgencia    = (int)($_POST['IdAgencia']  ?? 0);   // Ubicación (Location)

// Recurrencia (opcional)
$recurrencia   = strtolower(trim($_POST['recurrencia']   ?? 'none'));
$recurEvery    = (int)($_POST['recurEvery']              ?? 1);
$recurEndMode  = strtolower(trim($_POST['recurEndMode']  ?? 'count'));
$recurCount    = (int)($_POST['recurCount']              ?? 1);
$recurEndDate  = trim($_POST['recurEndDate']             ?? '');

// Doble reserva: si está activo, se agenda aunque el horario ya esté ocupado
// (las asistentes resuelven los choques manualmente).
$dobleReserva  = !empty($_POST['dobleReserva']);

if (!$fechafactura || !$IdPaciente || !$timeIni || !$Idconsulta || !$IdDoctor) {
    echo "<script>alert('Datos incompletos. Por favor complete todos los campos.'); history.back();</script>";
    exit;
}

// ── Regla: el paciente debe tener al menos un diagnóstico ICD-10 y dirección
//     para poder agendar (evita fichas incompletas; útil para facturación). ──
$dbEscC = $conexion->real_escape_string($conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db']);
$numDiag = 0;
if ((int)$conexion->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA='$dbEscC' AND TABLE_NAME='paciente_icd10'")->fetch_assoc()['c'] > 0) {
    $q = $conexion->query("SELECT COUNT(*) c FROM paciente_icd10 WHERE IDPACIENTE=".(int)$IdPaciente);
    $numDiag = $q ? (int)$q->fetch_assoc()['c'] : 0;
}
if ($numDiag === 0 && (int)$conexion->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbEscC' AND TABLE_NAME='AG_PACIENTE' AND COLUMN_NAME='IDICD10'")->fetch_assoc()['c'] > 0) {
    $q = $conexion->query("SELECT IDICD10 FROM AG_PACIENTE WHERE IDPACIENTE=".(int)$IdPaciente." LIMIT 1");
    if ($q && ($r = $q->fetch_assoc()) && (int)($r['IDICD10'] ?? 0) > 0) $numDiag = 1;
}
$tieneCity  = (int)$conexion->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbEscC' AND TABLE_NAME='AG_PACIENTE' AND COLUMN_NAME='CITY'")->fetch_assoc()['c'] > 0;
$tieneState = (int)$conexion->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbEscC' AND TABLE_NAME='AG_PACIENTE' AND COLUMN_NAME='STATE'")->fetch_assoc()['c'] > 0;
$selDir = "ADDRESS" . ($tieneCity ? ", CITY" : "") . ($tieneState ? ", STATE" : "");
$dir = ''; $city = ''; $state = '';
$q = $conexion->query("SELECT $selDir FROM AG_PACIENTE WHERE IDPACIENTE=".(int)$IdPaciente." LIMIT 1");
if ($q && ($r = $q->fetch_assoc())) {
    $dir   = trim((string)($r['ADDRESS'] ?? ''));
    $city  = trim((string)($r['CITY']  ?? ''));
    $state = trim((string)($r['STATE'] ?? ''));
}
// Dirección "completa" = tiene ADDRESS, o bien ciudad y estado.
$dirCompleta = ($dir !== '') || ($city !== '' && $state !== '');

@require_once(__DIR__ . '/../lang/i18n.php');
$en = (function_exists('current_lang') && current_lang() === 'en');
$faltantes = [];
if ($numDiag === 0)  $faltantes[] = $en ? 'a diagnosis (ICD-10)' : 'un diagnóstico (ICD-10)';
if (!$dirCompleta)   $faltantes[] = $en ? 'the address (street, or city and state)' : 'la dirección (calle o ciudad y estado)';
if ($faltantes) {
    if ($en) {
        $msg = "Cannot schedule: the patient is missing ".implode(' and ', $faltantes).
               ". Complete it in the patient's record (Manage Patients) and try again.";
    } else {
        $msg = "No se puede agendar: al paciente le falta ".implode(' y ', $faltantes).
               ". Complétalo en la ficha del paciente (Gestionar Pacientes) y vuelve a intentar.";
    }
    echo "<script>alert(".json_encode($msg, JSON_UNESCAPED_UNICODE)."); history.back();</script>";
    exit;
}

// Calcular hora final (+1 hora): toda cita dura 1 hora (como Kalix)
$timeFin = date("H:i", strtotime($timeIni) + 60 * 60);

/**
 * Genera todas las fechas de la serie (incluida la primera).
 * Cap de seguridad: hasta 52 ocurrencias.
 */
function generarFechasSerie($primera, $patron, $intervaloCustom, $modo, $cuenta, $fechaFin) {
    $fechas   = [];
    $baseDT   = DateTime::createFromFormat('Y-m-d', $primera);
    if (!$baseDT) return [$primera];
    $limite   = 260;  // tope de seguridad (≈5 años semanal); evita series enormes por error
    $endDT    = $fechaFin ? DateTime::createFromFormat('Y-m-d', $fechaFin) : null;
    if ($modo === 'count') {
        $cuenta = max(1, min($cuenta, $limite));
    } else {
        $cuenta = $limite; // hasta la fecha (con cap)
    }
    $step = null;
    switch ($patron) {
        case 'weekly':   $step = new DateInterval('P7D');  break;
        case 'biweekly': $step = new DateInterval('P14D'); break;
        case 'monthly':  $step = new DateInterval('P1M');  break;
        case 'daily':    $step = new DateInterval('P1D');  break;
        case 'custom':
            $n = max(1, min((int)$intervaloCustom, 90));
            $step = new DateInterval('P' . $n . 'D');
            break;
        default:
            return [$primera];
    }
    $cur = clone $baseDT;
    for ($i = 0; $i < $cuenta; $i++) {
        if ($endDT && $cur > $endDT) break;
        $fechas[] = $cur->format('Y-m-d');
        $cur->add($step);
    }
    return $fechas;
}

if ($recurrencia === 'none' || $recurrencia === '') {
    $fechasSerie = [$fechafactura];
} else {
    $fechasSerie = generarFechasSerie($fechafactura, $recurrencia, $recurEvery, $recurEndMode, $recurCount, $recurEndDate);
}

// Inserta cada fecha; salta las que choquen con otra cita activa DEL MISMO
// DOCTOR (dos doctores distintos pueden atender a la misma hora). Además se
// captura QUIÉN ocupa el horario, para informarlo con claridad.
$idUsuario   = $_SESSION['iduser'] ?? 0;
$stmt_valida = $conexion->prepare(
    "SELECT P.NOMBRES, P.APELLIDOS
       FROM AG_CITA A
       INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = A.IDPACIENTE
      WHERE A.FECHA_CITA = ? AND A.HORA_INICIO = ? AND A.ESTADO = 'A'
        AND A.ESTADO_CITA NOT IN ('Cancelada','Cancelado')
        AND A.IDDOCTOR = ?
      LIMIT 1"
);
$stmt_insert = $conexion->prepare(
    "INSERT INTO AG_CITA (IDPACIENTE, IDTIPOCONSULTA, IDDOCTOR, IDUSUARIO,
                          FECHA_CITA, HORA_INICIO, HORA_FIN, IDAGENCIA, ESTADO_CITA, ESTADO, COMENTARIO)
     VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), 'Pendiente', 'A', '')"
);

$primeraIdCita     = 0;
$primeraFechaOK    = '';
$creadas           = 0;
$saltadasPorChoque = [];
$saltadasDetalle   = [];

foreach ($fechasSerie as $f) {
    // Salvo que se permita la doble reserva, saltar si el mismo doctor ya
    // tiene una cita ese día y hora (y registrar quién la ocupa).
    if (!$dobleReserva) {
        $stmt_valida->bind_param("ssi", $f, $timeIni, $IdDoctor);
        $stmt_valida->execute();
        $rv = $stmt_valida->get_result();
        if ($occ = $rv->fetch_assoc()) {
            $saltadasPorChoque[] = $f;
            $ocupa = trim(($occ['NOMBRES'] ?? '') . ' ' . ($occ['APELLIDOS'] ?? ''));
            $saltadasDetalle[] = ['fecha' => $f, 'ocupa' => $ocupa];
            continue;
        }
    }

    $stmt_insert->bind_param("iiiisssi",
        $IdPaciente, $Idconsulta, $IdDoctor, $idUsuario,
        $f, $timeIni, $timeFin, $IdAgencia
    );
    if (!$stmt_insert->execute()) {
        continue;
    }
    $creadas++;
    if ($primeraIdCita === 0) {
        $primeraIdCita  = $conexion->insert_id;
        $primeraFechaOK = $f;
    }
}
$stmt_valida->close();
$stmt_insert->close();

if ($creadas === 0) {
    $lineas0 = [];
    foreach ($saltadasDetalle as $s) {
        $d0 = DateTime::createFromFormat('Y-m-d', $s['fecha']);
        $f0 = $d0 ? $d0->format('m/d/Y') : $s['fecha'];
        $oc0 = str_replace(["'", '"', "\\", "\n", "\r"], ' ', $s['ocupa']);
        $lineas0[] = $f0 . ($oc0 !== '' ? ' (ocupado por ' . $oc0 . ')' : ' (ocupado)');
    }
    $det0 = $lineas0 ? "\\n\\nFechas ocupadas:\\n- " . implode("\\n- ", $lineas0) : '';
    $msg0 = 'No se creó ninguna cita: el horario elegido ya está ocupado para ese doctor.' . $det0;
    echo "<script>alert(" . json_encode($msg0, JSON_UNESCAPED_UNICODE) . "); window.location.href = '../SCH_Calendar.php';</script>";
    exit;
}

// Si fue una serie con más de una cita creada, marca todas con IDSERIE = primeraIdCita.
$tieneSerie = false;
$dbNameQ = $conexion->query("SELECT DATABASE() AS db");
$dbName  = $dbNameQ ? $dbNameQ->fetch_assoc()['db'] : '';
if ($dbName) {
    $colExiste = (int)$conexion->query(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='AG_CITA' AND COLUMN_NAME='IDSERIE'"
    )->fetch_assoc()['c'] > 0;
    if ($colExiste && $creadas > 1) {
        // Los últimos $creadas IDs son secuenciales desde $primeraIdCita
        $ultimoIdCita = $primeraIdCita + $creadas - 1;
        $stmtSerie = $conexion->prepare(
            "UPDATE AG_CITA SET IDSERIE = ?
             WHERE IDCITA BETWEEN ? AND ?
               AND IDPACIENTE = ? AND HORA_INICIO = ?"
        );
        $stmtSerie->bind_param("iiiis", $primeraIdCita, $primeraIdCita, $ultimoIdCita, $IdPaciente, $timeIni);
        $stmtSerie->execute();
        $stmtSerie->close();
        $tieneSerie = true;
    }
}

auditar($conexion, 'Agenda', 'crear', 'AG_CITA', $primeraIdCita,
    'Creó '.$creadas.' cita(s)'.($tieneSerie ? ' (serie)' : '').' desde '.$primeraFechaOK.' '.$timeIni);

// El correo se enviará solo por la PRIMERA cita creada.
$fechafactura = $primeraFechaOK;

// Obtener datos del paciente (nombre + correo) y tipo de consulta
$stmt_info = $conexion->prepare(
    "SELECT P.NOMBRES, P.APELLIDOS, P.EMAIL,
            TC.NOMBRES AS TIPO_CONSULTA,
            CONCAT(D.NOMBRES,' ',D.APELLIDOS) AS DOCTOR
     FROM AG_PACIENTE P
     LEFT JOIN AG_TIPOCONSULTA TC ON TC.IDTIPOCONSULTA = ?
     LEFT JOIN ADM_DOCTOR D       ON D.IDDOCTOR        = ?
     WHERE P.IDPACIENTE = ?"
);
$stmt_info->bind_param("iii", $Idconsulta, $IdDoctor, $IdPaciente);
$stmt_info->execute();
$info = $stmt_info->get_result()->fetch_assoc();
$stmt_info->close();

$nombrePaciente = trim(($info['NOMBRES'] ?? '') . ' ' . ($info['APELLIDOS'] ?? ''));
$correoPaciente = $info['EMAIL'] ?? '';
$tipoConsulta   = $info['TIPO_CONSULTA'] ?? 'Consulta';
$doctorNombre   = $info['DOCTOR'] ?? '';

// Formatear fecha en español
$fechaObj  = DateTime::createFromFormat('Y-m-d', $fechafactura);
$diasES    = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$mesesES   = ['','enero','febrero','marzo','abril','mayo','junio',
              'julio','agosto','septiembre','octubre','noviembre','diciembre'];
$fechaBonita = $diasES[(int)$fechaObj->format('w')] . ', ' .
               (int)$fechaObj->format('j') . ' de ' .
               $mesesES[(int)$fechaObj->format('n')] . ' de ' .
               $fechaObj->format('Y');

// Resumen de la serie (si aplica)
$resumen = '';
if ($creadas > 1) {
    $resumen = "\\nSerie recurrente: {$creadas} citas creadas.";
}
if (!empty($saltadasDetalle)) {
    $lineas = [];
    foreach ($saltadasDetalle as $s) {
        $d = DateTime::createFromFormat('Y-m-d', $s['fecha']);
        $fstr = $d ? $d->format('m/d/Y') : $s['fecha'];
        $oc = str_replace(["'", '"', "\\", "\n", "\r"], ' ', $s['ocupa']);
        $lineas[] = $fstr . ($oc !== '' ? ' (ocupado por ' . $oc . ')' : ' (ocupado)');
    }
    $resumen .= "\\n\\nEstas fechas NO se crearon porque ya estaban ocupadas:\\n- " . implode("\\n- ", $lineas);
}

// ── Enviar correo ───────────────────────────────────────────────────
if (!$correoPaciente) {
    // Sin correo registrado: redirigir sin enviar
    echo "<script>alert('Cita creada. El paciente no tiene correo registrado.$resumen'); window.location.href = '../SCH_Calendar.php';</script>";
    exit;
}

$langPaciente = patient_lang($conexion, (int)$IdPaciente);
$emailCita = build_cita_email('programada', $langPaciente, [
    'nombre'       => $nombrePaciente,
    'fecha'        => $fechafactura,
    'hora'         => $timeIni,
    'horaFin'      => $timeFin,
    'tipoConsulta' => $tipoConsulta,
    'doctorNombre' => $doctorNombre,
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

    // Permite certificados SSL auto-firmados en hosting compartido
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

    echo "<script>
        alert('Cita creada y notificación enviada a $correoPaciente$resumen');
        window.location.href = '../SCH_Calendar.php';
    </script>";

} catch (Exception $e) {
    // La cita YA fue guardada; solo el correo falló
    error_log("PHPMailer error para $correoPaciente: " . $e->getMessage());
    echo "<script>
        alert('Cita creada correctamente.\\nNota: no se pudo enviar el correo de confirmación.$resumen');
        window.location.href = '../SCH_Calendar.php';
    </script>";
}

$conexion->close();
?>
