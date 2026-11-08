<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Адрес отчёта с переданными параметрами.
 *
 * @param array<string, string> $query
 */
function reportValidationUrl(array $query = []): string
{
    return '/api/v1/reports/orders'.($query === [] ? '' : '?'.http_build_query($query));
}

/**
 * Проверяет единый формат ошибки problem+json.
 *
 * @param TestResponse<Response> $response
 */
function reportValidationProblem(TestResponse $response, string $code, int $status): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'code', 'details'])
        ->assertJsonPath('code', $code);
}

/**
 * Поля, на которые указала валидация.
 *
 * @param TestResponse<Response> $response
 * @return list<string>
 */
function reportValidationFields(TestResponse $response): array
{
    return array_values(array_map(
        static fn (array $detail): string => (string) $detail['field'],
        (array) $response->json('details'),
    ));
}

beforeEach(function (): void {
    // Таймзона приложения задаётся явно: тест не зависит от .env стенда.
    config(['app.timezone' => 'Europe/Moscow']);

    Sanctum::actingAs(User::factory()->admin()->create());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('отвергает окончание периода не позже начала', function (): void {
    $response = $this->getJson(reportValidationUrl([
        'from' => '2026-11-05T10:00:00+03:00',
        'to' => '2026-11-05T09:00:00+03:00',
    ]));

    reportValidationProblem($response, 'VALIDATION_FAILED', 422);

    expect(reportValidationFields($response))->toContain('to');
});

it('отвергает равные границы периода', function (): void {
    $response = $this->getJson(reportValidationUrl([
        'from' => '2026-11-05T10:00:00+03:00',
        'to' => '2026-11-05T10:00:00+03:00',
    ]));

    reportValidationProblem($response, 'VALIDATION_FAILED', 422);
});

it('отвергает битую дату', function (): void {
    $response = $this->getJson(reportValidationUrl([
        'from' => 'вчера',
        'to' => '2026-11-05T10:00:00+03:00',
    ]));

    reportValidationProblem($response, 'VALIDATION_FAILED', 422);

    expect(reportValidationFields($response))->toContain('from');
});

it('отвергает дату без времени', function (): void {
    $response = $this->getJson(reportValidationUrl([
        'from' => '2026-11-01',
        'to' => '2026-11-05T10:00:00+03:00',
    ]));

    reportValidationProblem($response, 'VALIDATION_FAILED', 422);

    expect(reportValidationFields($response))->toContain('from');
});

it('сообщает отдельным кодом о периоде длиннее предела', function (): void {
    config(['reporting.max_period_days' => 30]);

    $response = $this->getJson(reportValidationUrl([
        'from' => '2026-01-01T00:00:00+03:00',
        'to' => '2026-03-01T00:00:00+03:00',
    ]));

    reportValidationProblem($response, 'REPORT_PERIOD_TOO_LONG', 422);

    expect((string) $response->json('detail'))->toContain('30')
        ->and(reportValidationFields($response))->toContain('to')
        ->and((string) $response->json('details.0.message'))->toContain('30');
});

it('трактует значение без смещения в таймзоне приложения', function (): void {
    $response = $this->getJson(reportValidationUrl([
        'from' => '2026-11-01T00:00:00',
        'to' => '2026-11-02T00:00:00',
    ]))->assertOk();

    expect($response->json('period.from'))->toBe('2026-11-01T00:00:00+03:00')
        ->and($response->json('period.to'))->toBe('2026-11-02T00:00:00+03:00')
        ->and($response->json('period.days'))->toBe(1);
});

it('по умолчанию считает период с начала месяца по текущий момент', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-05T14:30:00+03:00'));

    $response = $this->getJson(reportValidationUrl())->assertOk();

    expect($response->json('period.from'))->toBe('2026-11-01T00:00:00+03:00')
        ->and($response->json('period.to'))->toBe('2026-11-05T14:30:00+03:00')
        ->and($response->json('period.days'))->toBe(4);
});
