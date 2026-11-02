<?php

namespace App\Services\Onboarding;

use App\Exceptions\DomainRuleException;
use App\Exceptions\PlanLimitException;
use App\Models\City;
use App\Models\District;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Bulk salon import from a CSV (Excel "CSV UTF-8" or Turkish Windows-1254, ";" or ","),
 * for salons that agreed to join. Two steps: parse() validates every row without
 * writing anything (admin preview), import() creates the valid rows one by one.
 */
final class SalonCsvImporter
{
    public const MAX_ROWS = 2000;

    /** Column => [label, required, example] (also the downloadable template). */
    public const COLUMNS = [
        'isletme_adi' => ['İşletme adı', true, 'Örnek Kuaför'],
        'yetkili_adi' => ['Yetkili ad soyad', true, 'Ayşe Yılmaz'],
        'eposta' => ['Yetkili e-posta (davet buraya gider)', true, 'ayse@ornekkuafor.com'],
        'telefon' => ['Telefon', false, '0532 111 22 33'],
        'il' => ['İl', true, 'Bursa'],
        'ilce' => ['İlçe', false, 'Nilüfer'],
        'adres' => ['Açık adres', true, 'Fethiye Mah. Örnek Sk. No:1'],
        'paket' => ['Paket (standart / profesyonel / premium)', false, 'standart'],
        'aciklama' => ['Kısa tanıtım', false, 'Kadın kuaförü; kesim, boya ve fön.'],
        'hizmetler' => ['Hizmetler: ad:dakika:fiyat, | ile ayrılmış', false, 'Kadın Saç Kesimi:45:500|Fön:30:250|Dip Boya:90:1200'],
        'personel' => ['Personel adları, | ile ayrılmış', false, 'Ayşe|Zeynep'],
    ];

    public function __construct(private readonly SalonOnboardingService $onboarding) {}

    public static function template(): string
    {
        $row = fn (array $cells) => implode(';', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $cells));

        return "\xEF\xBB\xBF".$row(array_keys(self::COLUMNS))."\r\n".$row(array_column(self::COLUMNS, 2))."\r\n";
    }

    /**
     * @return list<array{line:int, name:string, place:string, data:?array, errors:list<string>, warnings:list<string>}>
     */
    public function parse(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1254');
        }
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        // Header matching ignores case, Turkish letters, spaces and dashes: "E-posta", "İşletme Adı" work too.
        $byLoose = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $byLoose[str_replace('_', '', $key)] = $key;
        }
        $header = array_map(function ($h) use ($byLoose) {
            $loose = str_replace('_', '', Str::slug(trim((string) $h), '_'));

            return $byLoose[$loose] ?? $loose;
        }, fgetcsv($stream, null, $delimiter, '"', '') ?: []);
        $missing = array_diff(array_keys(array_filter(self::COLUMNS, fn ($c) => $c[1])), $header);
        if ($missing !== []) {
            throw new DomainRuleException('Eksik sütun: '.implode(', ', $missing).'. Şablonu indirip başlık satırını değiştirmeden kullanın.', 'invalid_csv');
        }

        $rows = [];
        $seen = [];
        $line = 1;
        $plans = Plan::where('active', true)->pluck('slug')->all();
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new DomainRuleException('Tek seferde en fazla '.self::MAX_ROWS.' satır yüklenebilir; dosyayı bölün.', 'too_many_rows');
            }
            $raw = [];
            foreach ($header as $i => $key) {
                $raw[$key] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $this->validateRow($line, $raw, $plans, $seen);
        }
        fclose($stream);

        return $rows;
    }

    /** @param array<string, true> $seen */
    private function validateRow(int $line, array $r, array $plans, array &$seen): array
    {
        $errors = [];
        $warnings = [];
        $name = Str::limit($r['isletme_adi'] ?? '', 150, '');
        if (mb_strlen($name) < 2) {
            $errors[] = 'İşletme adı eksik.';
        }
        if (mb_strlen($r['yetkili_adi'] ?? '') < 2) {
            $errors[] = 'Yetkili adı eksik.';
        }
        $email = strtolower($r['eposta'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-posta geçersiz.';
        }
        $phone = null;
        if (($r['telefon'] ?? '') !== '') {
            $phone = PhoneNumber::normalize($r['telefon']);
            if (! $phone) {
                $errors[] = 'Telefon geçersiz.';
            }
        }
        $city = ($r['il'] ?? '') !== '' ? City::where('slug', Str::slug($r['il']))->first() : null;
        if (! $city) {
            $errors[] = 'İl bulunamadı: '.($r['il'] ?? '');
        }
        $district = null;
        if ($city && ($r['ilce'] ?? '') !== '') {
            $district = District::where('city_id', $city->id)->where('slug', Str::slug($r['ilce']))->first();
            if (! $district) {
                $warnings[] = "Yeni ilçe eklenecek: {$r['ilce']}";
            }
        }
        if (mb_strlen($r['adres'] ?? '') < 5) {
            $errors[] = 'Adres eksik.';
        }
        $plan = Str::slug(($r['paket'] ?? '') ?: 'standart');
        if (! in_array($plan, $plans, true)) {
            $errors[] = "Paket bulunamadı: {$plan}";
        }

        [$services, $serviceErrors] = self::parseServices($r['hizmetler'] ?? '');
        array_push($errors, ...$serviceErrors);
        if ($services === []) {
            $warnings[] = 'Hizmet yok: salon, sahibi hizmet ekleyene kadar aramada listelenmez.';
        }
        $employees = array_values(array_filter(array_map(fn ($n) => Str::limit(trim($n), 60, ''), explode('|', $r['personel'] ?? ''))));

        $key = $email.'|'.Str::slug($name);
        if (isset($seen[$key])) {
            $errors[] = 'Dosyada aynı işletme iki kez var.';
        }
        $seen[$key] = true;
        if ($errors === [] && ($owner = User::where('email', $email)->first())
            && Tenant::withTrashed()->where('owner_user_id', $owner->id)->where('name', $name)->exists()) {
            $errors[] = 'Bu işletme zaten kayıtlı.';
        }

        return [
            'line' => $line,
            'name' => $name ?: '—',
            'place' => implode(', ', array_filter([$district?->name ?? (($r['ilce'] ?? '') ?: null), $city?->name])),
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => $errors !== [] ? null : [
                'business_name' => $name, 'owner_name' => $r['yetkili_adi'], 'owner_email' => $email, 'owner_phone' => $phone,
                'city_id' => $city->id, 'district_name' => $district ? null : (($r['ilce'] ?? '') ?: null), 'district_id' => $district?->id,
                'address' => Str::limit($r['adres'], 300, ''), 'plan' => $plan, 'description' => ($r['aciklama'] ?? '') ?: null,
                'services' => $services, 'employees' => $employees,
            ],
        ];
    }

    /**
     * "Kadın Saç Kesimi:45:500|Fön:30:250" (also one per line) => service rows. Optional 4th part: category slug.
     *
     * @return array{0: list<array{name:string, minutes:int, price:string, category:?string}>, 1: list<string>}
     */
    public static function parseServices(string $value): array
    {
        $services = [];
        $errors = [];
        foreach (array_filter(array_map('trim', preg_split('/[|\r\n]+/', $value) ?: [])) as $chunk) {
            $parts = array_map('trim', explode(':', $chunk));
            $minutes = (int) ($parts[1] ?? 0);
            try {
                $price = isset($parts[2]) && $parts[2] !== '' ? Money::parse(trim(str_ireplace(['₺', 'TL'], '', $parts[2])))->toDecimal() : null;
            } catch (\Throwable) {
                $price = null;
            }
            if ($parts[0] === '' || $minutes < 5 || $minutes > 600 || $price === null) {
                $errors[] = "Hizmet okunamadı: \"{$chunk}\" (biçim: ad:dakika:fiyat)";

                continue;
            }
            $services[] = ['name' => Str::limit($parts[0], 150, ''), 'minutes' => $minutes, 'price' => $price, 'category' => ($parts[3] ?? '') ?: null];
        }

        return [$services, $errors];
    }

    /**
     * Creates the valid rows. A failing row (e.g. plan employee limit) is reported and skipped.
     *
     * @return array{created:int, failed:list<array{line:int, name:string, error:string}>}
     */
    public function import(array $rows, bool $invite): array
    {
        $created = 0;
        $failed = [];
        foreach ($rows as $row) {
            if ($row['data'] === null) {
                continue;
            }
            $d = $row['data'];
            try {
                ['tenant' => $tenant, 'user' => $user] = $this->onboarding->create($d, 'csv_import');
                if ($invite) {
                    $this->onboarding->invite($user, $tenant);
                }
                $created++;
            } catch (DomainRuleException|PlanLimitException $e) {
                $failed[] = ['line' => $row['line'], 'name' => $row['name'], 'error' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }
}
