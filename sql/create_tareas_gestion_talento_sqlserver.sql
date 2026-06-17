/*
   SQL Server version (T-SQL)
   Reemplaza el script MySQL create_tareas_gestion_talento.sql
*/

SET NOCOUNT ON;

IF OBJECT_ID('dbo.tareas_gestion_talento', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tareas_gestion_talento (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        task_code VARCHAR(80) NOT NULL,
        title VARCHAR(200) NOT NULL,
        description VARCHAR(MAX) NULL,

        employee_id BIGINT NOT NULL,
        employee_number VARCHAR(50) NOT NULL,
        employee_name VARCHAR(300) NULL,

        hire_date DATE NOT NULL,
        due_date DATE NOT NULL,
        week_bucket VARCHAR(10) NOT NULL,

        owner_area VARCHAR(120) NOT NULL CONSTRAINT DF_tgt_owner_area DEFAULT ('NOMINAS'),
        priority VARCHAR(10) NOT NULL CONSTRAINT DF_tgt_priority DEFAULT ('ALTA'),
        status VARCHAR(15) NOT NULL CONSTRAINT DF_tgt_status DEFAULT ('PENDIENTE'),
        status_changed_at DATETIME2(0) NULL,
        status_changed_by_user VARCHAR(150) NULL,
        status_changed_by_email VARCHAR(180) NULL,

        source VARCHAR(80) NOT NULL CONSTRAINT DF_tgt_source DEFAULT ('AUTO_IMSS'),
        metadata_json NVARCHAR(MAX) NULL,

        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_tgt_created_at DEFAULT (GETDATE()),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_tgt_updated_at DEFAULT (GETDATE()),

        CONSTRAINT UQ_tgt_task_employee_hire UNIQUE (task_code, employee_id, hire_date),
        CONSTRAINT CK_tgt_week_bucket CHECK (week_bucket IN ('ACTUAL', 'PROXIMA', 'VENCIDA', 'FUTURA')),
        CONSTRAINT CK_tgt_priority CHECK (priority IN ('ALTA', 'MEDIA', 'BAJA')),
        CONSTRAINT CK_tgt_status CHECK (status IN ('PENDIENTE', 'EN_PROCESO', 'COMPLETADA', 'CANCELADA')),
        CONSTRAINT CK_tgt_metadata_json CHECK (metadata_json IS NULL OR ISJSON(metadata_json) = 1)
    );

    CREATE INDEX IX_tgt_due_date_status ON dbo.tareas_gestion_talento (due_date, status);
    CREATE INDEX IX_tgt_week_bucket ON dbo.tareas_gestion_talento (week_bucket);
    CREATE INDEX IX_tgt_employee_number ON dbo.tareas_gestion_talento (employee_number);
END;
GO

IF OBJECT_ID('dbo.tareas_notificacion_destinos', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tareas_notificacion_destinos (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        task_code VARCHAR(80) NOT NULL,
        destination_type VARCHAR(10) NOT NULL,
        destination_value VARCHAR(180) NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_tnd_is_active DEFAULT (1),
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_tnd_created_at DEFAULT (GETDATE()),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_tnd_updated_at DEFAULT (GETDATE()),

        CONSTRAINT UQ_tnd_destino UNIQUE (task_code, destination_type, destination_value),
        CONSTRAINT CK_tnd_destination_type CHECK (destination_type IN ('ROLE', 'EMAIL'))
    );

    CREATE INDEX IX_tnd_task_code ON dbo.tareas_notificacion_destinos (task_code);
END;
GO

IF OBJECT_ID('dbo.tareas_gestion_talento_historial', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tareas_gestion_talento_historial (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        tarea_id BIGINT NOT NULL,
        old_status VARCHAR(15) NULL,
        new_status VARCHAR(15) NOT NULL,
        changed_by_user VARCHAR(150) NULL,
        changed_by_email VARCHAR(180) NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_tgth_created_at DEFAULT (GETDATE()),

        CONSTRAINT CK_tgth_old_status CHECK (old_status IS NULL OR old_status IN ('PENDIENTE', 'EN_PROCESO', 'COMPLETADA', 'CANCELADA')),
        CONSTRAINT CK_tgth_new_status CHECK (new_status IN ('PENDIENTE', 'EN_PROCESO', 'COMPLETADA', 'CANCELADA')),
        CONSTRAINT FK_tgth_tarea FOREIGN KEY (tarea_id)
            REFERENCES dbo.tareas_gestion_talento(id)
            ON DELETE CASCADE
    );

    CREATE INDEX IX_tgth_tarea_id ON dbo.tareas_gestion_talento_historial (tarea_id);
END;
GO

/* Seed de destinos por rol */
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tareas_notificacion_destinos
    WHERE task_code = 'ALTA_IMSS'
      AND destination_type = 'ROLE'
      AND destination_value = 'RH'
)
BEGIN
    INSERT INTO dbo.tareas_notificacion_destinos (task_code, destination_type, destination_value, is_active)
    VALUES ('ALTA_IMSS', 'ROLE', 'RH', 1);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tareas_notificacion_destinos
    WHERE task_code = 'ALTA_IMSS'
      AND destination_type = 'ROLE'
      AND destination_value = 'ADMIN'
)
BEGIN
    INSERT INTO dbo.tareas_notificacion_destinos (task_code, destination_type, destination_value, is_active)
    VALUES ('ALTA_IMSS', 'ROLE', 'ADMIN', 1);
END;
GO
