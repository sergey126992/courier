<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Роль пользователя в системе.
 */
enum Role: string
{
    case Sender = 'sender';
    case Courier = 'courier';
    case Dispatcher = 'dispatcher';
    case Admin = 'admin';

    /**
     * Человекочитаемое название роли для интерфейса.
     */
    public function label(): string
    {
        return match ($this) {
            self::Sender => 'Отправитель',
            self::Courier => 'Курьер',
            self::Dispatcher => 'Диспетчер',
            self::Admin => 'Администратор',
        };
    }

    /**
     * Может ли роль создавать заказы на доставку.
     */
    public function canCreateOrders(): bool
    {
        return $this === self::Sender;
    }

    /**
     * Может ли роль назначать курьеров на заявки.
     */
    public function canDispatch(): bool
    {
        return $this === self::Dispatcher;
    }

    /**
     * Может ли роль вести назначенные заявки (двигать статусы доставки).
     */
    public function canHandleAssignments(): bool
    {
        return $this === self::Courier;
    }

    /**
     * Может ли роль управлять справочниками и отчётами.
     */
    public function canManageReferences(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Может ли роль смотреть отчёт по заказам, выручке и загрузке курьеров.
     *
     * Отчёт — административный инструмент: остальным ролям он не раскрывается
     * и не фильтруется под роль (FR-RPT-103, FR-RPT-105).
     */
    public function canViewReports(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Может ли роль пользоваться административными страницами уведомлений.
     *
     * Журнал необработанных, повтор и симулятор push — административный
     * инструмент переходного периода M11 (ЧТЗ 6, 8.2, FR-NTF-293).
     * Abilities токенов от этого не меняются: доступ проверяет Gate.
     */
    public function canAdministerNotifications(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Есть ли у роли профиль отправителя с телефоном, компанией и адресом.
     *
     * Профиль отправителя создаётся вместе с аккаунтом, поэтому эти поля
     * правит только сама роль «отправитель».
     */
    public function hasSenderProfile(): bool
    {
        return $this === self::Sender;
    }

    /**
     * Может ли роль менять собственные данные через API профиля.
     *
     * Отправитель управляет телефоном, компанией и адресом по умолчанию;
     * курьеру доступно только имя, потому что тип транспорта и активность
     * назначает администратор. Диспетчер и администратор личного профиля не имеют.
     */
    public function canEditOwnProfile(): bool
    {
        return $this === self::Sender || $this === self::Courier;
    }

    /**
     * Разрешения токена доступа, которые получает роль.
     *
     * Набор этапа M1: чтение и запись своих заказов, операционные действия
     * диспетчера, действия курьера. Администратору остаётся базовое чтение
     * заказов и просмотр отчёта: операционные заказы он не ведёт.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        $abilities = ['orders:read'];

        if ($this->canCreateOrders()) {
            $abilities[] = 'orders:write';
        }

        if ($this->canDispatch()) {
            $abilities[] = 'dispatch';
        }

        if ($this->canHandleAssignments()) {
            $abilities[] = 'courier';
        }

        if ($this->canViewReports()) {
            $abilities[] = 'reports:read';
        }

        return $abilities;
    }
}
