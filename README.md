# Talent Tasks Sync

Automatizacion de tareas operativas de Gestion de Talento en PHP (estructura similar a biotime-sync).

## Estructura

- `config/sqlserver.php`: conexion SQL Server (DB por defecto `GestionTalento`).
- `config/email.php`: configuracion SMTP para envio de notificaciones.
- `scripts/generar_tareas_alta_imss.php`: genera tareas de Alta IMSS para Nominas.
- `scripts/generar_tareas_baja_imss.php`: genera tareas de Baja IMSS para Nominas.
- `scripts/generar_tareas_evaluaciones.php`: genera tareas de primera y segunda evaluacion.
- `scripts/generar_tareas_firma_contrato.php`: genera tareas de firma de contrato definitivo.
- `scripts/generar_tareas_onboarding_base.php`: genera tareas de onboarding para personal BASE.
- `scripts/generar_tareas_primer_contrato.php`: genera tareas de primer contrato.
- `scripts/generar_tareas_segundo_contrato.php`: genera tareas de segundo contrato.
- `scripts/mailer.php`: helper SMTP con PHPMailer.
- `sql/create_tareas_gestion_talento_sqlserver.sql`: script de creacion de tablas en SQL Server.
- `logs/*.log`: bitacoras de ejecucion por tipo de tarea.

## Regla implementada

**Alta del IMSS** se entrega a Nominas dentro de los primeros **5 dias habiles** a partir de la fecha de ingreso.

El script:
1. Consulta empleados `ALTA` con `hire_date`.
2. Calcula fecha limite (ingreso + 5 dias habiles, sin sabado/domingo).
3. Considera solo tareas que vencen en:
   - Semana actual (`ACTUAL`)
   - Semana siguiente (`PROXIMA`)
4. Inserta/actualiza tareas en `tareas_gestion_talento`.
5. Imprime salida separada en consola por seccion.

## Estatus y auditoria

La tabla guarda estatus de la tarea y ultimo usuario que modifico:
- `status` (`PENDIENTE`, `EN_PROCESO`, `COMPLETADA`, `CANCELADA`)
- `status_changed_at`
- `status_changed_by_user`
- `status_changed_by_email`

Ademas, cada cambio queda en `tareas_gestion_talento_historial`.

## Destinatarios de notificacion

La tabla `tareas_notificacion_destinos` define a quien se notifica por tipo de tarea:
- Tipo `ROLE`: por ejemplo `RH` o `ADMIN`.
- Tipo `EMAIL`: correos puntuales.

Para `ALTA_IMSS` se insertan por defecto los roles `RH` y `ADMIN`.

Importante para evaluaciones:
- `scripts/notificar_tareas_evaluaciones.php` SI toma destinatarios desde `tareas_notificacion_destinos` (roles/emails).
- `scripts/notificar_jefe_evaluacion.php` NO usa `tareas_notificacion_destinos`; toma el jefe directo desde `employees.manager_id` y su correo desde `employee_general_info.corporate_email`.
- El adjunto de evaluacion se toma desde `employee_generated_documents` filtrando por nombre/template de evaluacion de desempeno y priorizando categoria `alta` (kit-alta).

## Variables opcionales de entorno

- `GT_DB_HOST`
- `GT_DB_PORT`
- `GT_DB_USER`
- `GT_DB_PASSWORD`
- `GT_DB_NAME`
- `GT_SMTP_HOST`
- `GT_SMTP_PORT`
- `GT_SMTP_USER`
- `GT_SMTP_PASSWORD`
- `GT_SMTP_FROM_EMAIL`
- `GT_SMTP_FROM_NAME`
- `GT_SMTP_SECURE` (`ssl` o `tls`)

## Dependencia de correo

Instalar PHPMailer en la carpeta del proyecto:

```bash
composer require phpmailer/phpmailer
```

## Ejecucion

```bash
php scripts/generar_tareas_alta_imss.php
php scripts/generar_tareas_baja_imss.php
php scripts/generar_tareas_evaluaciones.php
php scripts/generar_tareas_firma_contrato.php
php scripts/generar_tareas_onboarding_base.php
php scripts/generar_tareas_primer_contrato.php
php scripts/generar_tareas_segundo_contrato.php
```

## Validacion de requisitos (scripts `generar_*`)

Requisitos evaluados:
- Conexion SQL Server via `config/sqlserver.php`.
- Extension `sqlsrv` habilitada en PHP.
- Tablas y columnas requeridas en `GestionTalento` (`employees`, `employee_job_history`, `employee_general_info`, `tareas_gestion_talento`).
- Carpeta `logs/` con permisos de escritura.
- Ejecucion sin argumentos (todos los scripts se corren con `php scripts/<archivo>.php`).

Resultado:
- `generar_tareas_alta_imss.php`: cumple.
- `generar_tareas_baja_imss.php`: cumple.
- `generar_tareas_evaluaciones.php`: cumple.
- `generar_tareas_firma_contrato.php`: cumple.
- `generar_tareas_onboarding_base.php`: cumple.
- `generar_tareas_segundo_contrato.php`: cumple.
- `generar_tareas_primer_contrato.php`: cumple.

## Reset total de datos (SQL Server)

Script destructivo para vaciar las tablas del modulo y reiniciar contadores (`IDENTITY`) a 1:

```sql
BEGIN TRY
   BEGIN TRAN;

   DELETE FROM dbo.tareas_gestion_talento_historial;
   DELETE FROM dbo.tareas_gestion_talento;
   DELETE FROM dbo.tareas_notificacion_destinos;

   DBCC CHECKIDENT ('dbo.tareas_gestion_talento_historial', RESEED, 0);
   DBCC CHECKIDENT ('dbo.tareas_gestion_talento', RESEED, 0);
   DBCC CHECKIDENT ('dbo.tareas_notificacion_destinos', RESEED, 0);

   COMMIT TRAN;
END TRY
BEGIN CATCH
   IF @@TRANCOUNT > 0 ROLLBACK TRAN;
   THROW;
END CATCH;
```

Nota: este reset tambien elimina destinos de notificacion; si los necesitas de nuevo, vuelve a ejecutar el seed del archivo `sql/create_tareas_gestion_talento_sqlserver.sql`.

## Reset de tareas (sin borrar destinos)

Si quieres limpiar tareas e historial pero conservar `tareas_notificacion_destinos`:

```sql
BEGIN TRY
   BEGIN TRAN;

   DELETE FROM dbo.tareas_gestion_talento_historial;
   DELETE FROM dbo.tareas_gestion_talento;

   DBCC CHECKIDENT ('dbo.tareas_gestion_talento_historial', RESEED, 0);
   DBCC CHECKIDENT ('dbo.tareas_gestion_talento', RESEED, 0);

   COMMIT TRAN;
END TRY
BEGIN CATCH
   IF @@TRANCOUNT > 0 ROLLBACK TRAN;
   THROW;
END CATCH;
```

## Flujo recomendado (solo notificacion a jefe directo)

Si ya no se enviaran correos por roles/globales para evaluaciones:
- Ejecutar generacion: `php scripts/generar_tareas_evaluaciones.php`
- Ejecutar notificacion a jefe directo: `php scripts/notificar_jefe_evaluacion.php`
- No ejecutar `scripts/notificar_tareas_evaluaciones.php`.

## Calendario de gestion de talento

Los endpoints interactivos del calendario (listar y actualizar estatus) viven en Django:

- `GET /api/hr-tasks/calendario/?month=4&year=2026`
- `PATCH /api/hr-tasks/{id}/status/`

Esta carpeta (`talent-tasks-sync`) queda solo para procesos batch/cron:

- Generacion automatica de tareas (`generar_tareas_*.php`)
- Notificaciones por correo (`notificar_tareas_*.php`)
