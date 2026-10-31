<?php

namespace Database\Seeders;

use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateCatalog;
use Illuminate\Database\Seeder;

/**
 * Platform templates. In `meta` mode they start as PENDING: submit them to Meta
 * (WhatsApp Manager / Graph API) and set APPROVED + provider_template_id when
 * approved. In `fake` mode they are marked APPROVED so the sandbox works end-to-end.
 */
class WhatsAppTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $approved = config('whatsapp.mode') !== 'meta';
        foreach (TemplateCatalog::TEMPLATES as $key => $t) {
            WhatsAppTemplate::updateOrCreate(['tenant_id' => null, 'key' => $key, 'language' => 'tr'], [
                'name' => $t['name'], 'category' => 'UTILITY',
                'approval_status' => $approved ? 'APPROVED' : 'PENDING',
                'parameters_schema' => ['body_params' => substr_count($t['body'], '{{'), 'quick_replies' => $t['buttons']],
                'fallback_body' => $t['body'],
            ]);
        }
    }
}
