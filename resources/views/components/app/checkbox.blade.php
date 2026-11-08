@props([
    'name' => null,
    'value' => '1',
    'checked' => false,
    'label' => null,
    'id' => null,
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

    $oldValue = $name !== null ? old($name) : null;
    $isChecked = $oldValue !== null ? (string) $oldValue === (string) $value : (bool) $checked;
@endphp

<div class="field">
    <label class="checkbox min-h-11 items-center" @if ($controlId !== null) for="{{ $controlId }}" @endif>
        <input
            {{ $attributes->class(['checkbox-control'])->merge([
                'type' => 'checkbox',
                'id' => $controlId,
                'name' => $name,
                'value' => $value,
                'checked' => $isChecked,
                'disabled' => $disabled,
                'aria-invalid' => $hasError ? 'true' : null,
                'aria-describedby' => $describedBy === [] ? null : implode(' ', $describedBy),
            ]) }}
        />
        <span class="checkbox-label">{{ $label ?? $slot }}</span>
    </label>

    @if ($hasError)
        <p class="field-error" @if ($controlId !== null) id="{{ $controlId }}-error" @endif role="alert">{{ $errorBag->first($name) }}</p>
    @endif
</div>
