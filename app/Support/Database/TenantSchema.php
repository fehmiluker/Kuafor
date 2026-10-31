<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Migration helpers that enforce tenant consistency at the database level.
 *
 * A plain FK on `branch_id` only guarantees the branch exists, not that it
 * belongs to the same tenant. A composite FK (tenant_id, branch_id) ->
 * branches(tenant_id, id) makes cross-tenant references impossible.
 */
final class TenantSchema
{
    /**
     * Composite FK (tenant_id, $column) -> $table(tenant_id, id).
     * Referenced table must have UNIQUE(tenant_id, id).
     */
    public static function foreign(Blueprint $table, string $column, string $references, string $onDelete = 'restrict'): void
    {
        $table->foreign(['tenant_id', $column], "{$table->getTable()}_{$column}_tenant_fk")
            ->references(['tenant_id', 'id'])
            ->on($references)
            ->onDelete($onDelete);
    }

    /** Adds a MySQL 8 CHECK constraint (enforced since 8.0.16). Skipped on SQLite. */
    public static function check(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /** CHECK that a string column is one of the given values. */
    public static function checkIn(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(',', array_map(fn ($v) => "'".str_replace("'", "''", $v)."'", $values));
        $expr = "`{$column}` IN ({$list})";
        if ($nullable) {
            $expr = "`{$column}` IS NULL OR {$expr}";
        }
        self::check($table, "chk_{$table}_{$column}", $expr);
    }
}
