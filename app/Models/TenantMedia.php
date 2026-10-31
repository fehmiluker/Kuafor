<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TenantMedia extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_media';

    protected $guarded = ['id'];

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
