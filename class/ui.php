<?php
/**
 * class/ui.php — Utilidades de interfaz.
 *  avatar_iniciales(): círculo con las iniciales de la persona (sin imágenes externas ni fotos de ejemplo).
 */
if (!function_exists('avatar_iniciales')) {
    function avatar_iniciales($nombres, $apellidos, $tam = 45) {
        $ini = '';
        foreach ([$nombres, $apellidos] as $t) {
            $t = trim((string)$t);
            if ($t !== '') $ini .= mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8');
        }
        if ($ini === '') $ini = '?';
        // Color estable por persona, dentro de la paleta de la marca (teal) y sus complementarios suaves.
        $paleta = ['#2a9d8f', '#3a7ca5', '#6a8caf', '#5b8e7d', '#7c6bb0', '#c07c5a', '#4a8f9c', '#8a7f3a'];
        $color = $paleta[abs(crc32((string)$nombres . '|' . (string)$apellidos)) % count($paleta)];
        $tam = (int)$tam;
        return '<span class="rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 text-white fw-semibold" '
             . 'style="width:' . $tam . 'px;height:' . $tam . 'px;background:' . $color . ';font-size:' . round($tam * 0.38) . 'px;" aria-hidden="true">'
             . htmlspecialchars($ini, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
