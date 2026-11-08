<?php

declare(strict_types=1);

namespace App\Livewire\Cabinet;

use App\Actions\Geography\SearchAddressDirectory;
use App\Actions\Order\CreateOrder as CreateOrderAction;
use App\Actions\Pricing\EstimateDelivery;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\PriceQuote;
use App\Http\ApiResponse;
use App\Http\Problem\ApiException;
use App\Http\Requests\Order\OrderPayloadRules;
use App\Http\Requests\Pricing\EstimatePayloadRules;
use App\Models\Geography\AddressDirectoryEntry;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\IdempotencyKey;
use App\Models\User;
use App\Services\Order\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Расчёт и создание заказа отправителя (FR-UI-121…123).
 *
 * Расчёт вызывает EstimateDelivery и ничего не сохраняет; создание — CreateOrder.
 * Никакой бизнес-логики здесь нет: компонент собирает проверенные данные формы,
 * показывает результат и переводит доменные отказы в ошибки полей (ЧТЗ 8.5).
 *
 * Ключ идемпотентности создаётся один раз при открытии формы и переиспользуется
 * при повторах: работа с ним идёт через IdempotencyStore, а не через заголовок
 * Idempotency-Key, которого у HTML-формы нет (FR-UI-122).
 */
final class CreateOrder extends Component
{
    /**
     * Строка поиска адреса забора: выбор подсказки подставляет подпись записи.
     */
    public string $pickupQuery = '';

    /**
     * Строка поиска адреса доставки.
     */
    public string $dropoffQuery = '';

    /**
     * Идентификатор выбранной записи справочника адресов для забора.
     *
     * Меняется только сервером в selectPickup()/clearPickup(): клиентская
     * попытка задать значение отклоняется, поэтому подставить чужие
     * координаты через поле формы нельзя.
     */
    #[Locked]
    public ?int $pickupAddressId = null;

    /**
     * Идентификатор выбранной записи справочника адресов для доставки.
     */
    #[Locked]
    public ?int $dropoffAddressId = null;

    /**
     * Подсказки адреса забора: идентификатор записи и подпись для списка.
     *
     * @var list<array{id: int, label: string}>
     */
    public array $pickupSuggestions = [];

    /**
     * Подсказки адреса доставки.
     *
     * @var list<array{id: int, label: string}>
     */
    public array $dropoffSuggestions = [];

    /**
     * Адреса, разрешённые из справочника в текущем запросе.
     *
     * Приватное свойство не переносится между запросами: это снимок базы,
     * который проверка выбора и сборка запроса делят между собой.
     *
     * @var array{pickup: array<string, string>|null, dropoff: array<string, string>|null}|null
     */
    private ?array $resolvedAddresses = null;

    /**
     * Посылка: вес и габариты.
     *
     * @var array{weight_grams: string, dimensions: array{length: string, width: string, height: string}}
     */
    public array $parcel = [
        'weight_grams' => '',
        'dimensions' => [
            'length' => '',
            'width' => '',
            'height' => '',
        ],
    ];

    /**
     * Заявленная стоимость в рублях; пусто — посылка без объявленной ценности.
     */
    public string $declaredValue = '';

    /**
     * Тип транспорта (`TransportType`).
     */
    public string $transport = '';

    /**
     * Вид лёгкого транспорта (`LightVehicleKind`), только для типа «лёгкий».
     */
    public string $lightVehicleKind = '';

    /**
     * Желаемый момент доставки; пусто — текущее время.
     */
    public string $requestedAt = '';

    /**
     * Признак платной парковки при доставке автомобилем.
     */
    public bool $isPaidParking = false;

    /**
     * Ключ идемпотентности попытки: создаётся один раз и не меняется до успеха.
     */
    public string $idempotencyKey = '';

    /**
     * Результат последнего расчёта в скалярной форме для представления.
     *
     * @var array{price: string, price_amount: int, currency: string, eta_minutes: int, distance_km: float, lines: list<array{label: string, amount: string}>}|null
     */
    public ?array $quote = null;

    /**
     * Общая ошибка операции (алерт формы), когда отказать нужно не конкретному полю.
     */
    public ?string $error = null;

    /**
     * Ключ идемпотентности длины UUID: 36 символов, в пределах 16–64 из конфигурации.
     */
    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::uuid();
    }

    /**
     * Ввод строки адреса забора: обновляет подсказки.
     *
     * Ответ поиска применяется только если он относится к текущему значению
     * поля. Пока пользователь печатает, ответы на прошлые термы приходят
     * позже свежих — такой устаревший ответ отбрасывается и подсказки
     * не перезаписывает.
     */
    public function updatedPickupQuery(string $value): void
    {
        if ($value !== $this->pickupQuery) {
            return;
        }

        // Строку изменили после выбора: прежний адрес больше не действует.
        $this->pickupAddressId = null;
        $this->pickupSuggestions = $this->suggestionsFor($value);
    }

    /**
     * Ввод строки адреса доставки: та же защита от устаревшего ответа.
     */
    public function updatedDropoffQuery(string $value): void
    {
        if ($value !== $this->dropoffQuery) {
            return;
        }

        $this->dropoffAddressId = null;
        $this->dropoffSuggestions = $this->suggestionsFor($value);
    }

    /**
     * Выбор подсказки забора: запоминаем запись, показываем её подпись и закрываем список.
     *
     * Ищется только действующая запись: деактивированный адрес выбрать нельзя,
     * и тогда ошибка показывается у поля.
     */
    public function selectPickup(int $id): void
    {
        $entry = AddressDirectoryEntry::query()->active()->find($id);

        if (! $entry instanceof AddressDirectoryEntry) {
            // Прежний выбор больше не соответствует строке поиска: снимаем его,
            // чтобы расчёт не ушёл со старым адресом после ошибки.
            $this->pickupAddressId = null;
            $this->addError('pickup', (string) __('ui.create_order.address_not_selected'));

            return;
        }

        $this->pickupAddressId = (int) $entry->getKey();
        $this->pickupQuery = $entry->label();
        $this->pickupSuggestions = [];
        $this->resetValidation('pickup');
    }

    /**
     * Выбор подсказки доставки.
     */
    public function selectDropoff(int $id): void
    {
        $entry = AddressDirectoryEntry::query()->active()->find($id);

        if (! $entry instanceof AddressDirectoryEntry) {
            // Тот же сброс, что у забора: старый адрес не должен уйти в расчёт.
            $this->dropoffAddressId = null;
            $this->addError('dropoff', (string) __('ui.create_order.address_not_selected'));

            return;
        }

        $this->dropoffAddressId = (int) $entry->getKey();
        $this->dropoffQuery = $entry->label();
        $this->dropoffSuggestions = [];
        $this->resetValidation('dropoff');
    }

    /**
     * Очистка адреса забора: выбор, строка поиска, подсказки и ошибка поля.
     */
    public function clearPickup(): void
    {
        $this->pickupAddressId = null;
        $this->pickupQuery = '';
        $this->pickupSuggestions = [];
        $this->resetValidation('pickup');
    }

    /**
     * Очистка адреса доставки.
     */
    public function clearDropoff(): void
    {
        $this->dropoffAddressId = null;
        $this->dropoffQuery = '';
        $this->dropoffSuggestions = [];
        $this->resetValidation('dropoff');
    }

    /**
     * Предварительный расчёт: цена, прогноз и разбивка без сохранения (FR-UI-121).
     */
    public function estimate(EstimateDelivery $estimate): void
    {
        $this->authorize('estimate-delivery');

        $this->resetValidation();
        $this->error = null;
        $this->quote = null;

        // Адрес не выбран: ошибка у поля, расчёт не запускается (FR-UI-121).
        if (! $this->addressesSelected()) {
            return;
        }

        $data = $this->validated(EstimatePayloadRules::rules($this->payload()));

        try {
            $quote = $estimate->handle($data);
        } catch (ApiException $exception) {
            $this->showDomainError($exception);

            return;
        }

        $this->quote = $this->quoteToArray($quote);
    }

    /**
     * Создание заказа с переиспользованием ключа идемпотентности (FR-UI-122).
     *
     * Повтор с тем же ключом и теми же данными не создаёт второй заказ: он либо
     * возвращает сохранённый результат, либо сообщает о выполняющемся запросе.
     */
    public function create(CreateOrderAction $createOrder, IdempotencyStore $store): void
    {
        $this->authorize('create', DeliveryOrder::class);

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $this->resetValidation();
        $this->error = null;

        // Адрес не выбран: ошибка у поля до обращения к Actions, ключ не занимается.
        if (! $this->addressesSelected()) {
            return;
        }

        $data = $this->validated(OrderPayloadRules::rules($this->payload()));

        $existing = $store->find($user, $this->idempotencyKey);

        if ($existing !== null) {
            $this->replay($existing, $data, $store);

            return;
        }

        $record = $store->reserve($user, $this->idempotencyKey, $data);

        if ($record === null) {
            // Параллельный запрос успел занять ключ (FR-ORD-110).
            $this->error = (string) __('errors.web.IDEMPOTENCY_KEY_IN_PROGRESS');

            return;
        }

        try {
            $order = $createOrder->handle($user, $data);
        } catch (ApiException $exception) {
            // Неудачная попытка освобождает ключ: повторить можно с тем же ключом.
            $store->release($record);
            $this->showDomainError($exception);

            return;
        } catch (Throwable $exception) {
            $store->release($record);

            throw $exception;
        }

        $store->complete($record, ApiResponse::json(['order_id' => $order->getKey()], Response::HTTP_CREATED));

        $this->succeed((int) $order->getKey());
    }

    public function render(): View
    {
        return view('livewire.cabinet.create-order', [
            'transportOptions' => $this->transportOptions(),
            'lightVehicleOptions' => $this->lightVehicleOptions(),
        ]);
    }

    /**
     * Повтор формы: тот же заказ или сообщение о незавершённой отправке.
     *
     * @param array<string, mixed> $data проверенные данные текущей формы
     */
    private function replay(IdempotencyKey $record, array $data, IdempotencyStore $store): void
    {
        if (! $store->matches($record, $data)) {
            $this->error = (string) __('errors.web.IDEMPOTENCY_KEY_CONFLICT');

            return;
        }

        $response = $store->storedResponse($record);
        $orderId = $response instanceof JsonResponse ? $this->storedOrderId($response) : null;

        if ($orderId === null) {
            $this->error = (string) __('errors.web.IDEMPOTENCY_KEY_IN_PROGRESS');

            return;
        }

        $this->succeed($orderId);
    }

    /**
     * Идентификатор заказа из сохранённого ответа на повтор.
     */
    private function storedOrderId(JsonResponse $response): ?int
    {
        /** @var array<string, mixed> $body */
        $body = (array) $response->getData(true);
        $orderId = $body['order_id'] ?? null;

        return is_int($orderId) ? $orderId : (is_string($orderId) && ctype_digit($orderId) ? (int) $orderId : null);
    }

    /**
     * Успех: сообщение и переход на карточку заказа (FR-UI-123, ЧТЗ 8.4).
     */
    private function succeed(int $orderId): void
    {
        session()->flash('status', (string) __('orders.messages.created'));

        // Карточка заказа появится в следующем пункте плана: адрес строится по имени.
        $this->redirectRoute('cabinet.orders.show', ['order' => $orderId]);
    }

    /**
     * Проверка общими правилами: сообщения остаются у полей формы (ЧТЗ 8.5).
     *
     * @param array<string, array<int, mixed>> $rules
     * @return array<string, mixed>
     */
    private function validated(array $rules): array
    {
        /** @var array<string, mixed> $data */
        $data = Validator::make($this->payload(), $rules)->validate();

        return $data;
    }

    /**
     * Данные формы в форме запроса API: та же структура адресов и посылки.
     *
     * Адреса собираются по сохранённым идентификаторам записей справочника,
     * а не из свойств клиента: координаты в запрос попадают только из базы.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $addresses = $this->resolveAddresses();

        $payload = [
            'transport' => $this->transport,
            'light_vehicle_kind' => $this->lightVehicleKind === '' ? null : $this->lightVehicleKind,
            'pickup' => $addresses['pickup'] ?? [],
            'dropoff' => $addresses['dropoff'] ?? [],
            'parcel' => $this->parcelPayload(),
            'is_paid_parking' => $this->isPaidParking,
        ];

        // Пустое поле означает текущее время, а не пустую дату в запросе.
        if (trim($this->requestedAt) !== '') {
            $payload['requested_at'] = $this->requestedAt;
        }

        return $payload;
    }

    /**
     * Посылка: заявленная стоимость попадает в запрос только при заполнении.
     *
     * @return array<string, mixed>
     */
    private function parcelPayload(): array
    {
        /** @var array{length: string, width: string, height: string} $dimensions */
        $dimensions = $this->parcel['dimensions'];

        $parcel = [
            'weight_grams' => $this->parcel['weight_grams'],
            'dimensions' => [
                'length' => $dimensions['length'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
            ],
        ];

        if (trim($this->declaredValue) !== '') {
            $parcel['declared_value'] = [
                'amount' => $this->declaredValue,
                'currency' => (string) config('pricing.currency'),
            ];
        }

        return $parcel;
    }

    /**
     * Доменный отказ: ошибка у поля или общий алерт формы (ЧТЗ 8.5).
     */
    private function showDomainError(ApiException $exception): void
    {
        $field = $this->errorField($exception);

        if ($field !== null) {
            /** @var array{field: string, message: string} $detail */
            $detail = $exception->details[0];
            $this->addError($field, $detail['message']);

            return;
        }

        $key = 'errors.web.'.$exception->errorCode->value;
        $this->error = trans()->has($key) ? (string) trans($key) : (string) __('ui.create_order.failed');
    }

    /**
     * Поле формы для отказа домена; null — сообщение показывается общим алертом.
     */
    private function errorField(ApiException $exception): ?string
    {
        $detail = $exception->details[0] ?? null;

        if (! is_array($detail) || ! isset($detail['field']) || ! is_string($detail['field'])) {
            return null;
        }

        // Расстояние — ограничение транспорта, поэтому ошибка стоит у его поля.
        return match ($detail['field']) {
            'pickup' => 'pickup',
            'dropoff' => 'dropoff',
            'parcel.weight_grams' => 'parcel.weight_grams',
            'transport', 'distance_km' => 'transport',
            default => null,
        };
    }

    /**
     * Результат расчёта в скалярной форме: объекты домена Livewire не переносит.
     *
     * @return array{price: string, price_amount: int, currency: string, eta_minutes: int, distance_km: float, lines: list<array{label: string, amount: string}>}
     */
    private function quoteToArray(PriceQuote $quote): array
    {
        return [
            'price' => $quote->total()->format(),
            'price_amount' => $quote->total()->amount(),
            'currency' => $quote->total()->currency(),
            'eta_minutes' => $quote->etaMinutes(),
            'distance_km' => $quote->distance()->kilometers(),
            'lines' => array_map(
                static fn (BreakdownLine $line): array => [
                    'label' => $line->label(),
                    'amount' => $line->amount()->format(),
                ],
                $quote->lines(),
            ),
        ];
    }

    /**
     * Подсказки адреса по строке поиска.
     *
     * Слишком короткий запрос не ищется: подсказка по одному символу бесполезна
     * и дала бы полный список справочника.
     *
     * @return list<array{id: int, label: string}>
     */
    private function suggestionsFor(string $query): array
    {
        if (mb_strlen(trim($query)) < 2) {
            return [];
        }

        return array_values(
            app(SearchAddressDirectory::class)
                ->handle($query)
                ->map(static fn (array $entry): array => [
                    'id' => $entry['id'],
                    'label' => $entry['label'],
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * Проверка выбора обоих адресов до обращения к Actions (FR-UI-121).
     *
     * Если адрес не выбран, запись не найдена или деактивирована, ошибка
     * ставится у его поля: расчёт и создание не запускаются, ключ
     * идемпотентности не занимается.
     */
    private function addressesSelected(): bool
    {
        $addresses = $this->resolveAddresses();
        $selected = true;

        foreach (['pickup', 'dropoff'] as $field) {
            if ($addresses[$field] === null) {
                $this->addError($field, (string) __('ui.create_order.address_not_selected'));
                $selected = false;
            }
        }

        return $selected;
    }

    /**
     * Адреса из справочника, разрешённые в текущем запросе.
     *
     * Мемоизация не даёт читать записи дважды: проверка выбора и сборка
     * запроса используют один и тот же снимок базы.
     *
     * @return array{pickup: array<string, string>|null, dropoff: array<string, string>|null}
     */
    private function resolveAddresses(): array
    {
        return $this->resolvedAddresses ??= [
            'pickup' => $this->addressPayload($this->pickupAddressId),
            'dropoff' => $this->addressPayload($this->dropoffAddressId),
        ];
    }

    /**
     * Адрес в форме запроса по идентификатору записи справочника.
     *
     * Район, улица и дом берутся как есть, а координаты — строкой с шестью
     * знаками после запятой: так они проходят правило decimal:0,6 общих
     * правил заказа. Клиентские координаты не используются.
     *
     * @return array{district: string, street: string, house: string, latitude: string, longitude: string}|null
     */
    private function addressPayload(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $entry = AddressDirectoryEntry::query()->active()->find($id);

        if (! $entry instanceof AddressDirectoryEntry) {
            return null;
        }

        return [
            'district' => (string) $entry->district,
            'street' => (string) $entry->street,
            'house' => (string) $entry->house,
            'latitude' => number_format((float) $entry->latitude, 6, '.', ''),
            'longitude' => number_format((float) $entry->longitude, 6, '.', ''),
        ];
    }

    /**
     * Варианты типа транспорта для списка.
     *
     * @return array<string, string>
     */
    private function transportOptions(): array
    {
        $options = [];

        foreach (TransportType::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Варианты вида лёгкого транспорта для списка.
     *
     * @return array<string, string>
     */
    private function lightVehicleOptions(): array
    {
        $options = [];

        foreach (LightVehicleKind::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
