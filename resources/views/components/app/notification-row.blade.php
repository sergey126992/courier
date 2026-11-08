@props([
    'row' => [],
])

{{-- Строка журнала уведомлений администратора: без персональных данных (ЧТЗ 8.2). --}}
<article {{ $attributes->class(['notification-row', 'card']) }}>
    <div class="notification-row-head">
        <p class="notification-row-title">{{ __('notifications.admin.entry_title', ['id' => $row['id'] ?? '', 'event' => $row['event_type'] ?? '']) }}</p>

        <x-app.status-badge :status="$row['status'] ?? null" :label="$row['status_label'] ?? null" />
    </div>

    <dl class="notification-row-meta">
        <div>
            <dt>{{ __('notifications.admin.channel') }}</dt>
            <dd>{{ $row['channel_label'] ?? '' }}</dd>
        </div>
        <div>
            <dt>{{ __('notifications.admin.attempts') }}</dt>
            <dd>{{ $row['attempts'] ?? 0 }}</dd>
        </div>

        @if (filled($row['error_code'] ?? null))
            <div>
                <dt>{{ __('notifications.admin.error_code') }}</dt>
                <dd>{{ $row['error_code'] }}</dd>
            </div>
        @endif

        @if (filled($row['created_at_label'] ?? null))
            <div>
                <dt>{{ __('notifications.admin.created_at') }}</dt>
                <dd>{{ $row['created_at_label'] }}</dd>
            </div>
        @endif
    </dl>

    @isset($action)
        <div class="notification-row-action">{{ $action }}</div>
    @endisset
</article>
