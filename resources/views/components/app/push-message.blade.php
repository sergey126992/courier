@props([
    'message' => [],
])

{{-- Сообщение симулятора push: идентификатор, тело и момент (FR-NTF-254). --}}
<article {{ $attributes->class(['push-message', 'card']) }}>
    <p class="push-message-meta">
        {{ __('notifications.admin.notification_id') }}: {{ $message['notification_id'] ?? '' }}
    </p>

    <p class="push-message-body">{{ $message['body'] ?? '' }}</p>

    @if (filled($message['sent_at_label'] ?? null))
        <p class="push-message-meta">{{ $message['sent_at_label'] }}</p>
    @endif
</article>
