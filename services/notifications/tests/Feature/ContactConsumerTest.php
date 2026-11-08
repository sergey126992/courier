<?php

declare(strict_types=1);

use App\Models\ChannelBinding;
use App\Models\InboxMessage;
use App\Models\RecipientContact;
use App\Services\Events\StreamMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Контакты получателей из потока contacts (FR-NTF-231…235, ЧТЗ 9.3 п. 6)
|--------------------------------------------------------------------------
|
| Транспорт подменён подставным потоком. Событие применяется один раз
| на сообщение: повтор не создаёт вторую строку, смена почты обновляет
| контакт и активную привязку канала. Персональные данные в утверждениях
| проверяются только по базе.
|
*/

uses(RefreshDatabase::class);

/**
 * Сообщение потока контактов для запуска команды.
 */
function contactMessage(int $userId = 7, string $email = 'sender@example.test', ?string $messageId = null, string $streamId = '0-1', ?string $eventId = null): StreamMessage
{
    return contactStreamMessage($userId, $email, 'ru', $messageId, $streamId, $eventId);
}

function contactsStreamName(): string
{
    return (string) config('notifications.transport.streams.contacts');
}

it('сохраняет контакт из события регистрации и создаёт привязку email', function (): void {
    $stream = fakeEventStream([contactMessage()]);

    $this->artisan('notifications:consume', ['--once' => true, '--stream' => contactsStreamName()])->assertExitCode(0);

    $contact = RecipientContact::query()->find(7);

    expect($contact?->email)->toBe('sender@example.test')
        ->and($contact?->locale)->toBe('ru')
        ->and($contact?->source_event_id)->toBeString()
        ->and($stream->acknowledged)->toBe(['0-1'])
        ->and(InboxMessage::query()->count())->toBe(1)
        ->and(ChannelBinding::query()->count())->toBe(1)
        ->and(ChannelBinding::query()->value('channel'))->toBe('email')
        ->and(ChannelBinding::query()->value('address'))->toBe('sender@example.test');
});

it('не создаёт вторую строку при повторной доставке сообщения', function (): void {
    $message = contactMessage();
    $stream = fakeEventStream([$message, $message]);

    $this->artisan('notifications:consume', ['--once' => true, '--stream' => contactsStreamName()])->assertExitCode(0);

    expect(RecipientContact::query()->count())->toBe(1)
        ->and(ChannelBinding::query()->count())->toBe(1)
        ->and($stream->acknowledged)->toBe(['0-1', '0-1']);
});

it('применяет повторно опубликованное событие без второй строки', function (): void {
    $eventId = (string) Str::uuid();

    fakeEventStream([contactMessage(messageId: (string) Str::uuid(), streamId: '0-1', eventId: $eventId)]);
    $this->artisan('notifications:consume', ['--once' => true, '--stream' => contactsStreamName()])->assertExitCode(0);

    fakeEventStream([contactMessage(messageId: (string) Str::uuid(), streamId: '0-2', eventId: $eventId)]);
    $this->artisan('notifications:consume', ['--once' => true, '--stream' => contactsStreamName()])->assertExitCode(0);

    expect(RecipientContact::query()->count())->toBe(1)
        ->and(RecipientContact::query()->value('source_event_id'))->toBe($eventId)
        ->and(ChannelBinding::query()->count())->toBe(1);
});

it('обновляет email из события смены почты и активную привязку', function (): void {
    fakeEventStream([contactMessage()]);
    $this->artisan('notifications:consume', ['--once' => true, '--stream' => contactsStreamName()])->assertExitCode(0);

    $newEventId = (string) Str::uuid();
    fakeEventStream([contactMessage(email: 'changed@example.test', messageId: (string) Str::uuid(), streamId: '0-2', eventId: $newEventId)]);
    $this->artisan('notifications:consume', ['--once' => true, '--stream' => contactsStreamName()])->assertExitCode(0);

    expect(RecipientContact::query()->count())->toBe(1)
        ->and(RecipientContact::query()->value('email'))->toBe('changed@example.test')
        ->and(RecipientContact::query()->value('source_event_id'))->toBe($newEventId)
        ->and(ChannelBinding::query()->count())->toBe(1)
        ->and(ChannelBinding::query()->whereNull('revoked_at')->value('address'))->toBe('changed@example.test');
});

it('помечает событие контакта без адреса недействительным и не пишет контакт', function (): void {
    $messageId = (string) Str::uuid();
    $fields = contactEventFields(messageId: $messageId);
    $payload = json_decode($fields['payload'], true);
    unset($payload['email']);
    $fields['payload'] = (string) json_encode($payload, JSON_THROW_ON_ERROR);

    $stream = fakeEventStream([new StreamMessage('0-1', $fields)]);

    $this->artisan('notifications:consume', ['--once' => true, '--stream' => contactsStreamName()])->assertExitCode(0);

    expect($stream->acknowledged)->toBe(['0-1'])
        ->and(InboxMessage::query()->value('status'))->toBe('invalid')
        ->and(InboxMessage::query()->value('error_code'))->toBe('EVENT_CONTRACT_INVALID')
        ->and(RecipientContact::query()->count())->toBe(0);
});
