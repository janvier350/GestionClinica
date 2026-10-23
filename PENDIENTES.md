# Pendientes del proyecto L'Chaim

Lista viva para no perder temas acordados. (No se publica: `*.md` está excluido del despliegue.)

## Recordar al cliente / al desarrollador
- [ ] **Actualizar las guías de uso (`ayuda.php`)** después del *siguiente avance* que solicite la clínica. Incluir: triaje (con y sin cita),
      sueroterapia por enfermería (órdenes, aplicación, notas de evolución, historial), derivación entre profesionales, exámenes,
      historias escaneadas, registro de atención y hoja del paciente, localidades, correo y WhatsApp, módulos. Quitar lo de nutrición
      y los textos de la clínica anterior (p. ej. `att.help.req.https`).
- [ ] Corregir el nombre en la **propuesta comercial**: la marca es **L'Chaim** (salud & vida), no «L'CHAIN».

## Seguridad (revisar el propietario)
- [ ] Cambiar la contraseña de la base `overcloc_GestionClinica` (estuvo escrita en un commit a `main`).
- [ ] Revisar la pestaña **Actions** de GitHub (el commit a `main` pudo disparar el despliegue FTP hacia HealthSchedule).
- [ ] Mantener el repositorio **privado** (el historial contiene credenciales antiguas de HealthSchedule y un buzón de correo de SRoss).
- [ ] Cambiar la clave de los usuarios SISTEMA heredados y confirmar quiénes son.
- [ ] Verificar que `documentos_pacientes/` responda *Forbidden* por URL directa.

## Contenido heredado de la clínica anterior (revisar con la clínica)
- [ ] Plantillas de **documentos para firmar** (tabla `documentos`: textos HIPAA/SRoss) → reemplazar por las de L'Chaim o apagar el módulo.
- [ ] Catálogos heredados: tipos de consulta, *bill items*, seguros, plantillas de informe (nutrición). Desactivar lo que no aplique.
- [ ] Textos «Sross Nutritions / SROSS» que queden en pantallas secundarias.

## Mejoras posibles
- [ ] Logo horizontal (con texto) para la cabecera de la app; hoy se usa la ficha cuadrada.
- [ ] Colores de marca (#82C5CC) en botones y menús (hoy solo login y logo).
- [ ] Fecha de nacimiento obligatoria al crear paciente rápido (triaje sin cita / historias escaneadas): evaluar hacerla opcional.
- [ ] Resultados de exámenes con archivo adjunto (PDF) usando el módulo de documentos del paciente.
- [ ] Respaldos: confirmar los respaldos de cPanel antes de prometerlos en la propuesta.
