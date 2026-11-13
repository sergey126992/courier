<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dispatch\AcceptAssignment;
use App\Actions\Dispatch\AssignCourier;
use App\Actions\Dispatch\DeliverAssignment;
use App\Actions\Dispatch\FailAssignment;
use App\Actions\Dispatch\PickUpAssignment;
use App\Actions\Dispatch\ReassignOrder;
use App\Domain\Dispatch\AssignmentStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Dispatch\AssignCourierRequest;
use App\Http\Requests\Dispatch\FailAssignmentRequest;
use App\Http\Resources\Dispatch\AssignmentResource;
use App\Http\Resources\Dispatch\AssignmentSummaryResource;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Назначение и переназначение курьера (ЧТЗ 8.1, 8.2).
 *
 * Права проверяет политика AssignmentPolicy в запросе, правила статуса и
 * совместимости — домен и операция. Контроллер только передаёт данные
 * действию и собирает ответ.
 */
final class AssignmentController extends Controller
{
    /**
     * Сколько назначений отдаётся по умолчанию.
     */
    private const DEFAULT_LIMIT = 20;

    /**
     * Максимальный размер страницы списка назначений.
     */
    private const MAX_LIMIT = 100;

    /**
     * Назначение курьера: автоматический подбор или конкретный курьер.
     */
    public function assign(
        AssignCourierRequest $request,
        DeliveryOrder $order,
        AssignCourier $assignCourier,
    ): JsonResponse {
        $assignment = $assignCourier->handle(
            $order,
            $this->currentUser($request),
            $request->courierId(),
            $request->strategy(),
        );

        return $this->respond($assignment, $request);
    }

    /**
     * Переназначение после неудачной попытки: новая попытка, прежняя остаётся в истории.
     */
    public function reassign(
        AssignCourierRequest $request,
        DeliveryOrder $order,
        ReassignOrder $reassignOrder,
    ): JsonResponse {
        $assignment = $reassignOrder->handle(
            $order,
            $this->currentUser($request),
            $request->courierId(),
            $request->strategy(),
        );

        return $this->respond($assignment, $request);
    }

    /**
     * Список назначений курьера: активное первым, затем история (FR-DSP-150).
     *
     * Порядок задаётся выражением по кодам активных статусов: значения берутся
     * из домена, поэтому подстановка в SQL безопасна и не требует
     * плейсхолдеров, а курсор сравнивает те же значения.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Список ведёт только курьер: прочие роли получают 403 (ЧТЗ 8.1).
        if (! $user->can('actAsCourier', Assignment::class)) {
            throw ApiException::forbidden();
        }

        // Ранжирование «активные сверху». selectRaw принимает только literal-string,
        // а привязка значения ломает курсорную пагинацию: она пересобирает условие
        // и теряет соответствие параметров (SQLSTATE 08P01). Совпадение литерала
        // с активными статусами домена проверяется ветвью default.
        $activeRank = match (AssignmentStatus::activeValues()) {
            ['assigned', 'accepted', 'picked_up'] => "(CASE WHEN status IN ('assigned', 'accepted', 'picked_up') THEN 0 ELSE 1 END) AS active_rank",
            default => throw new LogicException('Набор активных статусов назначения изменился: обновите SQL ранжирования в AssignmentController.'),
        };

        $query = Assignment::query()
            ->where('courier_id', $user->getKey())
            ->with('order')
            ->select('assignments.*')
            ->selectRaw($activeRank)
            ->orderBy('active_rank')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');

        $page = $query->cursorPaginate(
            perPage: $this->limit($request),
            cursorName: 'cursor',
        );

        return ApiResponse::json([
            'data' => AssignmentSummaryResource::collection($page->getCollection())->resolve($request),
            'pagination' => [
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /**
     * Карточка назначения (ЧТЗ 8.3): курьеру-владельцу и диспетчеру.
     */
    public function show(Request $request, Assignment $assignment): AssignmentResource
    {
        $user = $this->currentUser($request);

        // Отправитель и администратор операционных данных назначения не видят.
        if (! $user->can('viewAny', Assignment::class)) {
            throw ApiException::forbidden();
        }

        // Чужое назначение неотличимо от несуществующего (FR-DSP-146).
        if (! $user->can('view', $assignment)) {
            throw ApiException::notFound();
        }

        return $this->card($assignment, $request);
    }

    /**
     * Принятие назначения курьером (FR-DSP-141).
     */
    public function accept(
        Request $request,
        Assignment $assignment,
        AcceptAssignment $acceptAssignment,
    ): AssignmentResource {
        $courier = $this->courierFor($request, $assignment, 'accept');

        return $this->card($acceptAssignment->handle($assignment, $courier), $request);
    }

    /**
     * Забор посылки (FR-DSP-142).
     */
    public function pickup(
        Request $request,
        Assignment $assignment,
        PickUpAssignment $pickUpAssignment,
    ): AssignmentResource {
        $courier = $this->courierFor($request, $assignment, 'pickup');

        return $this->card($pickUpAssignment->handle($assignment, $courier), $request);
    }

    /**
     * Вручение посылки (FR-DSP-143).
     */
    public function deliver(
        Request $request,
        Assignment $assignment,
        DeliverAssignment $deliverAssignment,
    ): AssignmentResource {
        $courier = $this->courierFor($request, $assignment, 'deliver');

        return $this->card($deliverAssignment->handle($assignment, $courier), $request);
    }

    /**
     * Неудачная попытка с причиной из перечня (FR-DSP-144).
     */
    public function fail(
        FailAssignmentRequest $request,
        Assignment $assignment,
        FailAssignment $failAssignment,
    ): AssignmentResource {
        $courier = $this->courierFor($request, $assignment, 'fail');

        return $this->card(
            $failAssignment->handle($assignment, $courier, $request->reason(), $request->comment()),
            $request,
        );
    }

    /**
     * Карточка назначения в обёртке data (ЧТЗ 8.3).
     */
    private function card(Assignment $assignment, Request $request): AssignmentResource
    {
        $assignment->loadMissing(['courier.courierProfile', 'order']);

        return AssignmentResource::make($assignment);
    }

    /**
     * Курьер, выполняющий действие по своему назначению (FR-DSP-146).
     *
     * Отказ по роли — 403: диспетчер, администратор и отправитель действий
     * курьера не выполняют. Чужое назначение даёт 404 и неотличимо от
     * несуществующего.
     */
    private function courierFor(Request $request, Assignment $assignment, string $ability): User
    {
        $user = $this->currentUser($request);

        if (! $user->can('actAsCourier', Assignment::class)) {
            throw ApiException::forbidden();
        }

        if (! $user->can($ability, $assignment)) {
            throw ApiException::notFound();
        }

        return $user;
    }

    /**
     * Размер страницы списка: по умолчанию 20, не больше 100.
     */
    private function limit(Request $request): int
    {
        $limit = $request->integer('limit', self::DEFAULT_LIMIT);

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * Ответ по контракту ЧТЗ 8.2: назначение и краткий блок заказа.
     */
    private function respond(Assignment $assignment, Request $request): JsonResponse
    {
        $assignment->loadMissing(['courier.courierProfile', 'order']);

        /** @var DeliveryOrder $order */
        $order = $assignment->order;

        /** @var array<string, mixed> $payload */
        $payload = (new AssignmentResource($assignment))->resolve($request);
        unset($payload['order']);

        return ApiResponse::json([
            'data' => [
                'assignment' => $payload,
                'order' => [
                    'number' => $order->number,
                    'status' => $order->status->value,
                    'status_label' => $order->status->label(),
                ],
            ],
        ]);
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
