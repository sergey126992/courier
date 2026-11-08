@props([
    'name' => null,
    'label' => null,
    'hint' => null,
    'required' => false,
    'id' => null,
])

@php
    $errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $controlId = $id ?? $name;
    $hasError = $name !== null && $errorBag->has($name);
    $hintId = $controlId !== null ? $controlId.'-hint' : null;
    $errorId = $controlId !== null ? $controlId.'-error' : null;
@endphp

<div {{ $attributes->class(['field']) }}>
    @if ($label !== null)
        <label @if ($controlId !== null) for="{{ $controlId }}" @endif class="field-label">
            {{ $label }}
            @if ($required)
                <span class="field-required" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if (filled($hint))
        <p class="field-hint" @if ($hintId !== null) id="{{ $hintId }}" @endif>{{ $hint }}</p>
    @endif

    @if ($hasError)
        <p class="field-error" @if ($errorId !== null) id="{{ $errorId }}" @endif role="alert">{{ $errorBag->first($name) }}</p>
    @endif
</div>
