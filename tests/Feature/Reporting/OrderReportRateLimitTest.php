<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Адрес отчёта за фиксированный период.
 */
function reportRateLimitUrl(): string
{
    return '/api/v1/reports/orders?'.http_build_query([
        'from' => '2026-11-01T00:00:00+03:00',
        'to' => '2026-12-01T00:00:00+03:00',
    ]);
}

it('ограничивает частоту отчёта и отдаёт заголовок повтора', function (): void {
    config(['rate_limits.reports_read.attempts' => 2]);

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson(reportRateLimitUrl())->assertOk();
    $this->getJson(reportRateLimitUrl())->assertOk();

    $response = $this->getJson(reportRateLimitUrl());

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'RATE_LIMITED');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('считает лимит по пользователю, а не по адресу', function (): void {
    config(['rate_limits.reports_read.attempts' => 1]);

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->getJson(reportRateLimitUrl())->assertOk();
    $this->getJson(reportRateLimitUrl())->assertStatus(429);

    // Второй администратор с того же адреса запроса не блокируется.
    Sanctum::actingAs(User::factory()->admin()->create());
    $this->getJson(reportRateLimitUrl())->assertOk();
});

it('срабатывает раньше проверки прав', function (): void {
    config(['rate_limits.reports_read.attempts' => 1]);

    Sanctum::actingAs(User::factory()->sender()->create());

    // Первый запрос проходит лимит и получает отказ политики.
    $this->getJson(reportRateLimitUrl())->assertStatus(403);

    // Второй запрос того же пользователя упирается в лимит раньше политики (ЧТЗ 5).
    $this->getJson(reportRateLimitUrl())
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
});
