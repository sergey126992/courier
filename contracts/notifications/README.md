# Контракты событий оповещений (M11)

Каталог `courier/contracts/notifications/` — единственный экземпляр схем событий
(FR-NTF-221). Монолит и сервис оповещений читают одни и те же файлы: копий нет,
поэтому расхождение форматов обнаруживается контрактным тестом одной из сторон
до выпуска (FR-NTF-228).

## Соответствие «контракт — event_type — поток»

| Файл | Контракт | `event_type` | Поток | Направление |
|---|---|---|---|---|
| `order-created.v1.json` | `OrderCreated.v1` | `order.created` | `events.orders` | монолит → сервис |
| `order-assigned.v1.json` | `OrderAssigned.v1` | `order.assigned` | `events.orders` | монолит → сервис |
| `order-picked-up.v1.json` | `OrderPickedUp.v1` | `order.picked_up` | `events.orders` | монолит → сервис |
| `order-delivered.v1.json` | `OrderDelivered.v1` | `order.delivered` | `events.orders` | монолит → сервис |
| `order-failed.v1.json` | `OrderFailed.v1` | `order.failed` | `events.orders` | монолит → сервис |
| `user-contact-changed.v1.json` | `UserContactChanged.v1` | `user.contact_changed` | `events.contacts` | монолит → сервис |
| `notification-sent.v1.json` | `NotificationSent.v1` | `notification.sent` | `events.notifications` | сервис → монолит |
| `notification-failed.v1.json` | `NotificationFailed.v1` | `notification.failed` | `events.notifications` | сервис → монолит |

Имя файла выводится из `event_type`: точки заменяются дефисами, добавляется
версия (`order.picked_up` → `order-picked-up.v1.json`). Переименование файла и
контракта запрещено (ЧТЗ 8.3, п. 2).

## Правила схем

- JSON Schema draft 2020-12: `required` перечисляет обязательные поля,
  `additionalProperties: true` допускает лишние поля (FR-NTF-222).
- `schema_version` — константа `v1`, `event_type` — константа в нотации
  `dot.case` (FR-NTF-224). Служебные поля `x-event-type`, `x-schema-version`,
  `x-stream` дублируют эти сведения для читателя-человека и тестов.
- Схема описывает конверт (`message_id`, `event_type`, `schema_version`,
  `correlation_id`, `occurred_at`, `aggregate_type`, `aggregate_id`) и объект
  `payload` (ЧТЗ 8.4). `correlation_id` допускает `null` у исторических строк
  Outbox, у которых идентификатора запроса не было.
- `order.cancelled` и события смен курьера контрактов M11 не имеют: политика
  отправки по ним не расширяется (ЧТЗ 8.3, п. 4). Публикатор такие события
  не проверяет по схеме.
- Событие `user.contact_changed` — единственный контракт с персональными
  данными: только поток `events.contacts`, без журналирования payload
  (FR-NTF-232, FR-NTF-235).

## Совместимость и выпуск версии

- Совместимость только аддитивная (FR-NTF-226): новое обязательное поле или
  изменение смысла существующего вводит новый файл `*.v2.json`; файл `*.v1.json`
  остаётся до миграции потребителей.
- Порядок выпуска: сначала добавляется `*.v2.json`, затем потребитель учится
  выбирать схему по `event_type` + `schema_version`, затем публикатор начинает
  писать новую версию, и только после миграции старый файл удаляется.
- Неизвестный тип или версия не роняет потребителя: сообщение помечается
  `invalid`, подтверждается и не блокирует поток (ЧТЗ 8.4).

## Проверка в монолите

- Валидатор `App\Services\Events\JsonSchemaValidator` читает каталог из
  конфигурации `events.schema_path` (по умолчанию `contracts/notifications`,
  переменная окружения `EVENTS_SCHEMA_PATH`).
- Публикатор Outbox проверяет payload по схеме до передачи в транспорт
  (FR-NTF-225). Несовпадение не теряет событие: строка остаётся в
  `outbox_messages` с `last_error` (`EVENT_CONTRACT_INVALID` либо
  `EVENT_CONTRACT_UNKNOWN`) и видна в `outbox:failed`.
- Контрактные тесты монолита: `tests/Feature/Async/JsonSchemaContractTest.php`
  и `tests/Feature/Async/EventContractTest.php`.
