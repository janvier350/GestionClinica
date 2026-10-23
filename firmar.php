<?php
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/mailer.php");
$conexion = conectarse();
if ($conexion) { $conexion->set_charset('utf8mb4'); }

$token = preg_replace('/[^a-fA-F0-9]/', '', $_GET['t'] ?? '');

$envio = null;
if ($token) {
    $stmt = $conexion->prepare(
        "SELECT e.id_envio, e.codigo, e.estado, e.fecha_firma, e.firmado_por, e.fecha_envio,
                d.titulo, d.contenido, d.archivo_pdf,
                CONCAT(p.NOMBRES,' ',p.APELLIDOS) AS paciente, p.CEDULA, p.FECHANACIMIENTO
           FROM documento_envio e
           INNER JOIN documentos  d ON d.id_documento = e.id_documento
           INNER JOIN AG_PACIENTE p ON p.IDPACIENTE   = e.IDPACIENTE
          WHERE e.token = ? LIMIT 1"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $envio = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Verificación de código
$codigoOk  = false;
$errCodigo = '';
if ($envio && $envio['estado'] !== 'Firmado'
    && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['codigo'])) {
    if (trim($_POST['codigo']) === (string)$envio['codigo']) {
        $codigoOk = true;
    } else {
        $errCodigo = 'Código incorrecto. Revisa el correo e inténtalo de nuevo.';
    }
}

// Reemplaza los campos {{...}} del documento con los datos reales
function aplicarCampos($html, $d) {
    $fnac = (!empty($d['FECHANACIMIENTO']) && $d['FECHANACIMIENTO'] !== '0000-00-00')
        ? date('m/d/Y', strtotime($d['FECHANACIMIENTO'])) : '';
    $fecha  = !empty($d['fecha_envio']) ? date('m/d/Y', strtotime($d['fecha_envio'])) : date('m/d/Y');
    $ffirma = !empty($d['fecha_firma']) ? date('m/d/Y', strtotime($d['fecha_firma'])) : '';
    return strtr($html, [
        '{{paciente}}'         => htmlspecialchars($d['paciente'] ?? ''),
        '{{nombre}}'           => htmlspecialchars($d['paciente'] ?? ''),
        '{{cedula}}'           => htmlspecialchars($d['CEDULA'] ?? ''),
        '{{fecha_nacimiento}}' => htmlspecialchars($fnac),
        '{{fecha}}'            => htmlspecialchars($fecha),
        '{{fecha_firma}}'      => htmlspecialchars($ffirma),
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Firmar documento - <?php echo htmlspecialchars(mail_brand(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .doc-wrap { max-width:760px; margin:24px auto; }
        .doc-head { background:#5a2d82; color:#fff; padding:20px; border-radius:8px 8px 0 0; text-align:center; }
        .doc-body { background:#fff; border:1px solid #e7e7e7; border-top:none; padding:24px; border-radius:0 0 8px 8px; }
        .doc-content { border:1px solid #eee; border-radius:6px; padding:16px; max-height:50vh; overflow:auto; background:#fff; }
        .doc-content input.campo-llenar[type="text"] { border:none; border-bottom:1px solid #5a2d82; background:#fff8e1; padding:1px 4px; border-radius:2px; }
        .doc-content input.campo-llenar[type="checkbox"] { width:16px; height:16px; vertical-align:middle; margin:0 4px; accent-color:#5a2d82; }
        #firmaCanvas { border:1px dashed #aaa; border-radius:6px; width:100%; height:160px; touch-action:none; background:#fff; }
    </style>
</head>
<body>
<div class="doc-wrap">
    <div class="doc-head">
        <h4 class="mb-0"><i class="bi bi-file-earmark-text"></i> Documento para firmar</h4>
        <div style="color:#e0c8f8;font-size:.9rem;"><?php echo htmlspecialchars(mail_brand(), ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <div class="doc-body">

    <?php if (!$envio): ?>
        <div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> El enlace no es válido o ya no está disponible.</div>

    <?php elseif ($envio['estado'] === 'Firmado'): ?>
        <div class="alert alert-success mb-0">
            <i class="bi bi-check-circle"></i> Este documento ya fue firmado
            <?php if (!empty($envio['firmado_por'])): ?>por <strong><?php echo htmlspecialchars($envio['firmado_por']); ?></strong><?php endif; ?>
            <?php if (!empty($envio['fecha_firma'])): ?> el <?php echo date('m/d/Y H:i', strtotime($envio['fecha_firma'])); ?><?php endif; ?>.
            <br>¡Gracias!
        </div>

    <?php elseif (!$codigoOk): ?>
        <p>Hola, <strong><?php echo htmlspecialchars($envio['paciente']); ?></strong>.</p>
        <p>Para abrir el documento <strong><?php echo htmlspecialchars($envio['titulo']); ?></strong>, ingresa el <strong>código de acceso</strong> que recibiste por correo.</p>
        <?php if ($errCodigo): ?>
            <div class="alert alert-danger py-2"><?php echo htmlspecialchars($errCodigo); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3" style="max-width:240px;">
                <label class="form-label">Código de acceso</label>
                <input type="text" name="codigo" class="form-control form-control-lg text-center"
                       style="letter-spacing:6px;" maxlength="6" inputmode="numeric" autocomplete="off" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-unlock"></i> Abrir documento</button>
        </form>

    <?php else: ?>
        <!-- Código correcto: mostrar documento + firma -->
        <h5 class="mb-3"><?php echo htmlspecialchars($envio['titulo']); ?></h5>
        <div class="doc-content mb-3" id="docContenido"><?php echo aplicarCampos($envio['contenido'], $envio); ?></div>
        <?php if (strpos($envio['contenido'], 'campo-llenar') !== false): ?>
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Completa los campos en blanco resaltados dentro del documento antes de firmar.</div>
        <?php endif; ?>
        <?php if (!empty($envio['archivo_pdf'])): ?>
        <div class="mb-3">
            <a href="<?php echo htmlspecialchars($envio['archivo_pdf']); ?>" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf"></i> Ver / descargar el PDF adjunto
            </a>
        </div>
        <?php endif; ?>

        <form method="POST" action="firmar_guardar.php" id="formFirma" onsubmit="return prepararFirma();">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($envio['codigo']); ?>">
            <input type="hidden" name="firma" id="firmaInput">
            <input type="hidden" name="campos_json" id="camposInput">

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="acepto" name="acepto" value="1" required>
                <label class="form-check-label" for="acepto">
                    He leído y <strong>acepto</strong> los términos del documento.
                </label>
            </div>

            <div class="mb-2">
                <label class="form-label">Nombre de quien firma</label>
                <input type="text" name="firmado_por" class="form-control" maxlength="150"
                       value="<?php echo htmlspecialchars($envio['paciente']); ?>" required>
            </div>

            <label class="form-label">Firma (dibuja con el dedo o el mouse)</label>
            <canvas id="firmaCanvas"></canvas>
            <div class="d-flex justify-content-between mt-1 mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="limpiarFirma()">
                    <i class="bi bi-eraser"></i> Limpiar
                </button>
                <small class="text-muted">Tu firma quedará registrada con fecha y hora.</small>
            </div>

            <button type="submit" class="btn btn-success btn-lg w-100">
                <i class="bi bi-check2-circle"></i> Firmar y enviar
            </button>
        </form>
    <?php endif; ?>

    </div>
</div>

<script>
// ── Canvas de firma (mouse + táctil) ──────────────────────────────────
(function () {
    var canvas = document.getElementById('firmaCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var dibujando = false, hayFirma = false;

    function ajustarTamano() {
        // Mantener resolución acorde al ancho mostrado
        var ratio = window.devicePixelRatio || 1;
        var w = canvas.offsetWidth, h = canvas.offsetHeight;
        canvas.width = w * ratio;
        canvas.height = h * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#222';
    }
    ajustarTamano();
    window.addEventListener('resize', function(){ var d = canvas.toDataURL(); ajustarTamano(); });

    function pos(e) {
        var r = canvas.getBoundingClientRect();
        var p = e.touches ? e.touches[0] : e;
        return { x: p.clientX - r.left, y: p.clientY - r.top };
    }
    function start(e){ dibujando = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
    function move(e){ if(!dibujando) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); hayFirma = true; e.preventDefault(); }
    function end(e){ dibujando = false; }

    canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move);
    document.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start); canvas.addEventListener('touchmove', move);
    canvas.addEventListener('touchend', end);

    window.limpiarFirma = function(){ ctx.clearRect(0,0,canvas.width,canvas.height); hayFirma = false; };
    window.prepararFirma = function(){
        if (!hayFirma) { alert('Por favor dibuja tu firma antes de enviar.'); return false; }
        if (!document.getElementById('acepto').checked) { alert('Debes aceptar los términos.'); return false; }

        var cont  = document.getElementById('docContenido');
        var datos = [];
        if (cont) {
            cont.querySelectorAll('input.campo-llenar').forEach(function (el, i) {
                datos.push(el.type === 'checkbox'
                    ? { idx: i, type: 'checkbox', value: el.checked }
                    : { idx: i, type: 'text', value: el.value });
            });
        }
        document.getElementById('camposInput').value = JSON.stringify(datos);
        document.getElementById('firmaInput').value = canvas.toDataURL('image/png');
        return true;
    };
})();
</script>
</body>
</html>
