<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once("funciones.php");
require_once("conexionBD.php");
require_once(__DIR__ . "/auditoria.php");
require_once(__DIR__ . "/modulos.php");
require_once(__DIR__ . "/../lang/i18n.php");
$conexion = conectarse();
?>
<?php
if ($conexion->connect_error) {
 die("La conexion falló: " . $conexion->connect_error);
}

$username = $_POST['user'];
$password = $_POST['password'];
 
$sql = "SELECT a.IDADM_USUARIO, a.NOMBRES, a.APELLIDOS, a.USUARIO, a.CONTRASENA, b.IDADM_ROL, b.CARGO
    FROM ADM_USUARIO a INNER JOIN ADM_ROL b ON a.IDADM_ROL = b.IDADM_ROL
    WHERE USUARIO = '$username' AND a.ESTADO = 'A'";
 
$result = $conexion->query($sql);

if ($result->num_rows > 0) {
    $row = mysqli_fetch_array($result);
    $PASS = $row['CONTRASENA'];
    if (password_verify($password, $row['CONTRASENA'])) { 
        session_start();
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['iduser'] = $row['IDADM_USUARIO'];
        $_SESSION['rol'] = $row['CARGO'];
        $_SESSION['nombres'] = $row['NOMBRES'];  // Guardar nombres en la sesión
        $_SESSION['apellidos'] = $row['APELLIDOS'];  // Guardar apellidos en la sesión
        $_SESSION['start'] = time();
        // Ventana de sesión: 24 horas (cubre una jornada completa de consultas
        // sin que se cierre a mitad de una atención). Antes 12 h / 1 h.
        // Nota: para que la sesión no se pierda mientras se escribe una nota
        // larga (sin peticiones al servidor), también se sube a 24 h el
        // session.gc_maxlifetime en .user.ini.
        $_SESSION['expire'] = $_SESSION['start'] + (60 * 60 * 24);

        auditar($conexion, 'Sesión', 'login', 'ADM_USUARIO', $row['IDADM_USUARIO'], 'Inició sesión');

        echo "<script language='JavaScript'>";
        // Pantalla de inicio según el rol
        $cargo = strtoupper($row['CARGO']);
        $destino = 'SCH_Calendar.php';
        if ($cargo === 'ENFERMERA' && modulo_activo('triaje')) $destino = 'triaje.php';
        elseif ($cargo === 'ASISTENTE') $destino = 'home.php';
        elseif ($cargo === 'DOCTOR' && modulo_activo('atencion_rapida')) $destino = 'sala_espera.php';
        echo 'self.location = "../' . $destino . '"';
        echo "</script>";
    } else {
        auditar($conexion, 'Sesión', 'login_fallido', 'ADM_USUARIO', null, 'Contraseña incorrecta para usuario: '.$username);
        echo htmlspecialchars(t('login.error_wrong'), ENT_QUOTES, 'UTF-8');
        echo "<br><a href='../index.php'>" . htmlspecialchars(t('login.retry'), ENT_QUOTES, 'UTF-8') . "</a>";
    }
} else {
    auditar($conexion, 'Sesión', 'login_fallido', 'ADM_USUARIO', null, 'Usuario no encontrado: '.$username);
    echo htmlspecialchars(t('login.error_notfound'), ENT_QUOTES, 'UTF-8');
}

mysqli_close($conexion); 
?>

