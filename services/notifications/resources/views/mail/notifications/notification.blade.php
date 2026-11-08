<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $lines['subject'] ?? '' }}</title>
</head>
<body>
    @isset($lines['greeting'])
        <p>{{ $lines['greeting'] }}</p>
    @endisset
    @isset($lines['body'])
        <p>{{ $lines['body'] }}</p>
    @endisset
    @isset($lines['tracking'])
        <p>{{ $lines['tracking'] }}</p>
    @endisset
    @isset($lines['signature'])
        <p>{{ $lines['signature'] }}</p>
    @endisset
</body>
</html>
