/*
  MIGRACION: Ampliar valores permitidos de week_bucket
  Ejecutar en la base de datos GestionTalento (SQL Server).
  Solo es necesario si la tabla ya fue creada con el constraint anterior.
*/

-- 1. Eliminar constraint anterior (solo acepta ACTUAL / PROXIMA)
IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_tgt_week_bucket'
      AND parent_object_id = OBJECT_ID('dbo.tareas_gestion_talento')
)
BEGIN
    ALTER TABLE dbo.tareas_gestion_talento
    DROP CONSTRAINT CK_tgt_week_bucket;
END;
GO

-- 2. Agregar constraint ampliado (VENCIDA y FUTURA ahora son valores validos)
IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_tgt_week_bucket'
      AND parent_object_id = OBJECT_ID('dbo.tareas_gestion_talento')
)
BEGIN
    ALTER TABLE dbo.tareas_gestion_talento
    ADD CONSTRAINT CK_tgt_week_bucket
        CHECK (week_bucket IN ('ACTUAL', 'PROXIMA', 'VENCIDA', 'FUTURA'));
END;
GO
