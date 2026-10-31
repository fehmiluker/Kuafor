<?php

namespace App\Enums;

/** Platform roles live in Spatie team 0 (the platform context), separate from tenants. */
enum PlatformRole: string
{
    use HasValues;

    case SuperAdmin = 'super_admin';
    case Support = 'platform_support';
    case Customer = 'customer';
}
