<?php
/**
 * demo_datos.php — Datos FICTICIOS para presentar la demo (SISTEMA, idempotente).
 *  - Crea 8 pacientes ficticios (cédulas 9900000001–9900000008) y citas para hoy y mañana usando los
 *    doctores, la enfermera y los servicios que YA existen en la instalación.
 *  - Incluye triajes, una orden de suero, exámenes (pendiente y realizado), un registro de atención y
 *    un historial de visitas previas para que la "Hoja del paciente" se vea completa.
 *  - "Eliminar datos de demo" borra SOLO los pacientes ficticios (por cédula 99000000xx) y todo lo ligado a ellos.
 * No usa datos reales de ninguna persona.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/auditoria.php");
require_once(__DIR__ . "/class/triaje.php");
require_once(__DIR__ . "/class/derivacion.php");
require_once(__DIR__ . "/class/enfermeria.php");
require_once(__DIR__ . "/class/registro_atencion.php");
require_once(__DIR__ . "/class/pacientes_util.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') { http_response_code(403); exit('Acceso restringido: sólo SISTEMA.'); }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$uid = (int)($_SESSION['iduser'] ?? 0);

$PAC = [
    ['María Fernanda','Torres Vega','9900000001','0990000001','1988-04-12','Fame','Av. Principal 123'],
    ['Carlos Andrés','Mejía Salazar','9900000002','0990000002','1975-09-30','Male','Calle 10 y Av. 4'],
    ['Lucía Alejandra','Paredes Cedeño','9900000003','0990000003','1992-01-21','Fame','Cdla. Los Ceibos Mz 5'],
    ['Jorge Luis','Intriago Moreira','9900000004','0990000004','1983-11-05','Male','Av. Kennedy 456'],
    ['Ana Belén','Zambrano Ruiz','9900000005','0990000005','1969-07-17','Fame','Urb. Vista Hermosa 22'],
    ['Pedro Pablo','Villamar Loor','9900000006','0990000006','2001-02-28','Male','Calle 8 y Av. 2'],
    ['Gabriela Estefanía','Macías Párraga','9900000007','0990000007','1996-06-09','Fame','Cdla. Alborada Mz 12'],
    ['Diego Armando','Cevallos Bravo','9900000008','0990000008','1958-12-14','Male','Av. Las Aguas 789'],
];
$msg = []; $accion = $_POST['accion'] ?? '';

function ids_demo($c) { $o = []; $q = $c->query("SELECT IDPACIENTE FROM AG_PACIENTE WHERE CEDULA BETWEEN '9900000001' AND '9900000008'"); if ($q) while ($r = $q->fetch_assoc()) $o[] = (int)$r['IDPACIENTE']; return $o; }
function usuarios_rol($c, $rol) { $o = []; $q = $c->query("SELECT U.IDADM_USUARIO FROM ADM_USUARIO U INNER JOIN ADM_ROL R ON R.IDADM_ROL=U.IDADM_ROL WHERE R.CARGO='".$c->real_escape_string($rol)."' AND U.ESTADO='A' ORDER BY U.IDADM_USUARIO"); if ($q) while ($r = $q->fetch_assoc()) $o[] = (int)$r['IDADM_USUARIO']; return $o; }
function servicio_id($c, $nombre) { $s = $c->prepare("SELECT IDTIPOCONSULTA FROM AG_TIPOCONSULTA WHERE LOWER(TRIM(NOMBRES))=LOWER(?) AND ESTADO='A' LIMIT 1"); $s->bind_param('s', $nombre); $s->execute(); $r = $s->get_result()->fetch_assoc(); $s->close(); return $r ? (int)$r['IDTIPOCONSULTA'] : 0; }
function nueva_cita($c, $idPac, $idTipo, $idDoc, $uid, $fecha, $hora, $estado) {
    $fin = date('H:i', strtotime($hora) + 3600); $ag = (int)($c->query("SELECT IDAGENCIA FROM ADM_AGENCIA WHERE ESTADO=1 ORDER BY IDAGENCIA LIMIT 1")->fetch_assoc()['IDAGENCIA'] ?? 0);
    $s = $c->prepare("INSERT INTO AG_CITA (IDPACIENTE, IDTIPOCONSULTA, IDDOCTOR, IDUSUARIO, FECHA_CITA, HORA_INICIO, HORA_FIN, IDAGENCIA, ESTADO_CITA, ESTADO, COMENTARIO) VALUES (?,?,?,?,?,?,?,NULLIF(?,0),?, 'A', 'DEMO')");
    $s->bind_param('iiiisssis', $idPac, $idTipo, $idDoc, $uid, $fecha, $hora, $fin, $ag, $estado); $s->execute(); $id = (int)$c->insert_id; $s->close(); return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'crear') {
    $docs = usuarios_rol($conexion, 'DOCTOR'); $enfs = usuarios_rol($conexion, 'ENFERMERA');
    $tCons = servicio_id($conexion, 'Consulta general'); if (!$tCons) { $q = $conexion->query("SELECT IDTIPOCONSULTA FROM AG_TIPOCONSULTA WHERE ESTADO='A' AND (ROL_ATIENDE IS NULL OR ROL_ATIENDE='') ORDER BY IDTIPOCONSULTA LIMIT 1"); $tCons = $q ? (int)($q->fetch_assoc()['IDTIPOCONSULTA'] ?? 0) : 0; }
    $tSue = servicio_id($conexion, 'Sueroterapia'); $tEco = servicio_id($conexion, 'Ecografía');
    if (!$docs) { $msg[] = ['danger', 'No hay doctores activos: crea al menos uno (Usuarios) y vuelve a ejecutar.']; }
    elseif (!$tCons) { $msg[] = ['danger', 'No hay un servicio de consulta activo (Tipos de consulta).']; }
    else {
        triaje_asegurar_tabla($conexion); derivacion_asegurar_tablas($conexion); enfermeria_asegurar_tablas($conexion); registro_asegurar_tablas($conexion);
        $ids = []; $nuevos = 0;
        foreach ($PAC as $i => $p) {
            $q = $conexion->prepare("SELECT IDPACIENTE FROM AG_PACIENTE WHERE CEDULA=? LIMIT 1"); $q->bind_param('s', $p[2]); $q->execute(); $f = $q->get_result()->fetch_assoc(); $q->close();
            if ($f) { $ids[$i] = (int)$f['IDPACIENTE']; continue; }
            $r = paciente_crear_minimo($conexion, $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], 'datos de demo');
            if ($r['ok']) { $ids[$i] = $r['id']; $nuevos++; $conexion->query("UPDATE AG_PACIENTE SET ADDRESS='".$conexion->real_escape_string($p[6])."' WHERE IDPACIENTE=".(int)$r['id']); }
        }
        $hoy = date('Y-m-d'); $man = date('Y-m-d', strtotime('+1 day')); $ayer = date('Y-m-d', strtotime('-1 day')); $sem = date('Y-m-d', strtotime('-8 days'));
        $d = function($k) use ($docs) { return $docs[$k % count($docs)]; };
        $enf = $enfs ? $enfs[0] : 0;
        // ¿ya hay citas de demo hoy? (evita duplicar al re-ejecutar)
        $yaHoy = (int)$conexion->query("SELECT COUNT(*) c FROM AG_CITA WHERE COMENTARIO='DEMO' AND FECHA_CITA='$hoy'")->fetch_assoc()['c'];
        if ($yaHoy === 0 && count($ids) === 8) {
            // Historial previo (visitas atendidas) del paciente 1 y 2: llenan la hoja de signos vitales
            $prev = [[0,$sem,'09:00',[68.0,165,'118/76',78,98,36.5,92],'Control de peso y hábitos alimentarios.'],[0,$ayer,'10:00',[67.2,165,'116/74',76,98,36.4,90],'Seguimiento: buena adherencia al plan.'],
                     [1,$ayer,'11:00',[88.5,176,'132/86',84,97,36.6,104],'Valoración inicial por hipertensión y sobrepeso.']];
            foreach ($prev as [$ip,$f,$hr,$v,$nota]) {
                $cid = nueva_cita($conexion, $ids[$ip], $tCons, $d($ip), $uid, $f, $hr, 'A');
                triaje_guardar($conexion, $cid, ['peso_kg'=>$v[0],'talla_cm'=>$v[1],'presion'=>$v[2],'fc'=>$v[3],'sat_o2'=>$v[4],'temperatura'=>$v[5],'glucosa'=>$v[6],'motivo'=>'Control'], $enf ?: $uid);
                $conexion->query("INSERT INTO AG_HISTORIAL (IDCITA, CONTENIDO_INFORME, PESO, TALLA, IMC) VALUES ($cid, '<p>".$conexion->real_escape_string($nota)."</p>', ".(float)$v[0].", ".(float)$v[1].", ".round($v[0]/pow($v[1]/100,2),1).")");
                registro_guardar($conexion, $cid, 'Consulta general', $f, '', [['indicacion'=>'Plan de alimentación personalizado','cantidad'=>'1','recomendacion'=>'Beber 2 litros de agua al día'],['indicacion'=>'Control en 15 días','cantidad'=>'','recomendacion'=>'Traer exámenes si los tiene']], $uid);
            }
            // Citas de HOY
            $c0 = nueva_cita($conexion, $ids[0], $tCons, $d(0), $uid, $hoy, '09:00', 'Confirmada');
            $c1 = nueva_cita($conexion, $ids[1], $tCons, $d(1), $uid, $hoy, '10:00', 'Pendiente');
            $c2 = nueva_cita($conexion, $ids[2], $tCons, $d(0), $uid, $hoy, '11:00', 'Confirmada');
            $c3 = nueva_cita($conexion, $ids[3], $tCons, $d(1), $uid, $hoy, '15:00', 'Pendiente');
            triaje_guardar($conexion, $c0, ['peso_kg'=>67.0,'talla_cm'=>165,'presion'=>'115/75','fc'=>75,'sat_o2'=>98,'temperatura'=>36.5,'glucosa'=>88,'motivo'=>'Control mensual, desea bajar de peso'], $enf ?: $uid);
            triaje_guardar($conexion, $c2, ['peso_kg'=>59.5,'talla_cm'=>160,'presion'=>'110/70','fc'=>82,'sat_o2'=>99,'temperatura'=>36.7,'glucosa'=>85,'motivo'=>'Cansancio y mareos ocasionales'], $enf ?: $uid);
            if ($tEco) { $ce = nueva_cita($conexion, $ids[4], $tEco, $d(0), $uid, $hoy, '12:00', 'Pendiente'); examen_crear($conexion, $ids[4], $ce, 'Ecografía', 'Ecografía abdominal', 'Ayuno de 6 horas', $d(0)); }
            examen_crear($conexion, $ids[1], $c1, 'Laboratorio', 'Biometría hemática y perfil lipídico', 'Ayuno de 8 horas', $d(1));
            $ex = examen_crear($conexion, $ids[0], $c0, 'Laboratorio', 'Glucosa en ayunas', '', $d(0)); if (!empty($ex['id'])) examen_cambiar_estado($conexion, $ex['id'], 'Realizado', 'Glucosa 88 mg/dL, dentro de lo normal', date('Y-m-d H:i:s'), $enf ?: $uid);
            // Sueroterapia (enfermera)
            if ($tSue && $enf) {
                $s1 = nueva_cita($conexion, $ids[5], $tSue, $enf, $uid, $hoy, '10:30', 'Confirmada');
                triaje_guardar($conexion, $s1, ['peso_kg'=>72,'talla_cm'=>178,'presion'=>'120/80','fc'=>70,'sat_o2'=>99,'temperatura'=>36.4,'glucosa'=>90,'motivo'=>'Hidratación y vitaminas'], $enf);
                derivacion_crear($conexion, $s1, ['tipo'=>'SUERO','motivo'=>'Sueroterapia agendada','items'=>[['producto'=>'Suero fisiológico 1000 ml','dosis'=>'1000 ml','via'=>'IV','indicaciones'=>'Pasar en 2 horas'],['producto'=>'Complejo B','dosis'=>'2 ml','via'=>'IV','indicaciones'=>'Diluido en el suero']]], $enf);
                $s2 = nueva_cita($conexion, $ids[6], $tSue, $enf, $uid, $hoy, '14:00', 'Pendiente');
            }
            // Mañana
            nueva_cita($conexion, $ids[7], $tCons, $d(1), $uid, $man, '09:30', 'Pendiente');
            nueva_cita($conexion, $ids[3], $tCons, $d(0), $uid, $man, '11:30', 'Confirmada');
            $msg[] = ['success', "Datos de demo creados: $nuevos paciente(s) nuevos, citas de hoy y mañana, triajes, exámenes, sueroterapia y visitas previas."];
        } elseif ($yaHoy > 0) { $msg[] = ['info', "Ya existen citas de demo para hoy; no se duplicaron. Pacientes nuevos: $nuevos."]; }
        if (!$enfs) $msg[] = ['warning', 'No hay una enfermera activa: se omitió la parte de sueroterapia.'];
        if (!$tSue) $msg[] = ['warning', 'No existe el servicio «Sueroterapia»: ejecuta primero «Preparar L\'Chaim» (setup_lchaim.php).'];
        auditar($conexion, 'Configuración', 'crear', 'CONFIG', null, 'Generó datos de demo');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'eliminar') {
    if (trim($_POST['frase'] ?? '') !== 'ELIMINAR DEMO') { $msg[] = ['danger', 'Frase de confirmación incorrecta.']; }
    else {
        $ids = ids_demo($conexion); $n = count($ids);
        if ($n) {
            $in = implode(',', $ids);
            $conexion->query("SET FOREIGN_KEY_CHECKS=0");
            $citas = []; $q = $conexion->query("SELECT IDCITA FROM AG_CITA WHERE IDPACIENTE IN ($in)"); if ($q) while ($r = $q->fetch_assoc()) $citas[] = (int)$r['IDCITA'];
            $ci = $citas ? implode(',', $citas) : '0';
            $ders = []; $q = @$conexion->query("SELECT IDDERIVACION FROM derivacion WHERE IDPACIENTE IN ($in)"); if ($q) while ($r = $q->fetch_assoc()) $ders[] = (int)$r['IDDERIVACION'];
            if ($ders) @$conexion->query("DELETE FROM suero_orden WHERE IDDERIVACION IN (".implode(',', $ders).")");
            foreach (["DELETE FROM atencion_registro_item WHERE IDREGISTRO IN (SELECT IDREGISTRO FROM atencion_registro WHERE IDPACIENTE IN ($in))",
                      "DELETE FROM atencion_registro WHERE IDPACIENTE IN ($in)", "DELETE FROM derivacion WHERE IDPACIENTE IN ($in)",
                      "DELETE FROM triaje WHERE IDPACIENTE IN ($in)", "DELETE FROM examen_orden WHERE IDPACIENTE IN ($in)",
                      "DELETE FROM nota_enfermeria WHERE IDPACIENTE IN ($in)", "DELETE FROM AG_HISTORIAL WHERE IDCITA IN ($ci)",
                      "DELETE FROM AG_CITA WHERE IDPACIENTE IN ($in)", "DELETE FROM AG_PACIENTE WHERE IDPACIENTE IN ($in)"] as $sql) { @$conexion->query($sql); }
            $conexion->query("SET FOREIGN_KEY_CHECKS=1");
            auditar($conexion, 'Configuración', 'eliminar', 'CONFIG', null, "Eliminó los datos de demo ($n pacientes ficticios)");
        }
        $msg[] = ['success', "Datos de demo eliminados ($n paciente(s) ficticios y sus registros)."];
    }
}
$hayDemo = count(ids_demo($conexion));
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Datos de demo</title><meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:760px;">
<h4>Datos de demo (ficticios)</h4>
<?php foreach ($msg as $m): ?><div class="alert alert-<?php echo h($m[0]); ?>"><?php echo h($m[1]); ?></div><?php endforeach; ?>
<p class="text-muted">Crea 8 pacientes ficticios y citas para hoy y mañana con los doctores y la enfermera que ya tienes, más triajes, sueroterapia, exámenes, un registro de atención y visitas previas (para la «Hoja del paciente»). Pacientes de demo actuales: <b><?php echo (int)$hayDemo; ?></b>.</p>
<form method="POST" class="mb-3"><input type="hidden" name="accion" value="crear"><button class="btn btn-primary" type="submit">Crear / completar datos de demo</button> <a class="btn btn-link" href="SCH_Calendar.php">Ir al calendario</a></form>
<form method="POST" class="card card-body border-danger" onsubmit="return confirm('¿Eliminar los pacientes ficticios y sus registros?');">
  <input type="hidden" name="accion" value="eliminar">
  <label class="form-label text-danger">Eliminar datos de demo — escribe <code>ELIMINAR DEMO</code>. Solo borra pacientes con cédula 9900000001–9900000008 y lo ligado a ellos.</label>
  <div class="d-flex gap-2"><input name="frase" class="form-control" autocomplete="off"><button class="btn btn-outline-danger" type="submit">Eliminar</button></div>
</form></div></body></html>
