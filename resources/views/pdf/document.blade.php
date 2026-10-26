<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 36px 40px 48px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; line-height: 1.45; color: #1f2937; }
        h1 { font-size: 20px; color: #047857; margin: 0 0 6px; border-bottom: 2px solid #047857; padding-bottom: 6px; }
        h2 { font-size: 14px; color: #065f46; margin: 18px 0 6px; page-break-after: avoid; }
        h3 { font-size: 11.5px; margin: 12px 0 4px; page-break-after: avoid; }
        p { margin: 4px 0 6px; }
        ul, ol { margin: 4px 0 8px 16px; padding: 0; }
        li { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        th { background: #047857; color: #fff; text-align: left; padding: 4px 5px; font-size: 8.5px; }
        td { border-bottom: 1px solid #e5e7eb; padding: 3px 5px; vertical-align: top; font-size: 8.5px; }
        code { font-family: DejaVu Sans Mono, monospace; font-size: 8px; background: #f3f4f6; padding: 0 2px; }
        pre { background: #f3f4f6; border-left: 3px solid #047857; padding: 6px 8px; white-space: pre-wrap; margin: 6px 0 10px; }
        pre code { background: none; padding: 0; }
        blockquote { margin: 8px 0; padding: 6px 10px; background: #ecfdf5; border-left: 3px solid #10b981; }
        blockquote p { margin: 0; }
        hr { border: 0; border-top: 1px solid #d1d5db; margin: 14px 0; }
        a { color: #047857; text-decoration: none; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="footer">{{ $title }} · Journal System · {{ now()->format('d/m/Y') }}</div>
    {!! $html !!}
</body>
</html>
