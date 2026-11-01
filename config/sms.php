<?php

return [
    // log: writes to the log (development/demo) · netgsm: real SMS via Netgsm REST v2
    'provider' => env('SMS_PROVIDER', 'log'),
    'otp_ttl_minutes' => 10,
    'otp_max_attempts' => 5,
    'otp_max_sends_per_hour' => 5,

    'netgsm' => [
        // Netgsm panel > Abonelik Bilgileri > API kullanıcısı (alt kullanıcı önerilir)
        'usercode' => env('NETGSM_USERCODE'),
        'password' => env('NETGSM_PASSWORD'),
        // Onaylı mesaj başlığı (gönderici adı), ör. KUAFORRNDV
        'header' => env('NETGSM_MSGHEADER'),
        // true: /sms/rest/v2/otp (OTP paketi gerekir, Türkçe karakterler sadeleştirilir)
        'otp' => (bool) env('NETGSM_OTP', false),
        'base_url' => env('NETGSM_BASE_URL', 'https://api.netgsm.com.tr'),
        'timeout' => 10,
    ],
];
