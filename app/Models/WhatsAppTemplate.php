<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** tenant_id NULL = platform template shared by all tenants (submitted under the platform WABA). */
class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parameters_schema' => 'array'];
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'APPROVED';
    }
}
