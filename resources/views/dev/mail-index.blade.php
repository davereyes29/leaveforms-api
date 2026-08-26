<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail previews</title>
    <style>
        body { font-family: sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; color: #111; }
        p { color: #444; }
        li { margin: 0.4rem 0; }
    </style>
</head>
<body>
    <h1>Leave mail previews</h1>
    <p>Local only. Opens the rendered email in this browser. Nothing is sent.</p>
    <p>Uses the latest leave (and conversion, if any) in the database. If none exist, sample data is used.</p>
    <ul>
        @foreach ($templates as $slug => $label)
            <li><a href="{{ url('/dev/mail/'.$slug) }}">{{ $label }}</a></li>
        @endforeach
    </ul>
</body>
</html>
