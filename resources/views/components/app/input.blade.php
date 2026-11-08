@props([
    'name' => null,
    'type' => 'text',
    'value' => null,
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

<input
    {{ $attributes->class(['field-control'])->merge([
        'id' => $controlId,
        'name' => $name,
        'type' => $type,
        'value' => $resolvedValue,
        'required' => $required,
        'readonly' => $readonly,
        'disabled' => $disabled,
        'aria-invalid' => $hasError ? 'true' : null,
        'aria-describedby' => $describedBy === [] ? null : implode(' ', $describedBy),
    ]) }}
/>
