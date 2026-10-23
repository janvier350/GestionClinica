<?php
function conectarse()
{
    // Esta aplicación está escrita para el modo clásico de mysqli: cada consulta
    // devuelve false ante un error y el código lo comprueba (o usa "or die").
    // En PHP 8.1+ el modo por defecto lanza excepciones, lo que convertía
    // cualquier error de consulta en un HTTP 500. Restauramos el modo clásico.
    if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }

    // Credenciales: cada instalación define las suyas en config.local.php (en la
    // raíz, fuera del control de versiones; ver config.local.example.php).
    // Si el archivo no existe se usan los valores históricos de esta instalación.
    $db_host   = "localhost";
    $db_nombre = "srossnut_agenda";
    $db_user   = "srossnut_agenda";
    $db_pass   = "nAGTDbMpym6nNv9aedHJ";
    $cfgLocal  = __DIR__ . '/../config.local.php';
    if (is_file($cfgLocal)) {
        $cfg = include $cfgLocal;
        if (is_array($cfg)) {
            $db_host   = $cfg['db_host'] ?? $db_host;
            $db_nombre = $cfg['db_name'] ?? $db_nombre;
            $db_user   = $cfg['db_user'] ?? $db_user;
            $db_pass   = $cfg['db_pass'] ?? $db_pass;
        }
    }

    $link = @mysqli_connect($db_host, $db_user, $db_pass);
    if (!$link) {
        // Mensaje claro en lugar de un HTTP 500 mudo (sin revelar credenciales).
        http_response_code(500);
        die("No se pudo conectar a la base de datos (usuario «" . htmlspecialchars($db_user) . "»). "
          . "Verifique que exista config.local.php en la raíz de la aplicación con los datos correctos.");
    }
    mysqli_select_db($link, $db_nombre) or die("Error seleccionando la base de datos «" . htmlspecialchars($db_nombre) . "».");
    mysqli_set_charset($link, 'utf8mb4');   // ← línea correcta, procedural

    // Zona horaria de la aplicación — configurable por base de datos desde la
    // página "Zona horaria" (tabla app_config, clave 'timezone'). Se usa la
    // misma en PHP y en MySQL para que las horas (bitácora, "creado el", etc.)
    // sean consistentes sin importar la zona del servidor de hosting.
    // Por defecto Nueva York (Este). Cada instalación/BD tiene su propio valor.
    if (!defined('APP_TZ')) {
        $tzApp = 'America/New_York';
        $rz = @mysqli_query($link, "SELECT valor FROM app_config WHERE clave='timezone' LIMIT 1");
        if ($rz && ($rowz = mysqli_fetch_assoc($rz))) {
            $vz = trim((string)($rowz['valor'] ?? ''));
            if ($vz !== '') $tzApp = $vz;
        }
        define('APP_TZ', $tzApp);
    }
    @date_default_timezone_set(APP_TZ);
    try {
        // Offset actual con horario de verano correcto (p. ej. -04:00 / -05:00).
        $off = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('P');
        @mysqli_query($link, "SET time_zone = '$off'");
    } catch (Exception $e) { /* si falla, se mantiene la zona del servidor */ }

    return $link;
}
?>