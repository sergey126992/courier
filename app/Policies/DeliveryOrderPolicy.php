<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order\DeliveryOrder;
use App\Models\User;

/**
 * Доступ к заказу на доставку.
 *
 * Роли и владение проверяются здесь, а не в контроллерах. Отмена доступна
 * отправителю своего заказа и диспетчеру — без согласия отправителя (FR-ORD-129).
 */
final class DeliveryOrderPolicy
{
    /**
     * Список заказов: отправитель видит свои, диспетчер — все.
     *
     * Курьер и администратор к чтению заказов на этом этапе не допускаются.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->canCreateOrders() || $actor->role->canDispatch();
    }

    /**
     * Карточка заказа: владелец или диспетчер.
     */
    public function view(User $actor, DeliveryOrder $order): bool
    {
        return $this->isOwner($actor, $order) || $actor->role->canDispatch();
    }

    /**
     * Отмена: владелец заказа или диспетчер, и только до забора посылки.
     *
     * Ограничение по статусу — правило домена: политика проверяет только роль
     * и владение, доменный переход отсечёт отмену после забора.
     */
    public function cancel(User $actor, DeliveryOrder $order): bool
    {
        return $this->isOwner($actor, $order) || $actor->role->canDispatch();
    }

    /**
     * Кандидаты на заказ: только диспетчер (ЧТЗ 8.1, раздел 6).
     *
     * Подбор исполнителя — операционное действие диспетчера: отправитель,
     * курьер и администратор получают отказ, а заказ им не раскрывается.
     */
    public function viewCandidates(User $actor, DeliveryOrder $order): bool
    {
        return $actor->role->canDispatch();
    }

    /**
     * Создание заказа: только роль, которой это разрешено доменом.
     *
     * Подтверждение почты проверяет промежуточный слой verified.api.
     */
    public function create(User $actor): bool
    {
        return $actor->role->canCreateOrders();
    }

    /**
     * Владелец определяется по идентификатору, а не по совпадению объектов.
     */
    private function isOwner(User $actor, DeliveryOrder $order): bool
    {
        return $actor->getKey() !== null && $actor->getKey() === $order->sender_id;
    }
}
