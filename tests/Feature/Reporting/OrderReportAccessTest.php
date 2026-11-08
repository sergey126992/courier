<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Адрес отчёта за фиксированный период: ноябрь 2026 года.
 */
function reportAccessUrl(): string
{
    return '/api/v1/reports/orders?'.http_build_query([
        'from' => '2026-11-01T00:00:00+03:00',
        'to' => '2026-12-01T00:00:00+03:00',
    ]);
}

/**
 * Проверяет единый формат ошибки problem+json.
 *
 * @param TestResponse<Response> $response
 */
function reportAccessProblem(TestResponse $response, string $code, int $status): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'code', 'details'])
        ->assertJsonPath('code', $code);
}

it('отдаёт администратору отчёт в структуре ЧТЗ 8.3', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create());

    $response = $this->getJson(reportAccessUrl())->assertOk();

    $response->assertJsonStructure([
        'period' => ['from', 'to', 'days'],
        'currency',
        'totals' => [
            'orders_created',
            'orders_cancelled',
            'orders_delivered',
            'revenue',
            'average_check',
            'average_eta_minutes',
            'active_couriers',
            'deliveries_per_courier',
        ],
        'by_transport' => [
            '*' => ['transport_type', 'orders_created', 'orders_delivered', 'revenue', 'share_percent', 'rank'],
        ],
        'couriers' => ['active_couriers', 'deliveries_per_courier', 'shift_hours'],
    ]);

    expect($response->json('period.from'))->toBe('2026-11-01T00:00:00+03:00')
        ->and($response->json('period.to'))->toBe('2026-12-01T00:00:00+03:00')
        ->and($response->json('period.days'))->toBe(30)
        ->and($response->json('currency'))->toBe((string) config('reporting.currency'))
        ->and($response->json('totals.orders_created'))->toBe(0)
        ->and($response->json('totals.average_check'))->toBeNull()
        ->and($response->json('totals.deliveries_per_courier'))->toBeNull();
});

it('всегда отдаёт три строки разреза в доменном порядке', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create());

    $rows = (array) $this->getJson(reportAccessUrl())->assertOk()->json('by_transport');

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'transport_type'))->toBe(['on_foot', 'light', 'car'])
        // Доля пустого периода — ноль: JSON не различает 0 и 0.0.
        ->and(array_column($rows, 'share_percent'))->toEqual([0.0, 0.0, 0.0]);
});

it('отказывает ролям без права отчёта', function (string $state): void {
    $user = match ($state) {
        'sender' => User::factory()->sender()->create(),
        'courier' => User::factory()->courier()->create(),
        'dispatcher' => User::factory()->dispatcher()->create(),
        default => throw new InvalidArgumentException(sprintf('Неизвестная роль: %s.', $state)),
    };

    Sanctum::actingAs($user);

    reportAccessProblem($this->getJson(reportAccessUrl()), 'FORBIDDEN', 403);
})->with(['sender', 'courier', 'dispatcher']);

it('отказывает запросу без токена', function (): void {
    reportAccessProblem($this->getJson(reportAccessUrl()), 'INVALID_CREDENTIALS', 401);
});

it('отказывает отозванному токену', function (): void {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('api-v1', $admin->role->abilities())->plainTextToken;

    // Отзыв токена: запись удаляется из хранилища, значение перестаёт приниматься.
    $admin->tokens()->delete();

    reportAccessProblem(
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson(reportAccessUrl()),
        'INVALID_CREDENTIALS',
        401,
    );
});

it('не меняет данные базы при повторном запросе', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create());

    $tables = [
        'delivery_orders',
        'order_status_history',
        'assignments',
        'courier_shifts',
        'outbox_messages',
        'personal_access_tokens',
    ];

    $counts = static fn (): array => array_map(
        static fn (string $table): int => DB::table($table)->count(),
        $tables,
    );

    $before = $counts();

    $this->getJson(reportAccessUrl())->assertOk();
    $this->getJson(reportAccessUrl())->assertOk();

    expect($counts())->toBe($before);
});
