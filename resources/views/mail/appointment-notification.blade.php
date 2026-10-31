<!DOCTYPE html>
<html lang="tr"><body style="font-family:Arial,sans-serif;color:#1e293b;background:#f8fafc;padding:24px">
<div style="max-width:560px;margin:auto;background:#fff;border-radius:12px;padding:24px;border:1px solid #e2e8f0">
    <h1 style="font-size:18px;margin:0 0 12px">{{ $subjectLine }}</h1>
    <p style="line-height:1.6">{{ $body }}</p>
    @if($actionUrl)<p><a href="{{ $actionUrl }}" style="display:inline-block;background:#2f55c7;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">{{ $actionLabel }}</a></p>@endif
    <p style="font-size:12px;color:#64748b">Bu bağlantı size özeldir, lütfen paylaşmayın. Ödemeler salonda yapılır.</p>
</div>
</body></html>
