<?php

return [
    // Spatie "team" id used for platform-level roles (super_admin, platform_support, customer).
    'platform_team_id' => 0,

    // Session key that stores the currently selected tenant in the web panel.
    'session_key' => 'current_tenant_id',

    // API clients select a tenant they are a member of with this header (slug or id).
    // Membership is always verified server-side; the header never grants access by itself.
    'header' => 'X-Tenant',

    'default_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Istanbul'),
];
