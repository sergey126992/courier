<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Geography\StoreZoneRequest;
use App\Http\Requests\Geography\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Справочник зон обслуживания: список, создание и изменение.
 *
 * Права целиком определяет политика ServiceZonePolicy, поэтому в контроллере
 * нет проверок роли. Удаления нет: зона деактивируется (FR-GEO-105).
 */
final class ZoneController extends Controller
{
    /**
     * Список зон с фильтром по активности.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Список зон — только администратору: политика, а не проверка роли по месту.
        if (! $user instanceof User || ! $user->can('viewAny', ServiceZone::class)) {
            throw ApiException::forbidden();
        }

        /** @var array<string, mixed> $filters */
        $filters = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $zones = ServiceZone::query()
            ->when(
                array_key_exists('is_active', $filters),
                static fn (Builder $query): Builder => $query->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy('code')
            ->get();

        return ApiResponse::json(['data' => ZoneResource::collection($zones)->resolve($request)]);
    }

    /**
     * Создание зоны: код задаётся один раз и дальше не меняется.
     */
    #[ResponseAttribute(status: Response::HTTP_CREATED, description: 'Зона создана; Location указывает на неё.')]
    public function store(StoreZoneRequest $request): ZoneResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $zone = ServiceZone::query()->create($data);

        return ZoneResource::make($zone)
            ->withStatus(Response::HTTP_CREATED)
            ->withHeaders(['Location' => url('/api/v1/zones/'.$zone->getKey())]);
    }

    /**
     * Изменение и деактивация зоны.
     */
    public function update(UpdateZoneRequest $request, ServiceZone $zone): ZoneResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $zone->fill($data)->save();

        return ZoneResource::make($zone->refresh());
    }
}
