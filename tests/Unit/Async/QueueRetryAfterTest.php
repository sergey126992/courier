<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
| Guard согласованности очереди (FR-OPS-107): retry_after используемого соединения
| строго больше --timeout воркера. При равенстве задача, ещё выполняющаяся у воркера,
| возвращается в очередь и запускается повторно — то есть выполняется дважды.
| Источник таймаута — docker-compose.yml, источник retry_after — конфигурация.
*/

it('держит retry_after строго больше таймаута воркера', function (): void {
    $compose = File::get(base_path('docker-compose.yml'));

    preg_match('/queue:work[^\]]*--timeout=(\d+)/', $compose, $timeoutMatch);
    preg_match('/QUEUE_CONNECTION:\s*"?([a-z0-9_]+)"?/', $compose, $connectionMatch);

    $timeout = (int) ($timeoutMatch[1] ?? 0);
    $connection = $connectionMatch[1] ?? '';

    expect($timeout)->toBe(60)
        ->and($connection)->toBe('redis');

    $retryAfter = config('queue.connections.'.$connection.'.retry_after');

    expect($retryAfter)->toBeInt()
        ->and($retryAfter)->toBeGreaterThan($timeout);
});

it('объявляет в окружении контейнеров тот же retry_after', function (): void {
    $compose = File::get(base_path('docker-compose.yml'));

    preg_match('/REDIS_QUEUE_RETRY_AFTER:\s*"?(\d+)"?/', $compose, $match);

    expect((int) ($match[1] ?? 0))->toBe((int) config('queue.connections.redis.retry_after'));
});
