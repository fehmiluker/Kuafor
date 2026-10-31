<?php

namespace App\Services\Booking;

use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Serialises bookings per (tenant, resource, local day) in MySQL.
 *
 * MySQL has no exclusion constraints, and `SELECT ... FOR UPDATE` on existing
 * appointments does not stop two transactions from both seeing an EMPTY range
 * and inserting overlapping rows. Instead every booking transaction:
 *
 *   1. ensures one row per touched local day exists in resource_day_locks
 *      (INSERT IGNORE on the unique key — idempotent),
 *   2. locks those rows with SELECT ... FOR UPDATE in a fixed global order
 *      (resource_type, resource_id, local_date) to avoid lock-order deadlocks,
 *   3. only then re-reads appointments/holds and writes.
 *
 * Any concurrent booking for the same employee and day blocks at step 2 until
 * the first commits, then sees its row. Deadlocks/lock-wait timeouts are retried
 * by the surrounding DB::transaction($cb, attempts).
 */
final class BookingLockService
{
    public const RESOURCE_EMPLOYEE = 'employee';

    /**
     * @param  list<array{employee_id:int, start:CarbonInterface, end:CarbonInterface}>  $intervals
     * @return list<array{resource_id:int, local_date:string}>
     */
    public function employeeKeys(string $timezone, array $intervals): array
    {
        $keys = [];
        foreach ($intervals as $i) {
            foreach (LocalTime::localDatesCovered($i['start'], $i['end'], $timezone) as $date) {
                $keys[$i['employee_id'].'|'.$date] = ['resource_id' => (int) $i['employee_id'], 'local_date' => $date];
            }
        }
        $keys = array_values($keys);
        usort($keys, fn ($a, $b) => [$a['resource_id'], $a['local_date']] <=> [$b['resource_id'], $b['local_date']]);

        return $keys;
    }

    /** Step 1 (outside the transaction): make sure lock rows exist. */
    public function ensure(int $tenantId, string $resourceType, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $now = now();
        DB::table('resource_day_locks')->insertOrIgnore(array_map(fn ($k) => [
            'tenant_id' => $tenantId,
            'resource_type' => $resourceType,
            'resource_id' => $k['resource_id'],
            'local_date' => $k['local_date'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $keys));
    }

    /**
     * Step 2 (inside the transaction): lock rows in deterministic order.
     *
     * @param  list<array{resource_id:int, local_date:string}>  $keys  sorted
     */
    public function lock(int $tenantId, string $resourceType, array $keys): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Resource locks must be acquired inside a transaction.');
        }
        // Rows normally exist already (ensure()); insert defensively if a caller skipped it.
        $existing = DB::table('resource_day_locks')->where('tenant_id', $tenantId)->where('resource_type', $resourceType)
            ->whereIn('resource_id', array_column($keys, 'resource_id'))->whereIn('local_date', array_column($keys, 'local_date'))->count();
        if ($existing < count($keys)) {
            $this->ensure($tenantId, $resourceType, $keys);
        }
        // Lock in deterministic order (row by row keeps the acquisition order explicit).
        foreach ($keys as $k) {
            DB::table('resource_day_locks')
                ->where('tenant_id', $tenantId)
                ->where('resource_type', $resourceType)
                ->where('resource_id', $k['resource_id'])
                ->where('local_date', $k['local_date'])
                ->lockForUpdate()
                ->first(['id']);
        }
    }

    /**
     * Ensure lock rows, then run $callback in a booking transaction that first
     * acquires them. All booking writes go through this.
     *
     * @param  list<array{employee_id:int, start:CarbonInterface, end:CarbonInterface}>  $intervals
     */
    public function withEmployeeLocks(int $tenantId, string $timezone, array $intervals, \Closure $callback): mixed
    {
        $keys = $this->employeeKeys($timezone, $intervals);
        $this->ensure($tenantId, self::RESOURCE_EMPLOYEE, $keys);

        return BookingTransaction::run(function () use ($tenantId, $keys, $callback) {
            $this->lock($tenantId, self::RESOURCE_EMPLOYEE, $keys);

            return $callback();
        });
    }
}
