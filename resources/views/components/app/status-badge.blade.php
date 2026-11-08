@props([
    'status' => null,
    'label' => null,
])

@php
    $tones = [
        'created' => 'info',
        'assigned' => 'info',
        'accepted' => 'warning',
        'picked_up' => 'warning',
        'delivered' => 'success',
        'closed' => 'success',
        'cancelled' => 'error',
        'failed' => 'error',
        'queued' => 'warning',
        'sent' => 'success',
        'error' => 'error',
        // Статусы привязки канала (FR-UI-404): «не привязан» остаётся нейтральным.
        'pending' => 'warning',
        'verified' => 'success',
        'revoked' => 'error',
    ];

    $key = $status instanceof \App\Domain\Order\OrderStatus ? $status->value : (string) $status;
    $text = $label
        ?? ($status instanceof \App\Domain\Order\OrderStatus
            ? $status->label()
            : (\App\Domain\Order\OrderStatus::tryFrom($key)?->label() ?? $key));
    $tone = $tones[$key] ?? null;
@endphp

@if (filled($text))
    <span {{ $attributes->class(['badge', $tone ? 'badge-'.$tone : null]) }}>{{ $text }}</span>
@endif
