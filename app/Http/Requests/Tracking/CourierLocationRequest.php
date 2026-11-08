<?php

declare(strict_types=1);

namespace App\Http\Requests\Tracking;

/**
 * Тело heartbeat позиции курьера (ЧТЗ 8.4).
 *
 * Поля и правила те же, что у геоточки назначения: различаются маршрут,
 * лимит частоты и операция. Наследование фиксирует общий контракт явно,
 * чтобы набор полей не разошёлся между двумя ручками.
 */
final class CourierLocationRequest extends TrackingPointRequest {}
