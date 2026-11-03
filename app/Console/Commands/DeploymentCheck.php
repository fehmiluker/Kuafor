<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use App\Services\Auth\WhatsAppOtpSender;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\PaymentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-install / health check for a hosting environment (cPanel or VPS).
 * Read-only: it never changes data. Exit code 1 if any hard requirement fails.
 *
 *   php artisan kuafor:check          # full check
 *   php artisan kuafor:check --demo   # additionally require demo safety (no real WhatsApp / payments)
 */
class DeploymentCheck extends Command
{
    protected $signature = 'kuafor:check {--demo : Demo ortamı kurallarını da zorunlu tut}';

    protected $description = 'Sunucu gereksinimlerini ve uygulama sağlığını kontrol eder (salt okunur)';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        // The command instance is reused within one process (Artisan::call, tests): reset counters.
        $this->failures = 0;
        $this->warnings = 0;
        $this->line('<options=bold>Kuaför Randevu — kurulum kontrolü</>');

        $this->section('PHP');
        $this->check(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP sürümü '.PHP_VERSION, 'En az PHP 8.2 gerekli (önerilen 8.4).');
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            $this->warn('  ⚠ PHP 8.4 önerilir (şu an '.PHP_VERSION.').');
            $this->warnings++;
        }
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'dom', 'xml', 'ctype', 'fileinfo', 'curl', 'gd', 'json', 'session'] as $ext) {
            $this->check(extension_loaded($ext), "Eklenti: {$ext}", "cPanel > Select PHP Version > Extensions bölümünden '{$ext}' eklentisini açın.");
        }
        if (extension_loaded('gd')) {
            $gd = gd_info();
            $this->check(($gd['JPEG Support'] ?? false) && ($gd['PNG Support'] ?? false), 'GD: JPEG + PNG desteği', 'GD eklentisi JPEG/PNG desteği olmadan derlenmiş; hosting firmasına bildirin.');
            if (! ($gd['WebP Support'] ?? false)) {
                $this->softWarn('GD: WebP desteği yok (WebP görsel yüklemeleri başarısız olabilir).');
            }
        }
        foreach (['intl', 'zip', 'bcmath'] as $ext) {
            if (! extension_loaded($ext)) {
                $this->softWarn("İsteğe bağlı eklenti kapalı: {$ext}");
            }
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $this->check(! in_array('proc_open', $disabled, true), 'proc_open açık (zamanlanmış komutlar için)', "php.ini 'disable_functions' içinden proc_open kaldırılmalı.");
        $this->check(! in_array('symlink', $disabled, true), 'symlink açık (storage:link için)', "php.ini 'disable_functions' içinden symlink kaldırılmalı.");

        $this->section('Uygulama');
        $this->check(filled(config('app.key')), 'APP_KEY tanımlı', "'php artisan key:generate' çalıştırın.");
        $debugOk = ! config('app.debug') || app()->environment('local', 'testing');
        $this->check($debugOk, 'APP_DEBUG kapalı', 'Sunucuda .env içinde APP_DEBUG=false olmalı (hata ayrıntıları ziyaretçilere görünür).');
        if (! str_starts_with((string) config('app.url'), 'https://') && ! app()->environment('local', 'testing')) {
            $this->softWarn('APP_URL https:// ile başlamıyor ('.config('app.url').').');
        }
        $manifest = public_path('build/manifest.json');
        $assetsOk = is_file($manifest);
        if ($assetsOk) {
            foreach ((array) json_decode((string) file_get_contents($manifest), true) as $entry) {
                $assetsOk = $assetsOk && is_file(public_path('build/'.($entry['file'] ?? '')));
            }
        }
        $this->check($assetsOk, 'Derlenmiş CSS/JS (public/build)', "public/build eksik: GitHub'dan gelen 'public/build' klasörünün sunucuda olduğundan emin olun.");
        $this->check(is_writable(storage_path('framework')) && is_writable(storage_path('logs')), 'storage/ yazılabilir', "'find storage bootstrap/cache -type d -exec chmod 775 {} +' çalıştırın.");
        $this->check(is_writable(base_path('bootstrap/cache')), 'bootstrap/cache yazılabilir', "'find bootstrap/cache -type d -exec chmod 775 {} +' çalıştırın.");
        if (! is_link(public_path('storage')) && ! is_dir(public_path('storage'))) {
            $this->softWarn("public/storage bağlantısı yok: 'php artisan storage:link' (logo/fotoğraf yüklemeleri görünmez).");
        } else {
            $this->ok('public/storage bağlantısı');
        }

        $this->section('Veritabanı');
        try {
            DB::connection()->getPdo();
            $this->ok('Bağlantı: '.config('database.connections.'.config('database.default').'.database'));
            $version = (string) DB::selectOne('select version() as v')->v;
            $isMaria = stripos($version, 'mariadb') !== false;
            $this->check(! $isMaria, 'Sunucu: '.$version, 'MariaDB ile test edilmedi; MySQL 8.0.16+ kullanın.');
            if (! $isMaria) {
                $this->check(version_compare(preg_replace('/[^0-9.].*$/', '', $version), '8.0.16', '>='), 'MySQL 8.0.16 veya üstü', 'CHECK kısıtları için MySQL 8.0.16+ gerekli.');
            }
            $pending = $this->pendingMigrations();
            $this->check($pending === 0, 'Bekleyen migration yok', "{$pending} migration bekliyor: 'php artisan migrate --force'.");
            if (Schema::hasTable('plans')) {
                $this->check(DB::table('plans')->count() > 0 && DB::table('roles')->count() > 0, 'Paketler ve roller yüklü', "'php artisan db:seed --force' çalıştırın.");
                $tenants = DB::table('tenants')->count();
                $tenants > 0 ? $this->ok("Demo/işletme verisi: {$tenants} işletme") : $this->softWarn('Henüz işletme yok (demo için: php artisan db:seed --class=DemoSeeder --force).');
            }
        } catch (\Throwable $e) {
            $this->check(false, 'Veritabanı bağlantısı', 'Bağlanılamadı: .env içindeki DB_* değerlerini kontrol edin ('.class_basename($e).').');
        }

        $this->section('Cron ve kuyruk');
        $this->line('  Kuyruk sürücüsü: '.config('queue.default'));
        $this->heartbeat('kuafor:scheduler_heartbeat', 'Zamanlayıcı (schedule:run cron)', "cPanel > Cron Jobs: her dakika 'php artisan schedule:run'.");
        if (config('queue.default') !== 'sync') {
            $this->heartbeat(QueueHeartbeat::KEY, 'Kuyruk işçisi (queue:work cron)', "cPanel > Cron Jobs: her dakika 'php artisan queue:work --queue=webhooks,notifications,default --stop-when-empty --max-time=55'.");
            if (Schema::hasTable('jobs')) {
                $oldest = DB::table('jobs')->min('created_at');
                if ($oldest && CarbonImmutable::createFromTimestamp((int) $oldest)->lt(now()->subMinutes(5))) {
                    $this->softWarn('Kuyrukta 5 dakikadan eski iş var ('.DB::table('jobs')->count().' iş).');
                }
            }
        }
        if (Schema::hasTable('failed_jobs') && ($failed = DB::table('failed_jobs')->count()) > 0) {
            $this->softWarn("Başarısız iş sayısı: {$failed} ('php artisan queue:failed' ile inceleyin).");
        }

        $this->section('Dış servisler (demo güvenliği)');
        $waMode = config('whatsapp.mode');
        $realWhatsApp = $waMode === 'meta';
        $paymentSettings = app(PaymentSettings::class);
        $paytr = $paymentSettings->paytr();
        $paymentProvider = $paymentSettings->provider();
        $realPayment = $paymentProvider !== 'manual' || app(PaymentGatewayInterface::class)->supportsAutomaticCharging() || filled(config('subscriptions.webhook_secret'));
        $this->line('  WhatsApp: '.($realWhatsApp ? '<fg=yellow>GERÇEK (Meta Cloud API)</>' : 'kapalı / sandbox (gerçek mesaj gitmez)'));
        $this->line('  Ödeme: '.match (true) {
            $paymentProvider === 'paytr' => '<fg=yellow>PayTR kartla ödeme AÇIK</>'.(($paytr['test_mode'] ?? true) ? ' (test modu)' : ' (CANLI)'),
            $realPayment => '<fg=yellow>ödeme bildirim adresi AÇIK</>',
            default => 'gerçek ödeme yok, bildirim adresi kapalı',
        });
        $this->line('  E-posta: '.config('mail.default'));
        // Phone verification (home visits, salons that require it) only works over WhatsApp: there is no SMS.
        app(WhatsAppOtpSender::class)->available()
            ? $this->ok('Telefon doğrulama: WhatsApp (platform numarası + "dogrulama_kodu" şablonu hazır)')
            : $this->softWarn('Telefon doğrulama kapalı: platform WhatsApp numarası bağlı değil veya "dogrulama_kodu" şablonu Meta\'da onaylanmadı. Adrese hizmet randevusu yalnızca telefonu doğrulanmış üyeler alabilir.');
        if ($paymentProvider === 'paytr') {
            $this->check(filled($paytr['merchant_id'] ?? null) && filled($paytr['merchant_key'] ?? null) && filled($paytr['merchant_salt'] ?? null),
                'PayTR mağaza bilgileri tanımlı', '.env: PAYTR_MERCHANT_ID, PAYTR_MERCHANT_KEY, PAYTR_MERCHANT_SALT (PayTR Mağaza Paneli > Bilgi).');
            $this->line('  PayTR Bildirim URL: '.url('/webhooks/subscription-payments/paytr'));
        }
        if ($this->option('demo')) {
            $this->check(! $realWhatsApp, 'Demo: WhatsApp gerçek mesaj göndermiyor', 'Demo için .env: WHATSAPP_PROVIDER_MODE=fake');
            $this->check(blank(config('whatsapp.app_secret')) && blank(config('whatsapp.verify_token')), 'Demo: Meta anahtarları boş', 'Demo için META_APP_SECRET ve META_VERIFY_TOKEN boş bırakılmalı.');
            $this->check(! $realPayment || ($paymentProvider === 'paytr' && ($paytr['test_mode'] ?? true)), 'Demo: gerçek ödeme kapalı', 'Demo için SUBSCRIPTION_PAYMENT_PROVIDER=manual (veya paytr + PAYTR_TEST_MODE=true) ve SUBSCRIPTION_WEBHOOK_SECRET boş olmalı.');
            $this->check(config('mail.default') === 'log', 'Demo: e-posta dışarı gönderilmiyor', 'Demo için MAIL_MAILER=log önerilir.');
            $this->check(! app()->environment('production'), 'Demo: APP_ENV production değil', 'Demo verisi için APP_ENV=staging kullanın.');
        }

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("Sonuç: {$this->failures} hata, {$this->warnings} uyarı. Hataları düzeltip tekrar çalıştırın.");

            return self::FAILURE;
        }
        $this->info("Sonuç: tüm zorunlu kontroller geçti ({$this->warnings} uyarı).");

        return self::SUCCESS;
    }

    private function pendingMigrations(): int
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return count($migrator->getMigrationFiles(database_path('migrations')));
        }
        $ran = $migrator->getRepository()->getRan();

        return count(array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $ran));
    }

    private function heartbeat(string $key, string $label, string $fix): void
    {
        $at = Cache::get($key);
        if (! $at) {
            $this->softWarn("{$label}: henüz çalıştığına dair kayıt yok. Cron'u kurduktan 2 dakika sonra tekrar kontrol edin. ({$fix})");

            return;
        }
        $age = (int) CarbonImmutable::parse($at)->diffInMinutes(now(), true);
        $this->check($age <= 3, "{$label}: son çalışma {$age} dk önce", $fix);
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function ok(string $label): void
    {
        $this->line("  <fg=green>✓</> {$label}");
    }

    private function softWarn(string $message): void
    {
        $this->line("  <fg=yellow>⚠</> {$message}");
        $this->warnings++;
    }

    private function check(bool $passed, string $label, string $fix): void
    {
        if ($passed) {
            $this->ok($label);

            return;
        }
        $this->line("  <fg=red>✗</> {$label} — {$fix}");
        $this->failures++;
    }
}
