<?php

namespace App\Models;

use App\Enums\WhatsAppConnectionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppConnection extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_connections';

    protected $guarded = ['id'];

    // The credential never leaves the server: hidden from every serialization.
    protected $hidden = ['encrypted_credential_reference', 'encrypted_pin'];

    /** The platform connection has no tenant. */
    public function allowsNullTenant(): bool
    {
        return (bool) $this->is_platform;
    }

    public static function platform(): ?self
    {
        return static::withoutGlobalScopes()->where('is_platform', true)->latest('id')->first();
    }

    protected function casts(): array
    {
        return [
            'status' => WhatsAppConnectionStatus::class,
            'encrypted_credential_reference' => 'encrypted',
            'encrypted_pin' => 'encrypted',
            'is_platform' => 'boolean',
            'permissions' => 'array',
            'metadata' => 'array',
            'webhook_subscribed' => 'boolean',
            'connected_at' => 'immutable_datetime',
            'last_health_check_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isUsable(): bool
    {
        return $this->status === WhatsAppConnectionStatus::Connected
            && $this->phone_number_id !== null
            && $this->encrypted_credential_reference !== null;
    }
}
