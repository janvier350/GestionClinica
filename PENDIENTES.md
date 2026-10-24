# Pendientes del proyecto L'Chaim

Lista viva para no perder temas acordados. (No se publica: `*.md` está excluido del despliegue.)

## Recordar al cliente / al desarrollador
- [ ] **RECORDAR AL DESARROLLADOR (propuesta futura): consulta de cédula con autocompletar nombres.** La app que usa la clínica (HC Médica) tiene
      «Número de documento + Consultar» que trae el nombre del paciente. Hoy nuestra «Atención rápida» solo busca en la base propia y valida el dígito
      verificador de la cédula ecuatoriana; los nombres de un paciente nuevo se escriben a mano. Proponer a la clínica una integración con una fuente
      de datos de identificación (a definir: proveedor/consulta autorizada, costo, y cumplimiento de la ley de protección de datos) como mejora de pago aparte.
- [ ] **Actualizar las guías de uso (`ayuda.php`)** después del *siguiente avance* que solicite la clínica. Incluir: **atención rápida (ingreso, sala de espera, cobro)**, triaje (con y sin cita),
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
- [ ] Plantillas de **documentos para firmar** (tabla `documentos`: textos HIPAA/SRoss): ejecutar `revisar_marca.php` para ver dónde están y, si hace falta, desactivarlas;
      crear las de L'Chaim con `migrar_documentos_lchaim.php` (textos base en español: **revisar con la clínica / asesor legal** antes de usarlos).
- [ ] Catálogos heredados: tipos de consulta, *bill items*, seguros, plantillas de informe (nutrición). Desactivar lo que no aplique.
- [ ] Textos «Sross Nutritions / SROSS» que queden en pantallas secundarias (`revisar_marca.php` los lista; la clínica los corrige a mano).

## Mejoras posibles
- [ ] Logo horizontal (con texto) para la cabecera de la app; hoy se usa la ficha cuadrada.
- [ ] Colores de marca (#82C5CC) en botones y menús (hoy solo login y logo).
- [ ] Fecha de nacimiento obligatoria al crear paciente rápido (triaje sin cita / historias escaneadas): evaluar hacerla opcional.
- [ ] Resultados de exámenes con archivo adjunto (PDF) usando el módulo de documentos del paciente.
- [ ] Respaldos: confirmar los respaldos de cPanel antes de prometerlos en la propuesta.

## Despliegue
- [x] `.cpanel.yml` publica en `/home/overcloc/public_html/GestionClinica/` (la URL actual del sitio). Si cambia la carpeta web, editar solo `DEPLOYPATH`.

## Atención rápida (nuevo)
- [ ] Cargar el **valor de cada servicio** en Tipos de consulta (campo «Valor») y marcar «Lo atiende» (Sueroterapia → Enfermera).
- [ ] Activar el módulo «Atención rápida» y dar el rol ASISTENTE a recepción (ya tiene ingresar y cobrar por defecto).
- [ ] Confirmar con la clínica: pantalla de ingreso (la imagen de HC Médica), cobro de varios servicios, descuentos, y si quieren reporte de caja diario.
