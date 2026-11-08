<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Domain\Notifications\Exceptions\NotificationDeliveryFailed;
use App\Domain\Notifications\NotificationTemplate;

/**
 * Адаптер канала push (FR-NTF-253).
 *
 * Контракт отделяет слой отправки от реализации: на этапе M11 единственный
 * провайдер — журнал-симулятор, реальный провайдер (FCM) подключается позже
 * заменой реализации без изменения вызывающего кода (FR-NTF-255).
 *
 * Сбоя сети на M11 нет: провайдер либо фиксирует сообщение, либо сообщает
 * инфраструктурную ошибку записи журнала (FR-NTF-256).
 */
interface PushProvider
{
    /**
     * Отправляет push и возвращает управление после фиксации сообщения.
     *
     * @param int $notificationId строка журнала уведомлений
     * @param int $recipientId получатель как снимок на момент отправки
     * @param NotificationTemplate $template шаблон домена
     * @param string $body текст из lang-шаблона: номер заказа, статус, ссылка трекинга
     *
     * @throws NotificationDeliveryFailed сбой записи сообщения
     */
    public function send(int $notificationId, int $recipientId, NotificationTemplate $template, string $body): void;
}
