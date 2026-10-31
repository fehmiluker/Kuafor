<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** employee_id NULL = branch opening hours; otherwise employee shift. Times are branch-local. */
class WorkingHour extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'day_of_week' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public const DAY_NAMES = [0 => 'Pazar', 1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi'];
}
