<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f4f4f7; padding:24px;">
    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
        <h2 style="margin-bottom:8px;">Your verification code</h2>
        <p style="color:#555;">Enter this code to finish signing up for {{ config('app.name', 'Sevof') }}:</p>
        <div style="font-size:32px;letter-spacing:8px;font-weight:bold;background:#f0f2ff;color:#3b3fd9;padding:16px;border-radius:10px;margin:24px 0;">
            {{ $code }}
        </div>
        <p style="color:#999;font-size:13px;">This code expires in 10 minutes. If you didn't request it, you can ignore this email.</p>
    </div>
</body>
</html>
