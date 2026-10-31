<?php

namespace App\Services\Booking;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs booking writes in READ COMMITTED with deadlock retries.
 *
 * Why READ COMMITTED: under REPEATABLE READ the snapshot is taken at the first
 * plain SELECT; if that happened before the resource lock was granted, the
 * conflict query after the lock could miss a booking committed meanwhile.
 * READ COMMITTED gives each statement a fresh view, so the re-check after the
 * lock always sees every committed booking.
 */
final class BookingTransaction
{
    public static function run(Closure $callback): mixed
    {
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($callback, (int) config('booking.transaction_attempts', 3));
    }
}
