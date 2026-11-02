<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>تقييم الخدمة — {{ $company->name }}</title>
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --brand: #4f46e5; --bg: #f1f5f9; --card: #ffffff; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font-family: Tahoma, "Segoe UI", Arial, sans-serif; font-size: 15px; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 20px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
        h1 { font-size: 18px; margin: 0 0 6px; } .muted { color: var(--muted); font-size: 13px; }
        .stars { display: flex; flex-direction: row-reverse; justify-content: center; gap: 6px; margin: 18px 0; }
        .stars input { display: none; }
        .stars label { font-size: 38px; color: #cbd5e1; cursor: pointer; line-height: 1; }
        .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color: #f59e0b; }
        textarea { width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: 10px; font: inherit; min-height: 90px; }
        button { margin-top: 12px; width: 100%; background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 12px; font: inherit; cursor: pointer; }
        .ok { text-align: center; color: #047857; font-weight: bold; }
    </style>
</head>
<body>
<div class="wrap"><div class="card">
    <h1>{{ $company->name }}</h1>
    @if($done)
        <p class="ok" data-survey-done>شكرًا لتقييمك، رأيك يساعدنا على تحسين الخدمة.</p>
    @else
        <p class="muted">التذكرة {{ $ticket->number }} — {{ $ticket->subject }}</p>
        <form method="post" action="{{ $action }}">
            @csrf
            <p>كيف تقيّم الخدمة التي حصلت عليها؟</p>
            <div class="stars">
                @foreach([5,4,3,2,1] as $n)
                    <input type="radio" name="rating" id="r{{ $n }}" value="{{ $n }}" @if($ticket->rating === $n) checked @endif required>
                    <label for="r{{ $n }}" title="{{ $n }}">★</label>
                @endforeach
            </div>
            <textarea name="comment" placeholder="ملاحظاتك (اختياري)" maxlength="2000">{{ $ticket->rating_comment }}</textarea>
            <button type="submit">إرسال التقييم</button>
        </form>
    @endif
</div></div>
</body>
</html>
