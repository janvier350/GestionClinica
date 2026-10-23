<?php
/**
 * migrar_triaje.php
 * Crea el rol "ENFERMERA" en ADM_ROL y la tabla `triaje`. Idempotente.
 * SISTEMA-only, POST-driven (mismo patrón que migrar_rol_asistente.php).
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once("class/triaje.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }
if (!isset($_SESSION["rol"]) || strtoupper($_SESSION["rol"]) !== 'SISTEMA') {
    http_response_code(403); exit('Acceso restringido: sólo SISTEMA.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Triaje</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><div class="container" style="max-width:640px;">
<h4>Preparar módulo de Triaje</h4>
<p class="text-muted">Crea el rol <code>ENFERMERA</code> y la tabla <code>triaje</code>. Idempotente.
Después activa el módulo en <a href="modulos_config.php">Módulos</a>.</p>
<form method="POST"><button class="btn btn-primary" type="submit">Ejecutar</button>
<a class="btn btn-link" href="modulos_config.php">Ir a Módulos</a></form></div></body></html>
    <?php
    exit;
}
$msgs = [];
$chk = $conexion->query("SELECT IDADM_ROL FROM ADM_ROL WHERE CARGO='ENFERMERA' LIMIT 1");
if ($chk && $chk->num_rows > 0) {
    $msgs[] = ['OK','El rol ENFERMERA ya existe.'];
} else {
    $ins = $conexion->query("INSERT INTO ADM_ROL (CARGO, ESTADO) VALUES ('ENFERMERA','A')");
    $msgs[] = $ins ? ['NEW','Rol ENFERMERA creado.'] : ['ERR','No se pudo crear el rol: '.$conexion->error];
}
$msgs[] = triaje_asegurar_tabla($conexion) ? ['NEW','Tabla triaje lista.'] : ['ERR','No se pudo crear triaje: '.$conexion->error];
$cls=['NEW'=>'success','OK'=>'secondary','ERR'=>'danger'];
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Resultado</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4"><div class="container" style="max-width:640px;"><h4>Resultado</h4><ul class="list-group">';
foreach ($msgs as $m) echo '<li class="list-group-item"><span class="badge bg-'.($cls[$m[0]]??'secondary').' me-2">'.htmlspecialchars($m[0]).'</span>'.htmlspecialchars($m[1]).'</li>';
echo '</ul><a class="btn btn-primary mt-3" href="modulos_config.php">Ir a Módulos</a></div></body></html>';
