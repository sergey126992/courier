<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Расчёт требует зону по адресу забора, иначе сервер отклонит его до лимита.
beforeEach(function (): void {
    estimateServiceZone();
});

it('ограничивает частоту расчёта и отдаёт заголовок повтора', function (): void {
    config(['rate_limits.estimate.attempts' => 2]);

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/estimates', estimatePayload())->assertOk();
    $this->postJson('/api/v1/estimates', estimatePayload())->assertOk();

    $response = $this->postJson('/api/v1/estimates', estimatePayload());

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'RATE_LIMITED');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('считает лимит по пользователю, а не по адресу', function (): void {
    config(['rate_limits.estimate.attempts' => 1]);

    Sanctum::actingAs(User::factory()->sender()->create());
    $this->postJson('/api/v1/estimates', estimatePayload())->assertOk();
    $this->postJson('/api/v1/estimates', estimatePayload())->assertStatus(429);

    // Другой пользователь с тем же адресом запроса не блокируется.
    Sanctum::actingAs(User::factory()->sender()->create());
    $this->postJson('/api/v1/estimates', estimatePayload())->assertOk();
});
