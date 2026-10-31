<?php

return [
    'provider' => env('SMS_PROVIDER', 'log'),
    'otp_ttl_minutes' => 10,
    'otp_max_attempts' => 5,
    'otp_max_sends_per_hour' => 5,
];
