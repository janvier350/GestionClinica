<?php
/**
 * limpiar_datos_clinica.php
 * Deja la base de datos de esta instalación SIN datos de clientes: vacía
 * pacientes, citas, historiales, facturas, documentos, tickets, auditoría, etc.
 * Pensado para una instalación nueva creada a partir de una copia de otra clínica.
 *
 *  - SÓLO SISTEMA. Destructivo e IRREVERSIBLE (haz un respaldo antes).
 *  - Conserva: estructura, catálogos (tipos de consulta, ICD-10, plantillas,
 *    seguros, bill items, agencias, roles, colores), configuración (app_config)
 *    y los usuarios con rol SISTEMA. Elimina el resto de usuarios y sus permisos.
 *  - Salvaguardas: muestra la base actual, exige escribir su nombre y la frase
 *    de confirmación, y marcar "entiendo". Muestra un conteo previo por tabla.
 *  - No borra archivos del disco (documentos_pacientes/, tickets_adjuntos/).
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') {
    http_response_code(403); exit('Acceso restringido: sólo SISTEMA.');
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$dbActual = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
$dbEsc    = $conexion->real_escape_string($dbActual);
$FRASE_OK = 'VACIAR CLINICA';

// Tablas de datos de clientes/operación (se vacían si existen).
$tablasDatos = [
    'AG_CITA', 'AG_HISTORIAL', 'AG_PACIENTE', 'AG_PLAN_PESO',
    'paciente_contactos', 'paciente_icd10', 'paciente_seguro', 'paciente_archivo', 'paciente_archivos',
    'documento_envio', 'facturas', 'factura_detalle', 'factura_pagos',
    'ausencias_doctor', 'tickets', 'ticket_adjuntos', 'ticket_comentarios',
    'triaje', 'auditoria', 'ip_geo', 'usuario_permisos', 'ADM_DOCTOR',
];

$existe = function ($t) use ($conexion, $dbEsc) {
    $t = $conexion->real_escape_string($t);
    return (int)$conexion->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA='$dbEsc' AND TABLE_NAME='$t'")->fetch_assoc()['c'] > 0;
};
$cuenta = function ($t) use ($conexion) {
    $r = @$conexion->query("SELECT COUNT(*) c FROM `$t`");
    return $r ? (int)$r->fetch_assoc()['c'] : 0;
};

$idRolSistema = 0;
$r = $conexion->query("SELECT IDADM_ROL FROM ADM_ROL WHERE CARGO='SISTEMA' LIMIT 1");
if ($r && ($x = $r->fetch_assoc())) $idRolSistema = (int)$x['IDADM_ROL'];
$usuariosBorrar = $idRolSistema ? (int)$conexion->query("SELECT COUNT(*) c FROM ADM_USUARIO WHERE IDADM_ROL <> $idRolSistema")->fetch_assoc()['c'] : 0;
$usuariosQuedan = $idRolSistema ? (int)$conexion->query("SELECT COUNT(*) c FROM ADM_USUARIO WHERE IDADM_ROL = $idRolSistema")->fetch_assoc()['c'] : 0;

$errores = []; $resultado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['confirmar_db'] ?? '') !== $dbActual) $errores[] = 'El nombre de la base escrito no coincide con la base actual.';
    if (trim($_POST['frase'] ?? '') !== $FRASE_OK)        $errores[] = 'La frase de confirmación no coincide.';
    if (!isset($_POST['entiendo']))                        $errores[] = 'Debes marcar la casilla de confirmación.';
    if (!$idRolSistema || $usuariosQuedan < 1)             $errores[] = 'No hay ningún usuario SISTEMA: el borrado dejaría la app sin acceso. Operación cancelada.';
    if (!$errores) {
        $resultado = [];
        $conexion->query("SET FOREIGN_KEY_CHECKS=0");
        foreach ($tablasDatos as $t) {
            if (!$existe($t)) continue;
            $n = $cuenta($t);
            $ok = $conexion->query("TRUNCATE TABLE `$t`");
            $resultado[] = [$t, $ok ? "vaciada ($n filas)" : 'ERROR: '.$conexion->error, (bool)$ok];
        }
        $ok = $conexion->query("DELETE FROM ADM_USUARIO WHERE IDADM_ROL <> $idRolSistema");
        $resultado[] = ['ADM_USUARIO', $ok ? "eliminados $usuariosBorrar usuarios no-SISTEMA" : 'ERROR: '.$conexion->error, (bool)$ok];
        $conexion->query("SET FOREIGN_KEY_CHECKS=1");
        $usuariosBorrar = 0;
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Vaciar datos de la clínica</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:760px;">
<h4 class="text-danger">Vaciar datos de la clínica</h4>
<p>Base de datos actual: <code><?php echo h($dbActual); ?></code></p>

<?php foreach ($errores as $e): ?><div class="alert alert-danger"><?php echo h($e); ?></div><?php endforeach; ?>

<?php if ($resultado !== null): ?>
    <div class="alert alert-success">Listo. La instalación quedó sin datos de clientes. Los usuarios SISTEMA se conservaron: <b>cambia sus contraseñas</b>.</div>
    <ul class="list-group mb-3">
    <?php foreach ($resultado as $x): ?><li class="list-group-item d-flex justify-content-between"><code><?php echo h($x[0]); ?></code><span class="<?php echo $x[2]?'text-success':'text-danger'; ?>"><?php echo h($x[1]); ?></span></li><?php endforeach; ?>
    </ul>
    <a class="btn btn-primary" href="SCH_Calendar.php">Ir al calendario</a>
<?php else: ?>
    <div class="alert alert-warning"><b>Irreversible.</b> Haz un respaldo (phpMyAdmin → Exportar) antes de continuar. Se conservan estructura, catálogos, configuración y usuarios SISTEMA.</div>
    <table class="table table-sm"><thead><tr><th>Tabla</th><th class="text-end">Filas que se borrarán</th></tr></thead><tbody>
    <?php foreach ($tablasDatos as $t): if (!$existe($t)) continue; ?>
        <tr><td><code><?php echo h($t); ?></code></td><td class="text-end"><?php echo number_format($cuenta($t)); ?></td></tr>
    <?php endforeach; ?>
        <tr><td><code>ADM_USUARIO</code> (no-SISTEMA)</td><td class="text-end"><?php echo number_format($usuariosBorrar); ?></td></tr>
    </tbody></table>
    <p class="small text-muted">Usuarios SISTEMA que se conservan: <?php echo (int)$usuariosQuedan; ?>.</p>
    <form method="POST" class="card card-body">
        <label class="form-label">Escribe el nombre de la base (<code><?php echo h($dbActual); ?></code>):</label>
        <input name="confirmar_db" class="form-control mb-2" autocomplete="off" required>
        <label class="form-label">Escribe la frase <code><?php echo h($FRASE_OK); ?></code>:</label>
        <input name="frase" class="form-control mb-2" autocomplete="off" required>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="entiendo" id="e"><label class="form-check-label" for="e">Entiendo que se borrarán los datos y no se puede deshacer.</label></div>
        <button class="btn btn-danger" type="submit">Vaciar datos</button>
    </form>
<?php endif; ?>
</div></body></html>
