-- Run against the API database in Navicat before deploying organization deletion.
-- Additive and safe to run again. Does not delete or modify any existing account.
SET @fines_org_delete_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN organization_deleted_at DATETIME NULL',
        'SELECT 1 AS organization_deletion_schema_ready')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'organization_deleted_at'
);
PREPARE fines_org_delete_stmt FROM @fines_org_delete_ddl;
EXECUTE fines_org_delete_stmt;
DEALLOCATE PREPARE fines_org_delete_stmt;
