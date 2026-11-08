<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ __('mail.reset.subject') }}</title>
</head>
<body>
    <p>{{ __('mail.reset.greeting', ['name' => $name]) }}</p>
    <p>{{ __('mail.reset.intro') }}</p>
    <p><a href="{{ $url }}">{{ __('mail.reset.action') }}</a></p>
    <p>{{ __('mail.reset.expires', ['minutes' => $expireMinutes]) }}</p>
    <p>{{ __('mail.reset.ignore') }}</p>
    <p>{{ __('mail.reset.signature') }}</p>
</body>
</html>
