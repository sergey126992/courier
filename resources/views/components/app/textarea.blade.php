@props([
    'name' => null,
    'value' => null,
    'rows' => 4,
    'maxlength' => null,
    'id' => null,
    'required' => false,
    'readonly' => false,
    'disabled' => false,
])

@aware([
    'hint' => null,
])

@php
    $errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $controlId = $id ?? $name;
    $hasError = $name !== null && $errorBag->has($name);
    $describedBy = [];

    if (filled($hint) && $controlId !== null) {
        $describedBy[] = $controlId.'-hint';
    }

    if ($hasError && $controlId !== null) {
        $describedBy[] = $controlId.'-error';
    }

    $resolvedValue = $name !== null ? old($name, $value) : $value;
@endphp

<textarea
    {{ $attributes->class(['field-control'])->merge([
        'id' => $controlId,
        'name' => $name,
        'rows' => $rows,
        'maxlength' => $maxlength,
        'required' => $required,
        'readonly' => $readonly,
        'disabled' => $disabled,
        'aria-invalid' => $hasError ? 'true' : null,
        'aria-describedby' => $describedBy === [] ? null : implode(' ', $describedBy),
    ]) }}
>{{ $resolvedValue }}</textarea>
