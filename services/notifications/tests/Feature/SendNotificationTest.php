<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\PushMessage;
use App\Models\RecipientContact;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Отправка писем и push (FR-NTF-244, FR-NTF-251…258, ЧТЗ 9.1 п. 4)
|--------------------------------------------------------------------------
|
| Слой отправки вызывается напрямую: поллер появится отдельным пунктом
| плана. Почта и HTTP подменены, внешней сети нет. Тексты проверяются
| на отсутствие персональных данных, push — на запись в журнал симулятора.
|
*/

uses(RefreshDatabase::class);

/**
 * Уведомление канала email для получателя с контактом.
 *
 * @param array<string, mixed> $attributes переопределяющие поля журнала
 */
function emailNotification(int $recipientId = 7, array $attributes = []): Notification
{
    RecipientContact::factory()->create([
        'user_id' => $recipientId,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    return Notification::factory()->create(array_merge([
        'recipient_id' => $recipientId,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ], $attributes));
}

it('отправляет письмо по контакту из базы сервиса и отмечает уведомление отправленным', function (): void {
    Mail::fake();

    $notification = emailNotification();
    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Sent)
        ->and($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(1)
        ->and($notification->sent_at)->not->toBeNull()
        ->and($notification->last_error_code)->toBeNull();

    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo('sender@example.test'));

    $sent = Mail::sent(NotificationMail::class)->first();

    if (! $sent instanceof NotificationMail) {
        throw new RuntimeException('Письмо не отправлено.');
    }

    expect($sent->lines['subject'])->toContain('42')
        ->and($sent->lines['body'])->toContain('42')
        ->and($sent->lines['body'])->toContain('delivered')
        ->and($sent->lines['tracking'])->toContain('TRK-42');
});

it('не выводит персональные данные получателя в письмо', function (): void {
    Mail::fake();

    $notification = emailNotification();
    app(NotificationSender::class)->send($notification);

    Mail::assertSent(NotificationMail::class);

    $sent = Mail::sent(NotificationMail::class)->first();

    if (! $sent instanceof NotificationMail) {
        throw new RuntimeException('Письмо не отправлено.');
    }

    $html = (string) $sent->render();

    expect($html)->toContain('TRK-42')
        ->and($html)->not->toContain('sender@example.test')
        ->and(implode(' ', $sent->lines))->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/');
});

it('переводит письмо в failed без контакта получателя', function (): void {
    Mail::fake();

    $notification = Notification::factory()->create([
        'recipient_id' => 999,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]);

    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Failed)
        ->and($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::RECIPIENT_UNKNOWN)
        ->and($notification->attempts)->toBe(1)
        ->and($notification->sent_at)->toBeNull();

    Mail::assertNothingSent();
});

it('пишет push в журнал симулятора без сетевого вызова', function (): void {
    Http::fake();

    $notification = Notification::factory()->push()->create([
        'recipient_id' => 55,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]);

    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Sent)
        ->and($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(1);

    $this->assertDatabaseHas('push_messages', [
        'notification_id' => $notification->id,
        'recipient_id' => 55,
        'template' => 'order_delivered_push',
    ]);

    expect((string) PushMessage::query()->value('body'))->toContain('TRK-42');

    Http::assertNothingSent();
});

it('считает сбой записи журнала push инфраструктурной ошибкой и повторяет попытку', function (): void {
    Http::fake();

    $notification = Notification::factory()->push()->create([
        'recipient_id' => 55,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]);

    Schema::rename('push_messages', 'push_messages_unavailable');

    $failed = app(NotificationSender::class)->send($notification);

    expect($failed)->toBe(NotificationStatus::Error)
        ->and($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::SEND_FAILED)
        ->and($notification->attempts)->toBe(1);

    Schema::rename('push_messages_unavailable', 'push_messages');

    $retried = app(NotificationSender::class)->send($notification);

    expect($retried)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(2)
        ->and($notification->last_error_code)->toBeNull();
});

it('не отменяет push при сбое email того же события', function (): void {
    Mail::fake();

    $email = Notification::factory()->create([
        'event_id' => '7f2f0f9e-0000-4000-8000-000000000031',
        'recipient_id' => 999,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]);

    $push = Notification::factory()->push()->create([
        'event_id' => '7f2f0f9e-0000-4000-8000-000000000031',
        'recipient_id' => 999,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]);

    $sender = app(NotificationSender::class);

    expect($sender->send($email))->toBe(NotificationStatus::Failed)
        ->and($sender->send($push))->toBe(NotificationStatus::Sent)
        ->and(PushMessage::query()->count())->toBe(1);
});

it('не отправляет повторно уведомление в терминальном состоянии', function (): void {
    Mail::fake();

    $notification = Notification::factory()->sent()->create(['recipient_id' => 7]);

    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(1);

    Mail::assertNothingSent();
});

it('считает неизвестный шаблон журнала бизнес-ошибкой', function (): void {
    Mail::fake();

    $notification = emailNotification(7, ['template' => 'unknown_template']);

    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TEMPLATE_NOT_FOUND);

    Mail::assertNothingSent();
});
