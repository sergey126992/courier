@props([
    'name' => null,
    'options' => [],
    'value' => null,
    'placeholder' => null,
    'id' => null,
    'required' => false,
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
    $optionsList = is_array($options) ? $options : (array) $options;
@endphp

<select
    {{ $attributes->class(['field-control'])->merge([
        'id' => $controlId,
        'name' => $name,
        'required' => $required,
        'disabled' => $disabled,
        'aria-invalid' => $hasError ? 'true' : null,
        'aria-describedby' => $describedBy === [] ? null : implode(' ', $describedBy),
    ]) }}
>
    @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach ($optionsList as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}" @if ((string) $resolvedValue === (string) $optionValue) selected @endif>{{ $optionLabel }}</option>
    @endforeach
</select>
