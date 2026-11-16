<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class OrganizationAccountSchema
{
    public static function deletion_available($database): bool
    {
        // Inspect result metadata without selecting account rows or querying a
        // column that may not exist yet during a rolling deployment.
        $columns = $database->raw('SELECT * FROM users LIMIT 0');
        for ($index = 0; $index < $columns->columnCount(); $index++) {
            $column = $columns->getColumnMeta($index);
            if (($column['name'] ?? '') === 'organization_deleted_at') return true;
        }
        return false;
    }
}
