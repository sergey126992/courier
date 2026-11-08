<?php

declare(strict_types=1);

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

it('повторно отправляет письмо авторизованному пользователю', function (): void {
    Mail::fake();

    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/email/resend')
        ->assertStatus(202)
        ->assertJsonPath('message', __('auth.verification_sent'));

    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo((string) $user->email));
});

it('не отправляет письмо подтверждённому пользователю', function (): void {
    Mail::fake();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/auth/email/resend')->assertStatus(202);

    Mail::assertNothingQueued();
});

it('требует авторизации для повторной отправки', function (): void {
    $response = $this->postJson('/api/v1/auth/email/resend');

    $response->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('закрывает личные разделы неподтверждённому пользователю', function (): void {
    Route::middleware(['auth:sanctum', 'verified.api'])
        ->get('/api/v1/test/personal', fn () => response()->json(['ok' => true]));

    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/test/personal')
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    $user->markEmailAsVerified();

    $this->getJson('/api/v1/test/personal')->assertOk();
});
