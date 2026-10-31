<?php

namespace Tests\Concurrency;

use App\Models\Plan;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\TestBaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Real parallelism: data is COMMITTED (truncation instead of a wrapping
 * transaction) and each racer is a forked process with its own MySQL
 * connection, so InnoDB locking is exercised exactly as in production.
 */
abstract class ConcurrencyTestCase extends BaseTestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    protected string $seeder = TestBaseSeeder::class;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for concurrency tests.');
        }
        parent::setUp();
    }

    protected function plan(string $slug = 'profesyonel'): Plan
    {
        return Plan::with('features')->where('slug', $slug)->firstOrFail();
    }

    /**
     * Fork one process per callable, start them together, collect their string results.
     *
     * @param  list<callable(): string>  $workers
     * @return list<string>
     */
    protected function race(array $workers): array
    {
        $dir = sys_get_temp_dir().'/kuafor-race-'.uniqid();
        mkdir($dir);
        $barrier = $dir.'/go';
        DB::disconnect(); // never share the parent's socket with children
        $pids = [];
        foreach ($workers as $i => $worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::purge();
                    app(TenantContext::class)->forget();
                    while (! file_exists($barrier)) {
                        usleep(1000);
                    }
                    $result = $worker();
                } catch (\Throwable $e) {
                    $result = 'error:'.class_basename($e).':'.$e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $result);
                // Skip PHPUnit/Laravel shutdown in the child.
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        usleep(200000);
        touch($barrier);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $results = [];
        foreach (array_keys($workers) as $i) {
            $results[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }
        @unlink($barrier);
        @rmdir($dir);
        DB::reconnect();

        return $results;
    }
}
