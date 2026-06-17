/*
  LIMPIEZA ONE-TIME: Tareas cuya "ancla" (columna hire_date en tareas_gestion_talento)
  cambio de definicion al corregir la duracion de las tareas auto-generadas.

  Tareas afectadas:
    - PRIMERA_EVALUACION  (ancla: fecha de ingreso -> fecha de 1ra evaluacion)
    - SEGUNDA_EVALUACION  (ancla: fecha de 1ra evaluacion -> fecha de 2da evaluacion)
    - SEGUNDO_CONTRATO    (ancla: fin de 1er contrato -> fin de 2do contrato)
    - FIRMA_CONTRATO      (ancla: fecha calculada -> determined_indeterminate_contract_date)
    - ONBOARDING_BASE     (ancla: ingreso+6 -> onboarding_date)

  Al cambiar el ancla, la clave de upsert (task_code, employee_id, hire_date) ya no
  coincide con las filas existentes, dejandolas como duplicados huerfanos con el
  rango de dias incorrecto. Este script las elimina para que main_generar.py las
  regenere con el ancla correcta.

  El FOREIGN KEY hacia tareas_gestion_talento_historial tiene ON DELETE CASCADE,
  por lo que el historial asociado se limpia automaticamente.

  Ejecutar en la base de datos GestionTalento (SQL Server) ANTES de correr:
    python main_generar.py --only EVALUACIONES,SEGUNDO_CONTRATO,FIRMA_CONTRATO,ONBOARDING_BASE
*/

DELETE FROM dbo.tareas_gestion_talento
WHERE task_code IN (
    'PRIMERA_EVALUACION',
    'SEGUNDA_EVALUACION',
    'SEGUNDO_CONTRATO',
    'FIRMA_CONTRATO',
    'ONBOARDING_BASE'
);
GO
