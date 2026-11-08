<?php

declare(strict_types=1);

use App\Logging\TechnicalLogContext;
use App\Logging\TechnicalLogFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/*
|--------------------------------------------------------------------------
| Структурированный журнал сервиса (FR-OPS-202, FR-OPS-208)
|--------------------------------------------------------------------------
|
| Форматтер пишет одну JSON-запись на строку и оставляет в контексте только
| технические поля. Контекст области восстанавливается после обработки
| записи, поэтому correlation_id одной записи не протекает в следующую.
|
*/

/**
 * Запись Monolog с контекстом.
 *
 * @param array<string, mixed> $context
 */
function logRecord(array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-11-06T10:00:00+00:00'),
        channel: 'stderr',
        level: Level::Warning,
        message: 'Событие не обработано; сообщение оставлено в pending.',
        context: $context,
    );
}

it('пишет одну JSON-строку с уровнем, сообщением и контекстом', function (): void {
    $line = (new TechnicalLogFormatter)->format(logRecord([
        'correlation_id' => 'corr-00000001',
        'event_id' => '8b8f1c8e-0000-4000-8000-000000000001',
        'notification_id' => 501,
        'order_id' => 42,
        'recipient_id' => 7,
        'channel' => 'email',
        'status' => 'sent',
        'attempt' => 2,
        'error_code' => 'NOTIFICATION_SEND_FAILED',
        'count' => 3,
    ]));

    expect($line)->toEndWith("\n");

    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($decoded))->toBe(['timestamp', 'level', 'message', 'context'])
        ->and($decoded['level'])->toBe('warning')
        ->and($decoded['timestamp'])->toBe('2026-11-06T10:00:00+00:00')
        ->and($decoded['message'])->toBeString()->not->toBeEmpty()
        ->and($decoded['context']['correlation_id'])->toBe('corr-00000001')
        ->and($decoded['context']['attempt'])->toBe(2)
        ->and($decoded['context']['count'])->toBe(3);
});

it('отбрасывает адрес, телефон, токен, тело письма и текст исключения', function (): void {
    $line = (new TechnicalLogFormatter)->format(logRecord([
        'correlation_id' => 'corr-00000001',
        'email' => 'secret.person@example.test',
        'phone' => '+7 999 123-45-67',
        'token' => 'dev-notifications-token',
        'body' => 'Текст письма получателю',
        'exception' => 'RuntimeException: подключение к SMTP отклонено',
        'nested' => ['email' => 'secret.person@example.test'],
    ]));

    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['context'])->toBe(['correlation_id' => 'corr-00000001'])
        ->and($line)->not->toContain('secret.person@example.test')
        ->and($line)->not->toContain('+7 999')
        ->and($line)->not->toContain('dev-notifications-token')
        ->and($line)->not->toContain('Текст письма');
});

it('вычищает адрес и телефон из текста сообщения', function (): void {
    $record = new LogRecord(
        datetime: new DateTimeImmutable('2026-11-06T10:00:00+00:00'),
        channel: 'stderr',
        level: Level::Error,
        message: 'SMTP отклонил отправку: secret.person@example.test, +7 999 123-45-67',
    );

    $line = (new TechnicalLogFormatter)->format($record);

    expect($line)->not->toContain('secret.person@example.test')
        ->not->toContain('+7 999')
        ->toContain('[redacted-email]')
        ->toContain('[redacted-phone]');
});

it('проверяет, что в stderr настроен JSON-форматтер сервиса', function (): void {
    expect(config('logging.channels.stderr.formatter'))->toBe(TechnicalLogFormatter::class)
        ->and(config('logging.channels.stderr.handler_with')['stream'])->toBe('php://stderr');
});

it('восстанавливает контекст области после обработки записи', function (): void {
    $context = new TechnicalLogContext;

    $inside = null;
    $context->run(['correlation_id' => 'corr-1', 'notification_id' => 501], function () use ($context, &$inside): void {
        $inside = $context->fields();
    });

    expect($inside)->toBe(['correlation_id' => 'corr-1', 'notification_id' => 501])
        ->and($context->fields())->toBe([]);
});

it('не пропускает в контекст области посторонние поля', function (): void {
    $context = new TechnicalLogContext;
    $context->replace(['correlation_id' => 'corr-1', 'email' => 'secret.person@example.test']);

    expect($context->fields())->toBe(['correlation_id' => 'corr-1']);
});
