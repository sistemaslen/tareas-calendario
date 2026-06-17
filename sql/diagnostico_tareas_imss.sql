/*
  DIAGNOSTICO: Tareas que deberia generar generar_tareas_imss.php hoy.

  Ejecutar en la base de datos Stratix (SQL Server).
  Ajusta la fecha en @hoy si quieres simular otra fecha.
*/

DECLARE @hoy DATE = CAST(GETDATE() AS DATE);

-- Calcular inicio de la semana actual (lunes)
DECLARE @diasDesdelunes INT = (DATEPART(WEEKDAY, @hoy) + 5) % 7;
DECLARE @semanaActualStart DATE = DATEADD(DAY, -@diasDesdelunes, @hoy);
DECLARE @semanaActualEnd DATE = DATEADD(DAY, 6, @semanaActualStart);
DECLARE @semanaSigStart DATE = DATEADD(DAY, 7, @semanaActualStart);
DECLARE @semanaSigEnd DATE = DATEADD(DAY, 13, @semanaActualStart);

SELECT
    @hoy AS hoy,
    @semanaActualStart AS semana_actual_inicio,
    @semanaActualEnd AS semana_actual_fin,
    @semanaSigStart AS semana_sig_inicio,
    @semanaSigEnd AS semana_sig_fin;

/*
  Funcion inline: sumar N dias habiles a una fecha.
  Equivale a la funcion PHP addBusinessDays().
  Aqui lo hacemos con un tally de hasta 30 dias para no usar cursores.
*/
;WITH tally AS (
    SELECT TOP 30 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n
    FROM sys.objects
),
empleados_base AS (
    SELECT
        e.id AS employee_id,
        e.employee_number,
        COALESCE(gi.full_name, '') AS full_name,
        CAST(jh.hire_date AS DATE) AS hire_date
    FROM employees e
    INNER JOIN employee_job_history jh ON jh.employee_id = e.id
    LEFT JOIN  employee_general_info gi ON gi.employee_id = e.id
    WHERE e.status = 'ALTA'
      AND jh.hire_date IS NOT NULL
),
-- Calcular due_date = hire_date + 5 dias habiles
dias_habiles AS (
    SELECT
        eb.employee_id,
        eb.employee_number,
        eb.full_name,
        eb.hire_date,
        -- Cuenta los 5 primeros dias habiles a partir del dia siguiente
        MAX(CASE WHEN rn = 5 THEN candidate_date END) AS due_date
    FROM (
        SELECT
            eb.employee_id,
            eb.employee_number,
            eb.full_name,
            eb.hire_date,
            DATEADD(DAY, t.n, eb.hire_date) AS candidate_date,
            -- Solo contar si no es sabado (7) ni domingo (1) segun DATEPART WEEKDAY (domingo=1)
            SUM(
                CASE
                    WHEN DATEPART(WEEKDAY, DATEADD(DAY, t.n, eb.hire_date)) NOT IN (1,7) THEN 1
                    ELSE 0
                END
            ) OVER (
                PARTITION BY eb.employee_id, eb.hire_date
                ORDER BY t.n
                ROWS UNBOUNDED PRECEDING
            ) AS rn
        FROM empleados_base eb
        CROSS JOIN tally t
        WHERE DATEPART(WEEKDAY, DATEADD(DAY, t.n, eb.hire_date)) NOT IN (1,7)
    ) sub
    GROUP BY employee_id, employee_number, full_name, hire_date
)
SELECT
    dh.employee_id,
    dh.employee_number,
    dh.full_name AS employee_name,
    dh.hire_date,
    dh.due_date,
    CASE
        WHEN dh.due_date BETWEEN @semanaActualStart AND @semanaActualEnd THEN 'ACTUAL'
        WHEN dh.due_date BETWEEN @semanaSigStart    AND @semanaSigEnd    THEN 'PROXIMA'
        ELSE 'FUERA_DE_VENTANA'
    END AS bucket,
    -- Ver si ya existe tarea en GestionTalento
    (SELECT TOP 1 t.status
     FROM GestionTalento.dbo.tareas_gestion_talento t
     WHERE t.task_code = 'ALTA_IMSS'
       AND t.employee_id = dh.employee_id
       AND t.hire_date = dh.hire_date
    ) AS tarea_existente_status
FROM dias_habiles dh
ORDER BY
    bucket ASC,
    dh.due_date ASC;
