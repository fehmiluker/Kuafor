<?php

namespace App\Services\WhatsApp;

/**
 * Platform template definitions (category UTILITY). These must be submitted to
 * and APPROVED by Meta under each WABA before production use; the seeder
 * creates them with approval_status=PENDING (APPROVED in fake/sandbox mode).
 *
 * Body placeholders are positional ({{1}}, {{2}}...). Quick-reply buttons carry
 * an opaque one-time payload "a:{token}" — never PII or ids.
 */
final class TemplateCatalog
{
    public const TEMPLATES = [
        'appointment_received' => [
            'name' => 'randevu_talebi_alindi',
            'body' => 'Merhaba {{1}}, {{2}} için {{3}} tarihli randevu talebiniz alındı. Salon onayladığında size haber vereceğiz. Randevunuzu yönetmek için: {{4}}',
            'buttons' => [],
        ],
        'appointment_confirmed' => [
            'name' => 'randevu_onaylandi',
            'body' => 'Merhaba {{1}}, {{2}} randevunuz {{3}} için onaylandı. Detaylar ve iptal: {{4}}',
            'buttons' => [],
        ],
        'appointment_rejected' => [
            'name' => 'randevu_onaylanamadi',
            'body' => 'Merhaba {{1}}, {{2}} için {{3}} tarihli talebiniz maalesef onaylanamadı. Yeni bir saat seçmek için: {{4}}',
            'buttons' => [],
        ],
        'appointment_cancelled' => [
            'name' => 'randevu_iptal',
            'body' => 'Merhaba {{1}}, {{2}} için {{3}} tarihli randevunuz iptal edildi. Yeni randevu için: {{4}}',
            'buttons' => [],
        ],
        'appointment_rescheduled' => [
            'name' => 'randevu_degisti',
            'body' => 'Merhaba {{1}}, {{2}} randevunuz {{3}} olarak güncellendi. Detaylar: {{4}}',
            'buttons' => [],
        ],
        'appointment_reminder' => [
            'name' => 'randevu_hatirlatma',
            'body' => 'Hatırlatma: {{1}}, {{2}} randevunuz {{3}}. Gelemeyecekseniz lütfen iptal edin: {{4}}',
            'buttons' => [],
        ],
        'proposal_offer' => [
            'name' => 'randevu_alternatif_teklif',
            'body' => 'Merhaba {{1}}, {{2}} talep ettiğiniz saati veremiyor; bunun yerine {{3}} önerildi. Teklif {{4}} tarihine kadar geçerli.',
            'buttons' => ['Kabul Et', 'Reddet'],
        ],
        'review_invite' => [
            'name' => 'degerlendirme_daveti',
            'body' => 'Merhaba {{1}}, {{2}} ziyaretiniz için teşekkürler! Deneyiminizi değerlendirmek ister misiniz? {{3}}',
            'buttons' => [],
        ],
        'salon_new_request' => [
            'name' => 'salon_yeni_talep',
            'body' => '{{1}} şubesine yeni randevu talebi: {{2}} — {{3}}, {{4}}. Onaylayabilir veya başka saat önerebilirsiniz.',
            'buttons' => ['Onayla', 'Başka Saat Öner'],
        ],
        'salon_new_booking' => [
            'name' => 'salon_yeni_randevu',
            'body' => '{{1}} şubesine yeni (otomatik onaylı) randevu: {{2}} — {{3}}, {{4}}.',
            'buttons' => [],
        ],
        'salon_update' => [
            'name' => 'salon_randevu_guncelleme',
            'body' => '{{1}} şubesi: {{2}} ({{3}}).',
            'buttons' => [],
        ],
    ];

    public static function get(string $key): array
    {
        return self::TEMPLATES[$key] ?? throw new \InvalidArgumentException("Unknown template {$key}");
    }

    /** Render a body for e-mail/panel fallback or the sandbox preview. */
    public static function render(string $key, array $params): string
    {
        $body = self::get($key)['body'];
        foreach (array_values($params) as $i => $value) {
            $body = str_replace('{{'.($i + 1).'}}', (string) $value, $body);
        }

        return $body;
    }

    /** Meta "components" for a template with text params and quick-reply payloads. */
    public static function components(array $params, array $buttonPayloads = []): array
    {
        $components = [[
            'type' => 'body',
            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params)),
        ]];
        foreach (array_values($buttonPayloads) as $i => $payload) {
            $components[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i, 'parameters' => [['type' => 'payload', 'payload' => $payload]]];
        }

        return $components;
    }
}
