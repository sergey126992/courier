<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dispatch\FindCouriers;
use App\Actions\Order\CancelOrder;
use App\Actions\Order\CreateOrder;
use App\Domain\Dispatch\CourierCandidate;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Dispatch\ListCandidatesRequest;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Http\Resources\Dispatch\CourierCandidateResource;
use App\Http\Resources\OrderDetailsResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderSummaryResource;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Services\Order\OrderListQuery;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заказы на доставку: создание, отмена, список и карточка.
 *
 * Права проверяет политика DeliveryOrderPolicy, ограничения статуса — домен.
 * Контроллер только передаёт проверенные данные действиям и собирает ответ.
 */
final class OrderController extends Controller
{
    /**
     * Создание заказа: идемпотентность и лимит частоты — на маршруте.
     */
    #[ResponseAttribute(status: Response::HTTP_CREATED, description: 'Заказ создан; Location указывает на карточку.')]
    public function store(CreateOrderRequest $request, CreateOrder $createOrder): OrderResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $order = $createOrder->handle($this->currentUser($request), $data);

        return OrderResource::make($order)
            ->withStatus(Response::HTTP_CREATED)
            ->withHeaders(['Location' => url('/api/v1/orders/'.$order->getKey())]);
    }

    /**
     * Отмена заказа отправителем или диспетчером.
     */
    public function cancel(CancelOrderRequest $request, DeliveryOrder $order, CancelOrder $cancelOrder): OrderResource
    {
        $user = $this->currentUser($request);

        if (! $user->can('cancel', $order)) {
            // Чужой заказ неотличим от несуществующего (FR-ORD-135).
            throw ApiException::notFound();
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $reason = $data['reason'] ?? null;

        $cancelled = $cancelOrder->handle($order, $user, is_string($reason) ? $reason : null);

        return OrderResource::make($cancelled->refresh());
    }

    /**
     * Кандидаты на заказ для диспетчера (ЧТЗ 8.2, п. 6).
     *
     * Порядок задаёт применённое правило подбора, поэтому в meta возвращается
     * фактическое значение правила: клиент не угадывает настройку (FR-DSP-125).
     */
    public function candidates(
        ListCandidatesRequest $request,
        DeliveryOrder $order,
        FindCouriers $findCouriers,
    ): JsonResponse {
        $result = $findCouriers->handle($order, $request->strategy());

        return ApiResponse::json([
            'data' => array_map(
                static fn (CourierCandidate $candidate): array => (new CourierCandidateResource($candidate))->resolve($request),
                $result->candidates(),
            ),
            'meta' => [
                'strategy' => $result->strategy()->value,
                'order_eta_minutes' => $result->orderEtaMinutes(),
            ],
        ]);
    }

    /**
     * Список заказов с фильтрами и курсорной пагинацией (ЧТЗ 8.3).
     *
     * Выборку, доступность по роли и устойчивый порядок собирает
     * OrderListQuery (FR-UI-191): форма ответа остаётся здесь.
     */
    public function index(ListOrdersRequest $request, OrderListQuery $orders): JsonResponse
    {
        /** @var array<string, mixed> $filters */
        $filters = $request->validated();

        $page = $orders->paginate(
            $orders->query($this->currentUser($request), $filters),
            $request->limit(),
            $request->newestFirst(),
        );

        return ApiResponse::json([
            'data' => OrderSummaryResource::collection($page->getCollection())->resolve($request),
            'pagination' => [
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /**
     * Карточка заказа с историей статусов.
     */
    public function show(Request $request, DeliveryOrder $order): OrderDetailsResource
    {
        $user = $this->currentUser($request);

        // Ролям без доступа к заказам — 403, чужой заказ — 404 (FR-ORD-135).
        if (! $user->can('viewAny', DeliveryOrder::class)) {
            throw ApiException::forbidden();
        }

        if (! $user->can('view', $order)) {
            throw ApiException::notFound();
        }

        // История и её авторы загружаются заранее: карточка не порождает N+1.
        $order->loadMissing(['statusHistory.changedBy']);

        return OrderDetailsResource::make($order);
    }

    /**
     * Текущий пользователь: маршруты закрыты auth:sanctum, поэтому он всегда есть.
     */
    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
