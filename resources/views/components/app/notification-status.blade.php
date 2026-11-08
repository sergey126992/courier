{{--
    Статус доставки уведомлений по каналам (FR-NTF-284, ЧТЗ 9.6 п. 3).

    Компактный блок карточки заказа: канал и подпись состояния. Статус читается
    словом, цвет — только дополнение (FR-UI-158, FR-UI-176). Подписи приходят
    из lang через вызывающую карточку: литеральных текстов здесь нет.
    Пустой список — нейтральное состояние, а не ошибка.
--}}
@props([
    'items' => [],
])

<div {{ $attributes->class(['notification-status']) }}>
    @if ($items === [])
        <x-app.empty-state :title="__('orders.labels.notifications_empty')" />
    @else
        <ul class="notification-status-list">
            @foreach ($items as $item)
                <li class="notification-status-item">
                    <span class="notification-status-channel">{{ $item['channel'] }}</span>
                    <span class="notification-status-state" data-state="{{ $item['state'] }}">{{ $item['status'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
