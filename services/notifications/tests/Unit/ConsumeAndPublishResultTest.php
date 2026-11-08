<?php

declare(strict_types=1);

use App\Services\Events\ConsumeResult;
use App\Services\Notifications\OutboxPublishResult;

/*
|--------------------------------------------------------------------------
| Скалярные итоги проходов потребления и публикации (FR-NTF-204, FR-NTF-283)
|--------------------------------------------------------------------------
|
| Команды и проверка состояния опираются на признаки итога, а не на поля.
| Тест закрывает пустой проход, деградацию, занятую блокировку и отказы.
|
*/

it('описывает пустой и деградировавший проход потребления', function (): void {
    $empty = ConsumeResult::empty();
    $degraded = ConsumeResult::degraded();

    expect($empty->isEmpty())->toBeTrue()
        ->and($empty->hasFailures())->toBeFalse()
        ->and($degraded->isEmpty())->toBeFalse()
        ->and($degraded->hasFailures())->toBeTrue();
});

it('сообщает о сбоях обработки и занятой блокировке публикатора', function (): void {
    $failed = new ConsumeResult(failed: 2);
    $locked = OutboxPublishResult::locked();

    expect($failed->hasFailures())->toBeTrue()
        ->and($locked->lockBusy)->toBeTrue()
        ->and($locked->hasFailures())->toBeFalse()
        ->and((new OutboxPublishResult(failed: 1))->hasFailures())->toBeTrue();
});
