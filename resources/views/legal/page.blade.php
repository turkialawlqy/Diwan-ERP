<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['title'] }} — {{ config('app.name') }}</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.9; margin: 0; }
        main { max-width: 820px; margin: 24px auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px 28px; }
        h1 { font-size: 22px; } h2 { font-size: 16px; margin-top: 22px; } blockquote { background: #fffbeb; border-right: 4px solid #f59e0b; margin: 0; padding: 6px 12px; color: #92400e; }
        nav { text-align: center; font-size: 13px; margin: 16px; } a { color: #4f46e5; }
    </style>
</head>
<body>
<main>{!! $html !!}</main>
<nav>
    <a href="{{ route('legal.show', 'terms') }}">الشروط والأحكام</a> · <a href="{{ route('legal.show', 'privacy') }}">سياسة الخصوصية</a> · <a href="{{ route('login') }}">تسجيل الدخول</a>
    @if ($page['updated_at']) <div style="color:#64748b">آخر تحديث: {{ $page['updated_at']->format('Y-m-d') }}</div> @endif
</nav>
</body>
</html>
