<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeContent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function imageUrl(bool $mobile = false): string
    {
        $path = $mobile ? ($this->image_mobile_path ?: $this->image_path) : $this->image_path;
        return $path ? asset('storage/'.$path) : asset($this->asset_path ?: 'images/home/hero.jpg');
    }
}
