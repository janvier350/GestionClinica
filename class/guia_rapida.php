<?php
/**
 * class/guia_rapida.php — Guía visual del flujo de Atención rápida (plegable; recuerda si se cerró).
 * guia_rapida_html($en, $rol, $pasoActual): resalta los pasos que le corresponden al rol y marca el paso de la pantalla actual.
 *   1 Ingreso (recepción) → 2 Triaje (enfermería, opcional) → 3 Atención (doctor o enfermera) → 4 Reporte → 5 Cobro (recepción)
 */
function guia_rapida_html($en, $rol, $pasoActual = 0) {
    $rol = strtoupper((string)$rol);
    $pasos = [
        1 => ['bi-person-plus',  $en ? 'Check-in'   : 'Ingreso',  $en ? 'Reception' : 'Recepción',        $en ? 'ID number + service, then “Send to Triage” or “Pass to Care”.' : 'Cédula + servicio; luego «Enviar a Triaje» o «Pasar a Atención».', ['ASISTENTE','SISTEMA']],
        2 => ['bi-heart-pulse',  $en ? 'Triage'     : 'Triaje',   $en ? 'Nursing (optional)' : 'Enfermería (opcional)', $en ? 'Vital signs and reason. The patient then moves to the waiting room.' : 'Signos vitales y motivo. Luego el paciente pasa a la sala de espera.', ['ENFERMERA','ASISTENTE','SISTEMA']],
        3 => ['bi-people',       $en ? 'Care'       : 'Atención', $en ? 'Doctor / Nurse' : 'Doctor / Enfermera', $en ? '“Waiting room” → Attend. IV therapy goes to the “IV therapy” desk.' : '«Sala de espera» → Atender. La sueroterapia va a la mesa de «Sueros».', ['DOCTOR','ENFERMERA','SISTEMA']],
        4 => ['bi-journal-medical', $en ? 'Report'  : 'Reporte',  $en ? 'Professional' : 'Profesional',     $en ? 'Save the note/record, or apply the IV. The patient moves to “To collect”.' : 'Guarda la nota o registro, o aplica el suero. El paciente pasa a «Por cobrar».', ['DOCTOR','ENFERMERA','SISTEMA']],
        5 => ['bi-cash-coin',    $en ? 'Payment'    : 'Cobro',    $en ? 'Reception' : 'Recepción',        $en ? 'Amount + payment method. It creates the invoice and ends the visit.' : 'Monto + forma de pago. Crea la factura y finaliza la visita.', ['ASISTENTE','SISTEMA']],
    ];
    ob_start(); ?>
<details class="card shadow-sm mb-3 guia-rapida" id="guiaRapida" open>
  <summary class="card-header py-2 d-flex justify-content-between align-items-center" style="cursor:pointer;list-style:none;">
    <span><i class="bi bi-signpost-split text-primary"></i> <b><?php echo $en ? 'Quick guide: how a visit flows' : 'Guía rápida: cómo fluye una visita'; ?></b></span>
    <span class="small text-muted"><?php echo $en ? 'show / hide' : 'mostrar / ocultar'; ?></span>
  </summary>
  <div class="card-body py-3">
    <div class="row g-2 align-items-start">
    <?php foreach ($pasos as $n => [$ico, $tit, $quien, $desc, $roles]):
        $mio = in_array($rol, $roles, true); $aqui = ($n === (int)$pasoActual); ?>
      <div class="col">
        <div class="rounded p-2 border <?php echo $aqui ? 'border-primary border-2' : ''; ?> <?php echo $mio ? 'bg-light' : ''; ?>" style="<?php echo $mio ? '' : 'opacity:.62'; ?>">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge rounded-pill <?php echo $aqui ? 'bg-primary' : 'bg-secondary'; ?>"><?php echo $n; ?></span>
            <i class="bi <?php echo $ico; ?>"></i> <b><?php echo htmlspecialchars($tit, ENT_QUOTES, 'UTF-8'); ?></b>
          </div>
          <div class="small fw-semibold text-primary"><?php echo htmlspecialchars($quien, ENT_QUOTES, 'UTF-8'); ?><?php echo $mio ? ' · <span class="text-success">'.($en ? 'your step' : 'tu paso').'</span>' : ''; ?></div>
          <div class="small text-muted"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <div class="small text-muted mt-2"><i class="bi bi-droplet-half"></i> <b><?php echo $en ? 'IV therapy' : 'Sueroterapia'; ?>:</b>
      <?php echo $en ? 'Check-in → Triage → the nurse takes it in “Waiting room” → “IV therapy” desk (order, apply, notes) → Payment.'
                     : 'Ingreso → Triaje → la enfermera la toma en «Sala de espera» → mesa de «Sueros» (orden, aplicación, notas) → Cobro.'; ?>
      &nbsp;<i class="bi bi-lightbulb"></i> <?php echo $en ? 'Already has a doctor’s order? Use “Pass to Care” and skip Triage.' : '¿Ya trae indicación del doctor? Usa «Pasar a Atención» y salta el Triaje.'; ?></div>
  </div>
</details>
<script>
(function(){
  var d = document.getElementById('guiaRapida'); if (!d) return;
  try { if (localStorage.getItem('guiaRapidaCerrada') === '1') d.removeAttribute('open'); } catch(e) {}
  d.addEventListener('toggle', function(){ try { localStorage.setItem('guiaRapidaCerrada', d.open ? '0' : '1'); } catch(e) {} });
})();
</script>
<?php return ob_get_clean();
}
