<?php
/**
 * migrar_duracion_citas_1h.php — Normaliza la duración de las citas FUTURAS a
 * 1 hora (HORA_FIN = HORA_INICIO + 1h). No toca citas pasadas ni atendidas.
 * Idempotente. SISTEMA-only, POST-driven.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') {
    http_response_code(403); exit('Acceso restringido: sólo SISTEMA.');
}

// Cuántas citas futuras activas hay (para informar)
$pend = 0;
if ($r = $conexion->query("SELECT COUNT(*) c FROM AG_CITA WHERE ESTADO='A' AND FECHA_CITA >= CURDATE()")) {
    $pend = (int)$r->fetch_assoc()['c'];
}

$afect = null; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Solo futuras y activas; fija HORA_FIN = HORA_INICIO + 1 hora.
    $ok = $conexion->query(
        "UPDATE AG_CITA
            SET HORA_FIN = ADDTIME(HORA_INICIO, '01:00:00')
          WHERE ESTADO='A'
            AND FECHA_CITA >= CURDATE()
            AND HORA_INICIO IS NOT NULL
            AND TIME(HORA_FIN) <> ADDTIME(TIME(HORA_INICIO), '01:00:00')"
    );
    if ($ok) { $afect = $conexion->affected_rows; } else { $err = $conexion->error; }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Normalizar duración de citas</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:640px;">
<h4>Normalizar duración de citas futuras a 1 hora</h4>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <?php if ($afect !== null): ?>
        <div class="alert alert-success">Listo. Citas ajustadas a 1 hora: <b><?php echo (int)$afect; ?></b>.</div>
    <?php else: ?>
        <div class="alert alert-danger">Error: <?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>
    <a class="btn btn-primary" href="SCH_Calendar.php">Ir al calendario</a>
<?php else: ?>
    <p class="text-muted">Pone la hora de fin = hora de inicio + 1 hora, sólo en las citas
    <b>futuras</b> y activas (<?php echo (int)$pend; ?> en total). No modifica citas pasadas ni atendidas. Idempotente.</p>
    <form method="POST"><button class="btn btn-primary" type="submit">Normalizar a 1 hora</button></form>
<?php endif; ?>
</div></body></html>
