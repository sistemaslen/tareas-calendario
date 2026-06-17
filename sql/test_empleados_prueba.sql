/*
  SCRIPT DE PRUEBA: Insertar empleado de prueba para validar generar_tareas_imss.php
  Ejecutar en la base de datos Stratix (SQL Server).

  Hoy: 2026-04-30 (miercoles)
  Ventana calculada:
    ACTUAL  → semana 2026-04-27 a 2026-05-03  (due_date: ingreso el 2026-04-23 o antes)
    PROXIMA → semana 2026-05-04 a 2026-05-10  (due_date: ingreso HOY 2026-04-30)
    VENCIDA → due_date antes de hoy            (ingreso antes del 2026-04-23)

  El script inserta 3 empleados de prueba, uno por cada bucket relevante.
  Al final incluye el bloque de LIMPIEZA para borrarlos.
*/

-- ============================================================
-- INSERCION DE EMPLEADOS DE PRUEBA
-- ============================================================

-- CASO 1: Ingreso HOY → due_date = 2026-05-07 → bucket PROXIMA
IF NOT EXISTS (SELECT 1 FROM employees WHERE employee_number = 'TEST-001')
BEGIN
    INSERT INTO employees (employee_number, status)
    VALUES ('TEST-001', 'ALTA');

    INSERT INTO employee_job_history (employee_id, hire_date)
    SELECT id, '2026-04-30'
    FROM employees WHERE employee_number = 'TEST-001';

    INSERT INTO employee_general_info (employee_id, full_name)
    SELECT id, 'Prueba Proxima Uno'
    FROM employees WHERE employee_number = 'TEST-001';
END;

-- CASO 2: Ingreso 2026-04-23 → due_date = 2026-04-30 (HOY) → bucket ACTUAL / urgency HOY
IF NOT EXISTS (SELECT 1 FROM employees WHERE employee_number = 'TEST-002')
BEGIN
    INSERT INTO employees (employee_number, status)
    VALUES ('TEST-002', 'ALTA');

    INSERT INTO employee_job_history (employee_id, hire_date)
    SELECT id, '2026-04-23'
    FROM employees WHERE employee_number = 'TEST-002';

    INSERT INTO employee_general_info (employee_id, full_name)
    SELECT id, 'Prueba Vence Hoy'
    FROM employees WHERE employee_number = 'TEST-002';
END;

-- CASO 3: Ingreso 2026-04-15 → due_date = 2026-04-22 → bucket VENCIDA
IF NOT EXISTS (SELECT 1 FROM employees WHERE employee_number = 'TEST-003')
BEGIN
    INSERT INTO employees (employee_number, status)
    VALUES ('TEST-003', 'ALTA');

    INSERT INTO employee_job_history (employee_id, hire_date)
    SELECT id, '2026-04-15'
    FROM employees WHERE employee_number = 'TEST-003';

    INSERT INTO employee_general_info (employee_id, full_name)
    SELECT id, 'Prueba Vencida Tres'
    FROM employees WHERE employee_number = 'TEST-003';
END;

-- Verificar inserciones
SELECT
    e.id,
    e.employee_number,
    e.status,
    jh.hire_date,
    gi.full_name,
    'Ejecuta generar_tareas_imss.php y revisa logs/tareas_imss.log' AS siguiente_paso
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT  JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE e.employee_number IN ('TEST-001', 'TEST-002', 'TEST-003');
GO


-- ============================================================
-- LIMPIEZA: ejecutar DESPUES de validar el script PHP
-- ============================================================
/*
DELETE FROM employee_general_info
WHERE employee_id IN (SELECT id FROM employees WHERE employee_number IN ('TEST-001','TEST-002','TEST-003'));

DELETE FROM employee_job_history
WHERE employee_id IN (SELECT id FROM employees WHERE employee_number IN ('TEST-001','TEST-002','TEST-003'));

DELETE FROM employees
WHERE employee_number IN ('TEST-001','TEST-002','TEST-003');

-- Limpiar tareas generadas en GestionTalento
DELETE FROM GestionTalento.dbo.tareas_gestion_talento_historial
WHERE tarea_id IN (
    SELECT id FROM GestionTalento.dbo.tareas_gestion_talento
    WHERE employee_number IN ('TEST-001','TEST-002','TEST-003')
);

DELETE FROM GestionTalento.dbo.tareas_gestion_talento
WHERE employee_number IN ('TEST-001','TEST-002','TEST-003');
*/
