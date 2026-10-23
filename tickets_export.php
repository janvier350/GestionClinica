<?php
/**
 * tickets_export.php — Exporta las solicitudes (con filtros) a CSV para Excel.
 * Sólo quien gestiona (panel.tickets). Útil para medir el mantenimiento mensual.
 */
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/permisos.php");
if (!modulo_activo('tickets')) { http_response_code(403); exit('Módulo desactivado'); }
require_once(__DIR__ . "/class/tickets.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

if (!isset($_SESSION["rol"])) { header("Location: break.php"); exit(); }
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) { session_destroy(); header("Location: expirada.php"); exit(); }
if (!tickets_puede_gestionar()) { header("Location: break.php"); exit(); }

tickets_ensure_tablas($conexion);
$cat = tickets_catalogos();

$fEstado = trim($_GET['estado'] ?? '');
$fModulo = trim($_GET['modulo'] ?? '');
$fTipo   = trim($_GET['tipo']   ?? '');
$fQ      = trim($_GET['q']      ?? '');
$fDesde  = trim($_GET['desde']  ?? '');
$fHasta  = trim($_GET['hasta']  ?? '');

$where = "WHERE 1=1"; $tipos=''; $vals=[];
if ($fEstado !== '') { $where.=" AND estado=?"; $tipos.='s'; $vals[]=$fEstado; }
if ($fModulo !== '') { $where.=" AND modulo=?"; $tipos.='s'; $vals[]=$fModulo; }
if ($fTipo   !== '') { $where.=" AND tipo=?";   $tipos.='s'; $vals[]=$fTipo; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$fDesde)) { $where.=" AND fecha>=?"; $tipos.='s'; $vals[]=$fDesde.' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$fHasta)) { $where.=" AND fecha<=?"; $tipos.='s'; $vals[]=$fHasta.' 23:59:59'; }
if ($fQ !== '') { $where.=" AND (titulo LIKE ? OR descripcion LIKE ? OR solicitante LIKE ?)"; $tipos.='sss'; $like='%'.$fQ.'%'; array_push($vals,$like,$like,$like); }

$sql = "SELECT id, titulo, modulo, tipo, prioridad, estado, solicitante, rol_solicitante, fecha, fecha_cierre
        FROM tickets $where ORDER BY fecha DESC";
$stmt = $conexion->prepare($sql);
if ($tipos !== '') $stmt->bind_param($tipos, ...$vals);
$stmt->execute();
$res = $stmt->get_result();

$fname = 'solicitudes_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
fputcsv($out, ['#','Título','Módulo','Tipo','Prioridad','Estado','Solicitante','Rol','Fecha creación','Fecha cierre']);
while ($r = $res->fetch_assoc()) {
    fputcsv($out, [
        $r['id'], $r['titulo'], $r['modulo'], $r['tipo'], $r['prioridad'], $r['estado'],
        $r['solicitante'], $r['rol_solicitante'],
        $r['fecha'] ? date('m/d/Y H:i', strtotime($r['fecha'])) : '',
        $r['fecha_cierre'] ? date('m/d/Y', strtotime($r['fecha_cierre'])) : '',
    ]);
}
$stmt->close();
fclose($out);
exit;
