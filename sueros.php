<?php
/**
 * sueros.php — Mesa de trabajo de enfermería para sueroterapia.
 *  1. Citas de sueroterapia de hoy (asignadas a enfermería): estado de triaje y alta de órdenes.
 *  2. Órdenes pendientes (de los doctores o creadas aquí): registrar aplicación (hora, lote, observaciones,
 *     reacciones) o suspender, y escribir notas de enfermería (evolución).
 *  3. Historial del paciente: sueros que ha recibido / le tocan, exámenes pendientes y realizados, notas.
 */
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/permisos.php");
require_once(__DIR__ . "/class/derivacion.php");
require_once(__DIR__ . "/class/enfermeria.php");
require_once(__DIR__ . "/class/guia_rapida.php");
require_once(__DIR__ . "/class/mailer.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
requerir('sueros.aplicar');
derivacion_asegurar_tablas($conexion);
enfermeria_asegurar_tablas($conexion);

$en = (current_lang() === 'en');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nz($v){ if ($v === null || $v === '') return ''; $v = (string)$v; return strpos($v, '.') !== false ? rtrim(rtrim($v, '0'), '.') : $v; }
$idUser = (int)($_SESSION['iduser'] ?? 0);
$modExamenes = modulo_activo('examenes');
$verExamenes = $modExamenes && puede('examenes.registrar');
$msg = $_SESSION['sueros_msg'] ?? null; unset($_SESSION['sueros_msg']);
$ok_ = fn($t) => $_SESSION['sueros_msg'] = ['success', $t];
$er_ = fn($t) => $_SESSION['sueros_msg'] = ['danger', $t];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = $_POST['accion'] ?? 'registrar';
    if ($acc === 'registrar') {
        $okR = suero_registrar($conexion, (int)($_POST['idOrden'] ?? 0),
            ($_POST['resultado'] ?? '') === 'Suspendido' ? 'Suspendido' : 'Aplicado',
            trim($_POST['lote'] ?? ''), trim($_POST['observaciones'] ?? ''), trim($_POST['reacciones'] ?? ''), $_POST['fecha_hora'] ?? '', $idUser);
        $okR ? $ok_($en ? 'Saved.' : 'Registro guardado.') : $er_($en ? 'Could not save (already recorded?).' : 'No se pudo guardar (¿ya estaba registrada?).');
    } elseif ($acc === 'nota') {
        $okN = nota_enfermeria_agregar($conexion, (int)($_POST['idPaciente'] ?? 0), (int)($_POST['idOrden'] ?? 0), (int)($_POST['idCita'] ?? 0), $_POST['nota'] ?? '', $idUser);
        $okN ? $ok_($en ? 'Nursing note saved.' : 'Nota de enfermería guardada.') : $er_($en ? 'Write the note first.' : 'Escribe la nota.');
    } elseif ($acc === 'orden') {
        $r = derivacion_crear($conexion, (int)($_POST['idCita'] ?? 0), [
            'tipo' => 'SUERO', 'motivo' => trim($_POST['motivo'] ?? '') ?: ($en ? 'Scheduled IV therapy' : 'Sueroterapia agendada'),
            'items' => [['producto' => $_POST['producto'] ?? '', 'dosis' => $_POST['dosis'] ?? '', 'via' => $_POST['via'] ?? '', 'indicaciones' => $_POST['indicaciones'] ?? '']],
        ], $idUser);
        $r['ok'] ? $ok_($en ? 'Order added.' : 'Orden agregada.') : $er_($en ? 'Could not add the order: enter the product.' : 'No se pudo agregar: escribe el suero.');
    }
    header('Location: sueros.php'); exit();
}

// 1) Citas de sueroterapia de hoy (asignadas a un usuario con rol ENFERMERA)
$citasHoy = [];
$q = $conexion->query("SELECT A.IDCITA, A.IDPACIENTE, A.HORA_INICIO, A.ESTADO_CITA, TC.NOMBRES AS SERVICIO,
                              CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA,
                              CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS ESPECIALISTA, T.IDTRIAJE,
                              (SELECT COUNT(*) FROM derivacion D WHERE D.IDCITA_ORIGEN = A.IDCITA AND D.TIPO='SUERO') AS N_ORDENES
                         FROM AG_CITA A
                         INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = A.IDPACIENTE
                         INNER JOIN ADM_USUARIO U ON U.IDADM_USUARIO = A.IDDOCTOR
                         INNER JOIN ADM_ROL R ON R.IDADM_ROL = U.IDADM_ROL AND R.CARGO = 'ENFERMERA'
                         LEFT JOIN AG_TIPOCONSULTA TC ON TC.IDTIPOCONSULTA = A.IDTIPOCONSULTA
                         LEFT JOIN triaje T ON T.IDCITA = A.IDCITA
                        WHERE A.ESTADO='A' AND A.FECHA_CITA = CURDATE()
                          AND (A.ESTADO_CITA IS NULL OR A.ESTADO_CITA NOT IN ('Cancelada','Cancelado','Cancelación Tardía','Cancelado por Profesional','No Asistió'))
                        ORDER BY A.HORA_INICIO");
if ($q) while ($r = $q->fetch_assoc()) $citasHoy[] = $r;

// 2) Órdenes pendientes
$pend = [];
$q = $conexion->query("SELECT S.IDORDEN, S.PRODUCTO, S.DOSIS, S.VIA, S.INDICACIONES, D.IDDERIVACION, D.IDPACIENTE, D.MOTIVO, D.FECHA_CREACION, D.IDCITA_ORIGEN,
                              CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA,
                              CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS DERIVA,
                              T.PESO_KG, T.PRESION, T.FC, T.SAT_O2, T.TEMPERATURA, T.GLUCOSA
                         FROM suero_orden S
                         INNER JOIN derivacion D ON D.IDDERIVACION = S.IDDERIVACION
                         INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = D.IDPACIENTE
                         LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = D.IDUSUARIO_ORIGEN
                         LEFT JOIN triaje T ON T.IDCITA = D.IDCITA_ORIGEN
                        WHERE S.ESTADO = 'Pendiente' AND D.TIPO = 'SUERO'
                        ORDER BY D.FECHA_CREACION, S.IDORDEN");
if ($q) while ($r = $q->fetch_assoc()) $pend[] = $r;

$hechos = [];
$q = $conexion->query("SELECT S.PRODUCTO, S.DOSIS, S.ESTADO, S.FECHA_APLICACION, S.LOTE, S.REACCIONES,
                              CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE,
                              CONCAT(U.NOMBRES,' ',U.APELLIDOS) AS APLICA
                         FROM suero_orden S
                         INNER JOIN derivacion D ON D.IDDERIVACION = S.IDDERIVACION
                         INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = D.IDPACIENTE
                         LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = S.IDUSUARIO_APLICA
                        WHERE S.ESTADO <> 'Pendiente' AND DATE(S.FECHA_APLICACION) = CURDATE()
                        ORDER BY S.FECHA_APLICACION DESC");
if ($q) while ($r = $q->fetch_assoc()) $hechos[] = $r;
$ahora = date('Y-m-d\TH:i');

/** Panel desplegable con el historial del paciente (sueros, exámenes, notas). */
function panel_historial($conexion, $idPac, $en, $verExamenes, $ahora) {
    $hx = historial_enfermeria($conexion, $idPac);
    echo '<details class="mt-2"><summary class="small fw-semibold" style="cursor:pointer"><i class="bi bi-clock-history"></i> '.($en?'Patient history':'Historial del paciente')
        .' <span class="text-muted">('.count($hx['sueros']).' '.($en?'IV':'sueros').' · '.count($hx['examenes']).' '.($en?'exams':'exámenes').' · '.count($hx['notas']).' '.($en?'notes':'notas').')</span></summary><div class="mt-2 small">';
    // Sueros
    echo '<div class="fw-semibold mb-1">'.($en?'IV therapy (received / due)':'Sueros (recibidos / le tocan)').'</div>';
    if (!$hx['sueros']) echo '<div class="text-muted mb-2">'.($en?'No IV orders yet.':'Sin órdenes de suero.').'</div>';
    else { echo '<ul class="list-unstyled mb-2">';
        foreach ($hx['sueros'] as $s) {
            $cls = $s['ESTADO']==='Pendiente' ? 'warning text-dark' : ($s['ESTADO']==='Aplicado' ? 'success' : 'secondary');
            echo '<li><span class="badge bg-'.$cls.'">'.h($s['ESTADO']==='Pendiente' ? ($en?'Due':'Le toca') : $s['ESTADO']).'</span> <b>'.h($s['PRODUCTO']).'</b>'.($s['DOSIS']?' · '.h($s['DOSIS']):'').($s['VIA']?' · '.h($s['VIA']):'')
                .' <span class="text-muted">— '.($s['FECHA_APLICACION'] ? h(date('m/d/Y H:i', strtotime($s['FECHA_APLICACION']))).($s['LOTE'] ? ', '.($en?'lot':'lote').' '.h($s['LOTE']) : '') : h(date('m/d/Y', strtotime($s['FECHA_CREACION']))).($s['INDICA']?' · '.h($s['INDICA']):''))
                .'</span>'.($s['REACCIONES'] ? ' <span class="text-danger">'.h($s['REACCIONES']).'</span>' : '').'</li>';
        } echo '</ul>'; }
    // Exámenes
    if ($verExamenes) {
        echo '<div class="fw-semibold mb-1">'.($en?'Exams':'Exámenes').'</div>';
        if (!$hx['examenes']) echo '<div class="text-muted mb-2">'.($en?'No exams ordered.':'Sin exámenes solicitados.').'</div>';
        else { echo '<ul class="list-unstyled mb-2">';
            foreach ($hx['examenes'] as $e) {
                $cls = $e['ESTADO']==='Pendiente' ? 'warning text-dark' : ($e['ESTADO']==='Realizado' ? 'success' : 'secondary');
                echo '<li class="mb-1"><span class="badge bg-'.$cls.'">'.h($e['ESTADO']).'</span> <b>'.h($e['EXAMEN']).'</b> <span class="text-muted">('.h($e['TIPO']).', '.($en?'requested':'solicitado').' '.h(date('m/d/Y', strtotime($e['FECHA_SOLICITUD']))).($e['SOLICITA']?' · '.h($e['SOLICITA']):'').')</span>'
                    .($e['INDICACION'] ? '<div class="text-muted">'.h($e['INDICACION']).'</div>' : '')
                    .($e['ESTADO']!=='Pendiente' && $e['RESULTADO'] ? '<div>'.h($e['RESULTADO']).'</div>' : '');
                if ($e['ESTADO']==='Pendiente') echo ' <button type="button" class="btn btn-sm btn-outline-success py-0" onclick="examenRealizado('.(int)$e['IDEXAMEN'].')"><i class="bi bi-check2"></i> '.($en?'Mark done':'Marcar realizado').'</button>';
                echo '</li>';
            } echo '</ul>'; }
    }
    // Notas
    echo '<div class="fw-semibold mb-1">'.($en?'Nursing notes':'Notas de enfermería').'</div>';
    if (!$hx['notas']) echo '<div class="text-muted">'.($en?'No notes yet.':'Sin notas.').'</div>';
    else foreach ($hx['notas'] as $n) echo '<div class="border-start ps-2 mb-1"><span class="text-muted">'.h(date('m/d/Y H:i', strtotime($n['FECHA']))).' · '.h($n['AUTOR']).'</span><br>'.nl2br(h($n['NOTA'])).'</div>';
    echo '</div></details>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $en?'IV therapy':'Sueroterapia'; ?></title>
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
                <div class="page-title-icon"><i class="pe-7s-drop icon-gradient bg-happy-itmeo"></i></div>
                <div><?php echo $en?'IV therapy':'Sueroterapia'; ?>
                    <div class="page-title-subheading"><?php echo $en?'Today’s IV appointments, pending orders, nursing notes and patient history.':'Citas de sueroterapia de hoy, órdenes pendientes, notas de enfermería e historial del paciente.'; ?></div>
                </div>
            </div></div></div>

            <?php if ($msg): ?><div class="alert alert-<?php echo h($msg[0]); ?>"><?php echo h($msg[1]); ?></div><?php endif; ?>
            <?php if (modulo_activo('atencion_rapida')) echo guia_rapida_html($en, $_SESSION['rol'] ?? '', 4); ?>

            <h6><?php echo $en?'IV appointments today':'Citas de sueroterapia de hoy'; ?> <span class="badge bg-secondary"><?php echo count($citasHoy); ?></span></h6>
            <?php if (!$citasHoy): ?><div class="card shadow-sm mb-3"><div class="card-body text-muted small"><?php echo $en?'No IV appointments assigned to nursing today. Schedule one in the calendar with a service handled by a nurse (Visit types → “Handled by”).':'No hay citas de sueroterapia asignadas a enfermería hoy. Agéndalas en el calendario con un servicio que atiende la enfermera (Tipos de consulta → “Lo atiende”).'; ?></div></div><?php endif; ?>
            <?php foreach ($citasHoy as $c): ?>
            <div class="card shadow-sm mb-3 border-info"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start">
                    <div><div class="fs-6 fw-semibold"><?php echo h(substr($c['HORA_INICIO'],0,5)); ?> · <?php echo h($c['PACIENTE']); ?> <span class="small text-muted"><?php echo h($c['CEDULA']); ?></span></div>
                        <div class="small text-muted"><?php echo h($c['SERVICIO']); ?> · <?php echo h($c['ESPECIALISTA']); ?> · <?php echo h(estado_label($c['ESTADO_CITA'])); ?></div></div>
                    <div>
                        <?php if ($c['IDTRIAJE'] !== null): ?><span class="badge bg-info text-dark"><?php echo $en?'Triaged':'Triada'; ?></span>
                        <?php else: ?><span class="badge bg-warning text-dark"><?php echo $en?'Needs triage':'Por triar'; ?></span><?php endif; ?>
                        <?php if (puede('triaje.registrar')): ?><a class="btn btn-sm btn-outline-primary py-0 ms-1" href="triaje.php?idCita=<?php echo (int)$c['IDCITA']; ?>"><?php echo $c['IDTRIAJE'] !== null ? ($en?'Edit triage':'Editar triaje') : ($en?'Record triage':'Registrar triaje'); ?></a><?php endif; ?>
                        <span class="badge bg-light text-dark border ms-1"><?php echo (int)$c['N_ORDENES']; ?> <?php echo $en?'order(s)':'orden(es)'; ?></span>
                    </div>
                </div>
                <form method="POST" class="row g-2 align-items-end mt-1">
                    <input type="hidden" name="accion" value="orden"><input type="hidden" name="idCita" value="<?php echo (int)$c['IDCITA']; ?>">
                    <div class="col-md-4"><label class="form-label small mb-0"><?php echo $en?'IV / product':'Suero / producto'; ?></label><input name="producto" class="form-control form-control-sm" maxlength="150" required></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?php echo $en?'Dose':'Dosis'; ?></label><input name="dosis" class="form-control form-control-sm" maxlength="80"></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?php echo $en?'Route':'Vía'; ?></label><input name="via" class="form-control form-control-sm" maxlength="40" placeholder="IV"></div>
                    <div class="col-md-3"><label class="form-label small mb-0"><?php echo $en?'Instructions':'Indicaciones'; ?></label><input name="indicaciones" class="form-control form-control-sm"></div>
                    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100" type="submit"><i class="bi bi-plus"></i></button></div>
                </form>
                <?php panel_historial($conexion, (int)$c['IDPACIENTE'], $en, $verExamenes, $ahora); ?>
            </div></div>
            <?php endforeach; ?>

            <h6 class="mt-4"><?php echo $en?'Pending orders':'Órdenes pendientes'; ?> <span class="badge bg-secondary"><?php echo count($pend); ?></span></h6>
            <?php if (!$pend): ?><div class="card shadow-sm mb-3"><div class="card-body text-center text-muted py-4"><?php echo $en?'No pending orders.':'No hay órdenes pendientes.'; ?></div></div><?php endif; ?>
            <?php foreach ($pend as $o): ?>
            <div class="card shadow-sm mb-3"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between">
                    <div><div class="fs-5 fw-semibold"><?php echo h($o['PACIENTE']); ?> <span class="small text-muted"><?php echo h($o['CEDULA']); ?></span></div>
                        <div class="small text-muted"><?php echo $en?'Ordered by':'Indicado por'; ?> <b><?php echo h($o['DERIVA']); ?></b> · <?php echo h(date('m/d/Y H:i', strtotime($o['FECHA_CREACION']))); ?></div></div>
                    <?php if ($o['PRESION'] !== null || $o['PESO_KG'] !== null): ?>
                    <div class="small text-end text-muted"><?php echo $en?'Triage':'Triaje'; ?>:
                        <?php echo $o['PESO_KG']!==null ? h(nz($o['PESO_KG'])).' kg · ' : ''; ?>PA <?php echo h($o['PRESION'] ?: '—'); ?> · FC <?php echo h($o['FC'] ?? '—'); ?> · SatO₂ <?php echo h($o['SAT_O2'] ?? '—'); ?> · T° <?php echo h($o['TEMPERATURA']!==null ? nz($o['TEMPERATURA']) : '—'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="alert alert-info py-2 my-2"><b><?php echo h($o['PRODUCTO']); ?></b>
                    <?php echo $o['DOSIS'] ? ' · '.h($o['DOSIS']) : ''; ?><?php echo $o['VIA'] ? ' · '.h($o['VIA']) : ''; ?>
                    <?php if (trim((string)$o['INDICACIONES']) !== ''): ?><div class="small"><?php echo nl2br(h($o['INDICACIONES'])); ?></div><?php endif; ?>
                    <div class="small text-muted mt-1"><?php echo $en?'Reason':'Motivo'; ?>: <?php echo h($o['MOTIVO']); ?></div></div>
                <form method="POST" class="row g-2 align-items-end">
                    <input type="hidden" name="accion" value="registrar"><input type="hidden" name="idOrden" value="<?php echo (int)$o['IDORDEN']; ?>">
                    <div class="col-md-3"><label class="form-label small mb-0"><?php echo $en?'Date/time':'Fecha y hora'; ?></label><input type="datetime-local" name="fecha_hora" class="form-control form-control-sm" value="<?php echo h($ahora); ?>"></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?php echo $en?'Lot':'Lote'; ?></label><input name="lote" class="form-control form-control-sm" maxlength="60"></div>
                    <div class="col-md-3"><label class="form-label small mb-0"><?php echo $en?'Observations':'Observaciones'; ?></label><input name="observaciones" class="form-control form-control-sm"></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?php echo $en?'Reactions':'Reacciones'; ?></label><input name="reacciones" class="form-control form-control-sm" placeholder="<?php echo $en?'None':'Ninguna'; ?>"></div>
                    <div class="col-md-2 d-flex gap-1">
                        <button class="btn btn-success btn-sm flex-fill" name="resultado" value="Aplicado" type="submit"><i class="bi bi-check2-circle"></i> <?php echo $en?'Applied':'Aplicado'; ?></button>
                        <button class="btn btn-outline-danger btn-sm" name="resultado" value="Suspendido" type="submit" title="<?php echo $en?'Suspend':'Suspender'; ?>" onclick="return confirm('<?php echo $en?'Suspend this order?':'¿Suspender esta orden?'; ?>');"><i class="bi bi-x-circle"></i></button>
                    </div>
                </form>
                <form method="POST" class="row g-2 align-items-end mt-1">
                    <input type="hidden" name="accion" value="nota"><input type="hidden" name="idPaciente" value="<?php echo (int)$o['IDPACIENTE']; ?>"><input type="hidden" name="idOrden" value="<?php echo (int)$o['IDORDEN']; ?>"><input type="hidden" name="idCita" value="<?php echo (int)$o['IDCITA_ORIGEN']; ?>">
                    <div class="col-md-10"><label class="form-label small mb-0"><i class="bi bi-journal-medical"></i> <?php echo $en?'Nursing note (evolution)':'Nota de enfermería (evolución)'; ?></label><textarea name="nota" rows="2" class="form-control form-control-sm" placeholder="<?php echo $en?'How is the patient doing, tolerance, vitals during infusion…':'Cómo evoluciona el paciente, tolerancia, signos durante la infusión…'; ?>"></textarea></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-save"></i> <?php echo $en?'Save note':'Guardar nota'; ?></button></div>
                </form>
                <?php panel_historial($conexion, (int)$o['IDPACIENTE'], $en, $verExamenes, $ahora); ?>
            </div></div>
            <?php endforeach; ?>

            <?php if ($hechos): ?>
            <h6 class="mt-4"><?php echo $en?'Recorded today':'Registradas hoy'; ?> <span class="badge bg-secondary"><?php echo count($hechos); ?></span></h6>
            <div class="card shadow-sm mb-4"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th><?php echo $en?'Time':'Hora'; ?></th><th><?php echo $en?'Patient':'Paciente'; ?></th><th><?php echo $en?'Product':'Suero'; ?></th><th><?php echo $en?'Lot':'Lote'; ?></th><th><?php echo $en?'Status':'Estado'; ?></th><th><?php echo $en?'By':'Por'; ?></th></tr></thead><tbody>
                <?php foreach ($hechos as $x): ?><tr>
                    <td><?php echo h(date('H:i', strtotime($x['FECHA_APLICACION']))); ?></td><td><?php echo h($x['PACIENTE']); ?></td>
                    <td><?php echo h($x['PRODUCTO'].($x['DOSIS']?' · '.$x['DOSIS']:'')); ?></td><td><?php echo h($x['LOTE']); ?></td>
                    <td><span class="badge bg-<?php echo $x['ESTADO']==='Aplicado'?'success':'secondary'; ?>"><?php echo h($x['ESTADO']); ?></span><?php echo $x['REACCIONES'] ? ' <span class="small text-danger">'.h($x['REACCIONES']).'</span>' : ''; ?></td>
                    <td><?php echo h($x['APLICA']); ?></td></tr><?php endforeach; ?>
                </tbody></table></div></div>
            <?php endif; ?>

            <?php $nAp = 0; $nSu = 0; foreach ($hechos as $x) { if ($x['ESTADO'] === 'Aplicado') $nAp++; else $nSu++; } ?>
            <footer class="mt-5 pt-3 pb-5 border-top d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
                <div><i class="bi bi-droplet-half"></i> <b><?php echo h(mail_brand()); ?></b> · <?php echo $en?'IV therapy':'Sueroterapia'; ?> · <?php echo h(date('m/d/Y')); ?></div>
                <div>
                    <span class="badge bg-success"><?php echo $nAp; ?> <?php echo $en?'applied today':'aplicados hoy'; ?></span>
                    <?php if ($nSu): ?><span class="badge bg-secondary"><?php echo $nSu; ?> <?php echo $en?'suspended':'suspendidos'; ?></span><?php endif; ?>
                    <span class="badge bg-warning text-dark"><?php echo count($pend); ?> <?php echo $en?'pending':'pendientes'; ?></span>
                </div>
            </footer>

        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function examenRealizado(id) {
    var en = <?php echo json_encode($en); ?>;
    var res = prompt(en ? 'Result / observation (optional):' : 'Resultado u observación (opcional):', '');
    if (res === null) return;
    var fd = new FormData(); fd.append('accion', 'realizar'); fd.append('idExamen', id); fd.append('resultado', res);
    fetch('examen_accion.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        if (j.ok) location.reload(); else alert(en ? 'Could not update the exam.' : 'No se pudo actualizar el examen.');
    }).catch(function(){ alert(en ? 'Connection error.' : 'Error de conexión.'); });
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>
