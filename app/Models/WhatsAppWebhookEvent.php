<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-level inbox: the tenant is resolved from phone_number_id during processing. */
class WhatsAppWebhookEvent extends Model
{
    protected $table = 'whatsapp_webhook_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload_encrypted_or_redacted' => 'encrypted:array',
            'processed_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
