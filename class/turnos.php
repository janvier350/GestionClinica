<?php
/**
 * class/turnos.php — Atención rápida (sin agenda): ingreso → sala de espera → atención → reporte → cobro → fin.
 *
 * Un "turno" es un paciente que llegó y espera un servicio. Cuando un profesional lo toma ("Atender") se crea
 * la cita del día (AG_CITA) a su nombre y se reutiliza todo lo existente: triaje, atención, registro, sueros,
 * exámenes. El cobro crea una factura + pago (facturas / factura_pagos), así aparece en Cuentas por Cobrar.
 *
 *  ESTADO: [Triaje →] Espera → Atencion → Atendido    (o Cancelado)   ('Triaje' = enviado primero a la enfermera)
 *  COBRO : Pendiente → Cobrado                      ("Por cobrar" = Atendido con cobro Pendiente)
 */
require_once(__DIR__ . '/auditoria.php');
require_once(__DIR__ . '/pacientes_util.php');

function turnos_asegurar_tablas($c) {
    $ok = (bool)@$c->query("CREATE TABLE IF NOT EXISTS turno (
        IDTURNO INT AUTO_INCREMENT PRIMARY KEY,
        IDPACIENTE INT NOT NULL,
        IDTIPOCONSULTA INT NOT NULL,
        MOTIVO VARCHAR(255) NULL,
        ESTADO VARCHAR(12) NOT NULL DEFAULT 'Espera',
        FECHA_INGRESO DATETIME DEFAULT CURRENT_TIMESTAMP,
        IDUSUARIO_INGRESA INT NULL,
        IDCITA INT NULL,
        IDUSUARIO_ATIENDE INT NULL,
        FECHA_INICIO DATETIME NULL,
        FECHA_FIN DATETIME NULL,
        MONTO DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        COBRO VARCHAR(10) NOT NULL DEFAULT 'Pendiente',
        METODO VARCHAR(30) NULL,
        IDFACTURA INT NULL,
        FECHA_COBRO DATETIME NULL,
        IDUSUARIO_PREF INT NULL,
        DERIVADOR VARCHAR(120) NULL,
        ASEGURADORA VARCHAR(120) NULL,
        EMPRESA VARCHAR(120) NULL,
        INDEX idx_estado (ESTADO, FECHA_INGRESO),
        INDEX idx_pac (IDPACIENTE),
        INDEX idx_cita (IDCITA)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['IDUSUARIO_PREF' => 'INT NULL', 'DERIVADOR' => 'VARCHAR(120) NULL', 'ASEGURADORA' => 'VARCHAR(120) NULL', 'EMPRESA' => 'VARCHAR(120) NULL'] as $col => $def) {
        $r = @$c->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='turno' AND COLUMN_NAME='$col'");
        if ($r && (int)$r->fetch_assoc()['c'] === 0) @$c->query("ALTER TABLE turno ADD COLUMN $col $def");
    }
    @$c->query("CREATE TABLE IF NOT EXISTS turno_servicio (
        ID INT AUTO_INCREMENT PRIMARY KEY, IDTURNO INT NOT NULL, IDTIPOCONSULTA INT NOT NULL,
        PRECIO DECIMAL(10,2) NOT NULL DEFAULT 0.00, INDEX idx_turno (IDTURNO)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Valor del servicio (AG_TIPOCONSULTA.PRECIO) y quién lo atiende (ROL_ATIENDE)
    foreach (['PRECIO' => 'DECIMAL(10,2) NULL', 'ROL_ATIENDE' => 'VARCHAR(20) NULL'] as $col => $def) {
        $r = @$c->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='AG_TIPOCONSULTA' AND COLUMN_NAME='$col'");
        if ($r && (int)$r->fetch_assoc()['c'] === 0) @$c->query("ALTER TABLE AG_TIPOCONSULTA ADD COLUMN $col $def");
    }
    // Facturación (mismo esquema que migrar_facturas_schema.php) por si aún no existe
    @$c->query("CREATE TABLE IF NOT EXISTS facturas (
        id INT AUTO_INCREMENT PRIMARY KEY, billing_number VARCHAR(40) NULL, IDPACIENTE INT NULL, kalix_client_id VARCHAR(40) NULL,
        client_name VARCHAR(200) NULL, IDCITA INT NULL, kalix_appointment_id VARCHAR(40) NULL, fecha DATE NULL, fecha_vencimiento DATE NULL,
        status VARCHAR(40) NULL, descripcion VARCHAR(500) NULL, total DECIMAL(10,2) NOT NULL DEFAULT 0.00, contract DECIMAL(10,2) NULL,
        pagado DECIMAL(10,2) NOT NULL DEFAULT 0.00, impuesto DECIMAL(10,2) NULL, saldo DECIMAL(10,2) NOT NULL DEFAULT 0.00, notas TEXT NULL,
        origen VARCHAR(20) NOT NULL DEFAULT 'app', estado TINYINT(1) NOT NULL DEFAULT 1, creado_por INT NULL,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_billing (billing_number), INDEX idx_paciente (IDPACIENTE),
        INDEX idx_saldo (saldo), INDEX idx_fecha (fecha), INDEX idx_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$c->query("CREATE TABLE IF NOT EXISTS factura_detalle (
        id INT AUTO_INCREMENT PRIMARY KEY, id_factura INT NOT NULL, billing_number VARCHAR(40) NULL, id_bill_item INT NULL, code VARCHAR(20) NULL,
        description VARCHAR(500) NULL, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00, quantity INT NOT NULL DEFAULT 1,
        importe DECIMAL(10,2) NOT NULL DEFAULT 0.00, INDEX idx_factura (id_factura), INDEX idx_billing (billing_number)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$c->query("CREATE TABLE IF NOT EXISTS factura_pagos (
        id INT AUTO_INCREMENT PRIMARY KEY, id_factura INT NOT NULL, fecha DATE NULL, monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        metodo VARCHAR(30) NULL, referencia VARCHAR(100) NULL, registrado_por INT NULL,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_factura (id_factura)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $ok;
}

/** Valida una cédula ecuatoriana de 10 dígitos (provincia + dígito verificador módulo 10). */
function cedula_ec_valida($ced) {
    $ced = preg_replace('/\D/', '', (string)$ced);
    if (strlen($ced) !== 10) return false;
    $prov = (int)substr($ced, 0, 2);
    if ($prov < 1 || ($prov > 24 && $prov !== 30)) return false;
    if ((int)$ced[2] > 5) return false;
    $suma = 0;
    for ($i = 0; $i < 9; $i++) {
        $d = (int)$ced[$i] * (($i % 2 === 0) ? 2 : 1);
        if ($d > 9) $d -= 9;
        $suma += $d;
    }
    $ver = (10 - ($suma % 10)) % 10;
    return $ver === (int)$ced[9];
}

/** Busca un paciente activo por cédula. Devuelve fila o null. */
function turno_buscar_cedula($c, $cedula) {
    $cedula = trim((string)$cedula);
    if ($cedula === '') return null;
    $st = $c->prepare("SELECT IDPACIENTE, NOMBRES, APELLIDOS, TELEFONO, CEDULA FROM AG_PACIENTE WHERE ESTADO='A' AND TRIM(CEDULA) = ? ORDER BY IDPACIENTE LIMIT 1");
    $st->bind_param('s', $cedula); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}

/** Roles que atienden un servicio: ENFERMERA si el servicio es de enfermería; en otro caso DOCTOR. */
function turno_rol_servicio($rolAtiende) { return strtoupper(trim((string)$rolAtiende)) === 'ENFERMERA' ? 'ENFERMERA' : 'DOCTOR'; }

/**
 * Registra el ingreso de un paciente.
 * $d: cedula, nombres, apellidos, telefono, idPaciente?, servicios[] (ids), idEspecialista?, destino ('atencion'|'triaje'),
 *     motivo, derivador, aseguradora, empresa, monto? (si vacío = suma de los servicios), cobrar_ahora, metodo.
 * Devuelve ['ok'=>bool, 'id'=>?, 'error'=>?, 'aviso'=>?].
 */
function turno_crear($c, $d, $uid) {
    turnos_asegurar_tablas($c);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($d['servicios'] ?? [])))));
    if (!$ids) return ['ok'=>false, 'error'=>'SERVICIO_REQUERIDO'];
    $in = implode(',', $ids); $servs = [];
    $q = $c->query("SELECT IDTIPOCONSULTA, COALESCE(PRECIO,0) AS PRECIO FROM AG_TIPOCONSULTA WHERE IDTIPOCONSULTA IN ($in) AND ESTADO='A'");
    if ($q) while ($r = $q->fetch_assoc()) $servs[(int)$r['IDTIPOCONSULTA']] = (float)$r['PRECIO'];
    $ids = array_values(array_filter($ids, function($i) use ($servs) { return isset($servs[$i]); }));
    if (!$ids) return ['ok'=>false, 'error'=>'SERVICIO_REQUERIDO'];

    // Especialista opcional (por defecto: cola por servicio)
    $pref = (int)($d['idEspecialista'] ?? 0);
    if ($pref > 0) {
        $rp = $c->query("SELECT 1 FROM ADM_USUARIO U INNER JOIN ADM_ROL R ON R.IDADM_ROL=U.IDADM_ROL WHERE U.IDADM_USUARIO=$pref AND U.ESTADO='A' AND R.CARGO IN ('DOCTOR','ENFERMERA') LIMIT 1");
        if (!$rp || !$rp->fetch_assoc()) return ['ok'=>false, 'error'=>'ESPECIALISTA_INVALIDO'];
    }

    $aviso = '';
    $idPac = (int)($d['idPaciente'] ?? 0);
    if ($idPac <= 0) {
        $ex = turno_buscar_cedula($c, $d['cedula'] ?? '');
        if ($ex) { $idPac = (int)$ex['IDPACIENTE']; }
        else {
            $ced = trim((string)($d['cedula'] ?? ''));
            if ($ced !== '' && preg_match('/^\d{10}$/', $ced) && !cedula_ec_valida($ced)) $aviso = 'CEDULA_INVALIDA';
            $r = paciente_crear_minimo($c, $d['nombres'] ?? '', $d['apellidos'] ?? '', $ced, $d['telefono'] ?? '', '', 'Male', 'atención rápida', false);
            if (!$r['ok']) return ['ok'=>false, 'error'=>$r['error'] === 'DATOS_INCOMPLETOS' ? 'NOMBRE_REQUERIDO' : $r['error'], 'id'=>$r['id'] ?? 0];
            $idPac = $r['id'];
        }
    }
    // Un paciente no puede estar dos veces en la sala el mismo día
    $dup = $c->query("SELECT IDTURNO FROM turno WHERE IDPACIENTE = $idPac AND ESTADO IN ('Triaje','Espera','Atencion') AND DATE(FECHA_INGRESO) = CURDATE() LIMIT 1");
    if ($dup && $dup->fetch_assoc()) return ['ok'=>false, 'error'=>'YA_EN_ESPERA'];

    $suma = 0.0; foreach ($ids as $i) $suma += $servs[$i];
    $monto = (isset($d['monto']) && $d['monto'] !== '') ? max(0, (float)str_replace(',', '.', (string)$d['monto'])) : $suma;
    $texto = function($k, $n) use ($d) { return mb_substr(trim((string)($d[$k] ?? '')), 0, $n); };
    $motivo = $texto('motivo', 255); $der = $texto('derivador', 120); $seg = $texto('aseguradora', 120); $emp = $texto('empresa', 120);
    $estado = (($d['destino'] ?? '') === 'triaje') ? 'Triaje' : 'Espera';
    $primero = $ids[0];
    $c->begin_transaction();
    try {
        $st = $c->prepare("INSERT INTO turno (IDPACIENTE, IDTIPOCONSULTA, MOTIVO, ESTADO, IDUSUARIO_INGRESA, MONTO, IDUSUARIO_PREF, DERIVADOR, ASEGURADORA, EMPRESA)
                           VALUES (?, ?, NULLIF(?,''), ?, ?, ?, NULLIF(?,0), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''))");
        $st->bind_param('iissidisss', $idPac, $primero, $motivo, $estado, $uid, $monto, $pref, $der, $seg, $emp);
        if (!$st->execute()) throw new Exception('DB');
        $id = (int)$c->insert_id; $st->close();
        $sv = $c->prepare("INSERT INTO turno_servicio (IDTURNO, IDTIPOCONSULTA, PRECIO) VALUES (?, ?, ?)");
        foreach ($ids as $i) { $pr = $servs[$i]; $sv->bind_param('iid', $id, $i, $pr); if (!$sv->execute()) throw new Exception('SERV'); }
        $sv->close();
        $c->commit();
    } catch (Exception $e) { $c->rollback(); return ['ok'=>false, 'error'=>'ERROR_'.$e->getMessage()]; }
    auditar($c, 'Atención rápida', 'crear', 'turno', $id, 'Ingresó al paciente #'.$idPac.' ('.count($ids).' servicio/s) → '.($estado === 'Triaje' ? 'triaje' : 'sala de espera'));
    if ($estado === 'Triaje') turno_asegurar_cita($c, $id, $uid);   // la enfermera lo ve en la lista de triaje
    $res = ['ok'=>true, 'id'=>$id, 'aviso'=>$aviso, 'estado'=>$estado];
    if (!empty($d['cobrar_ahora'])) {
        $rc = turno_cobrar($c, $id, $monto, $d['metodo'] ?? 'Efectivo', '', $uid);
        if (!$rc['ok']) $res['aviso_cobro'] = $rc['error'];
    }
    return $res;
}

/** Un profesional toma el turno: crea la cita del día a su nombre y lo pasa a "Atencion". */
function turno_tomar($c, $idTurno, $idUsuario) {
    $idTurno = (int)$idTurno; $idUsuario = (int)$idUsuario;
    $c->begin_transaction();
    try {
        $st = $c->prepare("UPDATE turno SET ESTADO='Atencion', IDUSUARIO_ATIENDE=?, FECHA_INICIO=NOW()
                            WHERE IDTURNO=? AND ESTADO='Espera' AND (IDUSUARIO_PREF IS NULL OR IDUSUARIO_PREF = ? OR ? = 1)");
        $esSistema = !empty($_SESSION['rol']) && strtoupper($_SESSION['rol']) === 'SISTEMA' ? 1 : 0;
        $st->bind_param('iiii', $idUsuario, $idTurno, $idUsuario, $esSistema); $st->execute();
        if ($st->affected_rows !== 1) { $st->close(); $c->rollback(); return ['ok'=>false, 'error'=>'YA_TOMADO']; }
        $st->close();
        $t = $c->query("SELECT IDPACIENTE, IDTIPOCONSULTA, IDCITA, MOTIVO FROM turno WHERE IDTURNO = $idTurno")->fetch_assoc();
        $idCita = (int)($t['IDCITA'] ?? 0);
        if ($idCita > 0) {                              // ya tenía cita (p. ej. por triaje previo): se asigna al profesional
            $c->query("UPDATE AG_CITA SET IDDOCTOR = $idUsuario WHERE IDCITA = $idCita");
        } else {
            $idCita = turno_crear_cita($c, $t, $idUsuario, $idUsuario);
            if ($idCita <= 0) throw new Exception('CITA');
            $c->query("UPDATE turno SET IDCITA = $idCita WHERE IDTURNO = $idTurno");
        }
        $c->commit();
    } catch (Exception $e) { $c->rollback(); return ['ok'=>false, 'error'=>'ERROR_'.$e->getMessage()]; }
    auditar($c, 'Atención rápida', 'editar', 'turno', $idTurno, 'Tomó al paciente (cita #'.$idCita.')');
    return ['ok'=>true, 'idCita'=>$idCita];
}

/** Crea la cita de hoy (a la hora actual) ligada al turno. $idDoctor puede ser 0 (aún sin asignar). */
function turno_crear_cita($c, $t, $idDoctor, $uid) {
    $hoy = date('Y-m-d'); $ini = date('H:i'); $fin = date('H:i', strtotime($ini) + 3600);
    $idPac = (int)$t['IDPACIENTE']; $idTipo = (int)$t['IDTIPOCONSULTA']; $idDoctor = (int)$idDoctor; $uid = (int)$uid;
    $ag = (int)($c->query("SELECT IDAGENCIA FROM ADM_AGENCIA WHERE ESTADO=1 ORDER BY IDAGENCIA LIMIT 1")->fetch_assoc()['IDAGENCIA'] ?? 0);
    $com = 'Atención rápida' . (!empty($t['MOTIVO']) ? ': ' . $t['MOTIVO'] : '');
    $s = $c->prepare("INSERT INTO AG_CITA (IDPACIENTE, IDTIPOCONSULTA, IDDOCTOR, IDUSUARIO, FECHA_CITA, HORA_INICIO, HORA_FIN, IDAGENCIA, ESTADO_CITA, ESTADO, COMENTARIO)
                      VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), 'Pendiente', 'A', ?)");
    $s->bind_param('iiiisssis', $idPac, $idTipo, $idDoctor, $uid, $hoy, $ini, $fin, $ag, $com);
    $ok = $s->execute(); $id = $ok ? (int)$c->insert_id : 0; $s->close();
    return $id;
}

/** Cita ligada al turno aunque nadie lo haya tomado (para registrar el triaje en la sala de espera). */
function turno_asegurar_cita($c, $idTurno, $uid) {
    $idTurno = (int)$idTurno;
    $t = $c->query("SELECT IDPACIENTE, IDTIPOCONSULTA, IDCITA, MOTIVO, ESTADO FROM turno WHERE IDTURNO = $idTurno")->fetch_assoc();
    if (!$t || !in_array($t['ESTADO'], ['Triaje','Espera','Atencion'], true)) return 0;
    if ((int)$t['IDCITA'] > 0) return (int)$t['IDCITA'];
    $pref = (int)($c->query("SELECT COALESCE(IDUSUARIO_PREF,0) p FROM turno WHERE IDTURNO = $idTurno")->fetch_assoc()['p'] ?? 0);
    $id = turno_crear_cita($c, $t, $pref, $uid);
    if ($id > 0) $c->query("UPDATE turno SET IDCITA = $id WHERE IDTURNO = $idTurno");
    return $id;
}

/** Marca la atención como terminada (si el profesional no guarda un reporte, o para servicios rápidos). */
function turno_terminar($c, $idTurno, $uid) {
    $idTurno = (int)$idTurno;
    $c->query("UPDATE turno SET ESTADO='Atendido', FECHA_FIN=NOW() WHERE IDTURNO = $idTurno AND ESTADO='Atencion'");
    $ok = $c->affected_rows === 1;
    if ($ok) {
        $c->query("UPDATE AG_CITA A INNER JOIN turno T ON T.IDCITA = A.IDCITA SET A.ESTADO_CITA='A' WHERE T.IDTURNO = $idTurno AND A.ESTADO_CITA NOT IN ('A','Cancelada','Cancelado')");
        auditar($c, 'Atención rápida', 'editar', 'turno', $idTurno, 'Terminó la atención');
    }
    return $ok;
}

/** Hook: al cerrar la cita (reporte guardado / sueros terminados) el turno asociado pasa a Atendido. */
function turno_cita_atendida($c, $idCita) {
    $idCita = (int)$idCita;
    @$c->query("UPDATE turno SET ESTADO='Atendido', FECHA_FIN=NOW() WHERE IDCITA = $idCita AND ESTADO IN ('Atencion','Espera')");
}

function turno_cancelar($c, $idTurno, $uid) {
    $idTurno = (int)$idTurno;
    $c->query("UPDATE turno SET ESTADO='Cancelado', FECHA_FIN=NOW() WHERE IDTURNO = $idTurno AND ESTADO IN ('Triaje','Espera')");
    $ok = $c->affected_rows === 1;
    if ($ok) {
        $c->query("UPDATE AG_CITA A INNER JOIN turno T ON T.IDCITA = A.IDCITA SET A.ESTADO_CITA='Cancelada' WHERE T.IDTURNO = $idTurno AND A.ESTADO_CITA NOT IN ('A')");
        auditar($c, 'Atención rápida', 'cancelar', 'turno', $idTurno, 'Canceló el turno');
    }
    return $ok;
}

/**
 * Cobra el turno: crea la factura (si no existe) y registra el pago por el monto completo.
 * Devuelve ['ok'=>bool, 'idFactura'=>?, 'error'=>?].
 */
function turno_cobrar($c, $idTurno, $monto, $metodo, $referencia, $uid) {
    turnos_asegurar_tablas($c);
    $idTurno = (int)$idTurno; $uid = (int)$uid;
    $t = $c->query("SELECT T.*, TC.NOMBRES AS SERVICIO, CONCAT(P.APELLIDOS, ', ', P.NOMBRES) AS PAC
                      FROM turno T INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = T.IDPACIENTE
                      INNER JOIN AG_TIPOCONSULTA TC ON TC.IDTIPOCONSULTA = T.IDTIPOCONSULTA WHERE T.IDTURNO = $idTurno")->fetch_assoc();
    if (!$t) return ['ok'=>false, 'error'=>'NO_EXISTE'];
    if ($t['COBRO'] === 'Cobrado') return ['ok'=>false, 'error'=>'YA_COBRADO'];
    if ($t['ESTADO'] === 'Cancelado') return ['ok'=>false, 'error'=>'CANCELADO'];
    $monto = round((float)str_replace(',', '.', (string)$monto), 2);
    if ($monto <= 0) return ['ok'=>false, 'error'=>'MONTO_INVALIDO'];
    if (!in_array($metodo, ['Efectivo','Tarjeta','Transferencia','Otro'], true)) $metodo = 'Otro';
    $referencia = mb_substr(trim((string)$referencia), 0, 100);

    $c->begin_transaction();
    try {
        $fecha = date('Y-m-d'); $idCita = $t['IDCITA'] ? (int)$t['IDCITA'] : null; $idPac = (int)$t['IDPACIENTE'];
        $desc = mb_substr($t['SERVICIO'], 0, 500); $notaF = trim(($t['ASEGURADORA'] ? 'Aseguradora: '.$t['ASEGURADORA'].'. ' : '').($t['EMPRESA'] ? 'Empresa: '.$t['EMPRESA'].'. ' : '').($t['DERIVADOR'] ? 'Médico derivador: '.$t['DERIVADOR'].'.' : '')); $nom = mb_substr($t['PAC'], 0, 200); $status = 'Billing Client';
        $f = $c->prepare("INSERT INTO facturas (IDPACIENTE, client_name, IDCITA, fecha, status, descripcion, total, pagado, saldo, origen, creado_por, notas)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'rapida', ?, NULLIF(?,''))");
        $f->bind_param('isisssddis', $idPac, $nom, $idCita, $fecha, $status, $desc, $monto, $monto, $uid, $notaF);
        if (!$f->execute()) throw new Exception('FACTURA');
        $idF = (int)$c->insert_id; $f->close();
        $bn = 'F' . str_pad((string)$idF, 6, '0', STR_PAD_LEFT);
        $c->query("UPDATE facturas SET billing_number='" . $c->real_escape_string($bn) . "' WHERE id = $idF");
        $lineas = [];
        $ql = $c->query("SELECT TC.NOMBRES, TS.PRECIO FROM turno_servicio TS INNER JOIN AG_TIPOCONSULTA TC ON TC.IDTIPOCONSULTA = TS.IDTIPOCONSULTA WHERE TS.IDTURNO = $idTurno ORDER BY TS.ID");
        if ($ql) while ($l = $ql->fetch_assoc()) $lineas[] = [mb_substr($l['NOMBRES'], 0, 500), (float)$l['PRECIO']];
        if (!$lineas) $lineas[] = [$desc, $monto];
        $sumaL = 0; foreach ($lineas as $l) $sumaL += $l[1];
        // Si el monto cobrado difiere de la suma de servicios (descuento/ajuste), se agrega una línea de ajuste
        if (abs($sumaL - $monto) > 0.004) $lineas[] = [$monto > $sumaL ? 'Ajuste (+)' : 'Descuento / ajuste', round($monto - $sumaL, 2)];
        $d = $c->prepare("INSERT INTO factura_detalle (id_factura, billing_number, code, description, unit_price, quantity, importe) VALUES (?, ?, '', ?, ?, 1, ?)");
        foreach ($lineas as $l) { $d->bind_param('issdd', $idF, $bn, $l[0], $l[1], $l[1]); if (!$d->execute()) throw new Exception('DETALLE'); }
        $d->close();
        $p = $c->prepare("INSERT INTO factura_pagos (id_factura, fecha, monto, metodo, referencia, registrado_por) VALUES (?, ?, ?, ?, NULLIF(?,''), ?)");
        $p->bind_param('isdssi', $idF, $fecha, $monto, $metodo, $referencia, $uid);
        if (!$p->execute()) throw new Exception('PAGO'); $p->close();
        $u = $c->prepare("UPDATE turno SET COBRO='Cobrado', MONTO=?, METODO=?, IDFACTURA=?, FECHA_COBRO=NOW() WHERE IDTURNO=? AND COBRO='Pendiente'");
        $u->bind_param('dsii', $monto, $metodo, $idF, $idTurno); $u->execute();
        if ($u->affected_rows !== 1) throw new Exception('YA_COBRADO'); $u->close();
        $c->commit();
    } catch (Exception $e) { $c->rollback(); return ['ok'=>false, 'error'=>$e->getMessage() === 'YA_COBRADO' ? 'YA_COBRADO' : 'ERROR_'.$e->getMessage()]; }
    auditar($c, 'Atención rápida', 'editar', 'turno', $idTurno, 'Cobró $'.number_format($monto, 2).' ('.$metodo.') — factura '.$bn);
    return ['ok'=>true, 'idFactura'=>$idF, 'factura'=>$bn];
}

/** Lista de turnos de hoy (con nombres). Filtra por estados. */
function turnos_hoy($c) {
    $out = [];
    $q = @$c->query("SELECT T.*, TC.NOMBRES AS SERVICIO, TC.ROL_ATIENDE, CONCAT(P.NOMBRES,' ',P.APELLIDOS) AS PACIENTE, P.CEDULA,
                            TRIM(CONCAT(COALESCE(U.NOMBRES,''),' ',COALESCE(U.APELLIDOS,''))) AS ATIENDE,
                            TIMESTAMPDIFF(MINUTE, T.FECHA_INGRESO, NOW()) AS MIN_ESPERA,
                            TR.IDTRIAJE,
                            (SELECT GROUP_CONCAT(X.NOMBRES ORDER BY S2.ID SEPARATOR ' + ') FROM turno_servicio S2 INNER JOIN AG_TIPOCONSULTA X ON X.IDTIPOCONSULTA = S2.IDTIPOCONSULTA WHERE S2.IDTURNO = T.IDTURNO) AS SERVICIOS,
                            (SELECT COUNT(*) FROM turno_servicio S3 WHERE S3.IDTURNO = T.IDTURNO) AS NSERV,
                            (SELECT COUNT(*) FROM turno_servicio S4 INNER JOIN AG_TIPOCONSULTA X4 ON X4.IDTIPOCONSULTA = S4.IDTIPOCONSULTA WHERE S4.IDTURNO = T.IDTURNO AND X4.ROL_ATIENDE = 'ENFERMERA') AS NENF,
                            (SELECT TRIM(CONCAT(COALESCE(UP.NOMBRES,''),' ',COALESCE(UP.APELLIDOS,''))) FROM ADM_USUARIO UP WHERE UP.IDADM_USUARIO = T.IDUSUARIO_PREF) AS ESPECIALISTA
                       FROM turno T
                       INNER JOIN AG_PACIENTE P ON P.IDPACIENTE = T.IDPACIENTE
                       INNER JOIN AG_TIPOCONSULTA TC ON TC.IDTIPOCONSULTA = T.IDTIPOCONSULTA
                       LEFT JOIN ADM_USUARIO U ON U.IDADM_USUARIO = T.IDUSUARIO_ATIENDE
                       LEFT JOIN triaje TR ON TR.IDCITA = T.IDCITA
                      WHERE DATE(T.FECHA_INGRESO) = CURDATE() OR T.ESTADO IN ('Espera','Atencion') OR (T.ESTADO='Atendido' AND T.COBRO='Pendiente')
                      ORDER BY T.FECHA_INGRESO");
    if ($q) while ($r = $q->fetch_assoc()) $out[] = $r;
    return $out;
}

/** ¿Este turno en espera le corresponde a un profesional (por especialista elegido o por el rol que atiende el servicio)? */
function turno_visible_para($t, $idUsuario, $rol) {
    $rol = strtoupper((string)$rol);
    if ($rol === 'SISTEMA') return true;
    if (!empty($t['IDUSUARIO_PREF'])) return (int)$t['IDUSUARIO_PREF'] === (int)$idUsuario;
    $nEnf = (int)($t['NENF'] ?? 0); $nServ = (int)($t['NSERV'] ?? 0);
    if ($rol === 'ENFERMERA') return $nEnf > 0;
    if ($rol === 'DOCTOR')    return $nEnf < $nServ || $nServ === 0;
    return false;
}

/** Formato "hace 12 min" / "1 h 05 min". */
function turno_minutos_txt($min) {
    $min = max(0, (int)$min);
    return $min < 60 ? $min . ' min' : intdiv($min, 60) . ' h ' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) . ' min';
}
