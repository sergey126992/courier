<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ __('mail.verify.subject') }}</title>
</head>
<body>
    <p>{{ __('mail.verify.greeting', ['name' => $name]) }}</p>
    <p>{{ __('mail.verify.intro') }}</p>
    <p><a href="{{ $url }}">{{ __('mail.verify.action') }}</a></p>
    <p>{{ __('mail.verify.expires', ['minutes' => $expireMinutes]) }}</p>
    <p>{{ __('mail.verify.ignore') }}</p>
    <p>{{ __('mail.verify.signature') }}</p>
</body>
</html>
