<?php

namespace App\Services\Catalog;

use App\Models\Branch;
use App\Models\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The salon's permanent customer link (/r/{slug}) that opens booking directly,
 * its QR code and simple per-source counters. Tenant slugs never change, so
 * printed QR codes keep working.
 */
final class BookingLinkService
{
    /** Source key (?k=) => panel label. */
    public const SOURCES = ['link' => 'Bağlantı', 'qr' => 'QR kod', 'wa' => 'WhatsApp', 'eposta' => 'E-posta'];

    private const SESSION_KEY = 'booking_link';

    public function url(Tenant $tenant, ?string $source = null, ?Branch $branch = null): string
    {
        return route('booking-link', array_filter(['slug' => $tenant->slug, 'k' => $source, 'sube' => $branch?->id]));
    }

    public static function source(?string $value): string
    {
        return array_key_exists((string) $value, self::SOURCES) ? (string) $value : 'link';
    }

    /** Counts a visit once per browser session and remembers the source for booking attribution. */
    public function visit(Tenant $tenant, string $source): void
    {
        $seen = session()->get(self::SESSION_KEY, []);
        if (! isset($seen[$tenant->id])) {
            $this->increment($tenant->id, $source, 'visits');
        }
        $seen[$tenant->id] = $source;
        session()->put(self::SESSION_KEY, $seen);
    }

    /** Called after a successful online booking: credits the link that brought the customer (once). */
    public function booked(int $tenantId): void
    {
        $seen = session()->get(self::SESSION_KEY, []);
        if (isset($seen[$tenantId])) {
            $this->increment($tenantId, $seen[$tenantId], 'bookings');
            unset($seen[$tenantId]);
            session()->put(self::SESSION_KEY, $seen);
        }
    }

    /** @return array<string, array{label:string, visits:int, bookings:int}> last $days days, per source */
    public function stats(Tenant $tenant, int $days = 30): array
    {
        $rows = DB::table('booking_link_stats')->where('tenant_id', $tenant->id)
            ->where('day', '>=', CarbonImmutable::now('Europe/Istanbul')->subDays($days - 1)->toDateString())
            ->groupBy('source')->selectRaw('source, SUM(visits) v, SUM(bookings) b')->get()->keyBy('source');

        return collect(self::SOURCES)->map(fn ($label, $key) => [
            'label' => $label, 'visits' => (int) ($rows[$key]->v ?? 0), 'bookings' => (int) ($rows[$key]->b ?? 0),
        ])->all();
    }

    public function qrSvg(string $url, int $size = 320): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd));

        return $writer->writeString($url, 'UTF-8');
    }

    private function increment(int $tenantId, string $source, string $column): void
    {
        $now = now();
        DB::statement(
            "INSERT INTO booking_link_stats (tenant_id, day, source, visits, bookings, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE {$column} = {$column} + 1, updated_at = VALUES(updated_at)",
            [$tenantId, CarbonImmutable::now('Europe/Istanbul')->toDateString(), $source, $column === 'visits' ? 1 : 0, $column === 'bookings' ? 1 : 0, $now, $now]
        );
    }
}
