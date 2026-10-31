<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_messages';

    protected $guarded = ['id'];

    /** Inbound messages on the platform number can arrive before their tenant is known. */
    public function allowsNullTenant(): bool
    {
        return $this->connection_id !== null
            && (bool) WhatsAppConnection::withoutGlobalScopes()->whereKey($this->connection_id)->value('is_platform');
    }

    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'payload_redacted' => 'array',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'connection_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
