<?php

namespace App\Console\Commands;

use App\Exceptions\DomainRuleException;
use App\Services\Onboarding\SalonCsvImporter;
use Illuminate\Console\Command;

/** CLI twin of Admin > Salon aktarma (large files, SSH). Only for salons that agreed to join. */
class ImportSalons extends Command
{
    protected $signature = 'kuafor:salon-aktar {dosya : CSV dosyası (şablon: Admin > Salon aktarma)} {--davet : Salon sahiplerine davet e-postası gönder} {--onizle : Yalnızca kontrol et, kaydetme}';

    protected $description = 'Platforma katılmayı kabul eden salonları CSV dosyasından toplu ekler';

    public function handle(SalonCsvImporter $importer): int
    {
        $path = (string) $this->argument('dosya');
        if (! is_readable($path)) {
            $this->error("Dosya okunamadı: {$path}");

            return self::FAILURE;
        }
        try {
            $rows = $importer->parse($path);
        } catch (DomainRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $invalid = array_values(array_filter($rows, fn ($r) => $r['data'] === null));
        foreach ($invalid as $r) {
            $this->warn("Satır {$r['line']} ({$r['name']}): ".implode(' ', $r['errors']));
        }
        $this->info(count($rows).' satır: '.(count($rows) - count($invalid)).' geçerli, '.count($invalid).' hatalı.');
        if ($this->option('onizle')) {
            return self::SUCCESS;
        }

        $result = $importer->import($rows, (bool) $this->option('davet'));
        foreach ($result['failed'] as $f) {
            $this->warn("Satır {$f['line']} ({$f['name']}) eklenemedi: {$f['error']}");
        }
        $this->info("{$result['created']} salon eklendi.".($this->option('davet') ? ' Davet e-postaları kuyruğa alındı.' : ''));

        return self::SUCCESS;
    }
}
