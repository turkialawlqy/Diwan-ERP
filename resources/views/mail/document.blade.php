<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:24px; background:#f1f5f9; font-family:Tahoma, Arial, sans-serif; direction:rtl; text-align:right; color:#0f172a;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:24px;">
        <h2 style="margin:0 0 16px; font-size:18px;">{{ $companyName }}</h2>
        <div style="font-size:14px; line-height:1.9; white-space:pre-line;">{{ $body }}</div>
        @if($link)
            <p style="margin:20px 0 0;"><a href="{{ $link }}" style="display:inline-block; background:#4f46e5; color:#ffffff; text-decoration:none; padding:10px 18px; border-radius:8px; font-size:14px;">عرض المستند وكشف الحساب</a></p>
        @endif
        <p style="margin:24px 0 0; font-size:12px; color:#64748b;">المستند مرفق بصيغة PDF. للاستفسار يمكنكم الرد على هذه الرسالة مباشرة.</p>
    </div>
</body>
</html>
