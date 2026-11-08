<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Команда api:docs: сборка артефакта и проверка расхождения с кодом.
 *
 * Проверка идёт по временной копии: тесты не перезаписывают
 * зафиксированный файл docs/api/openapi.json.
 */
it('собирает описание API и печатает счётчики', function (): void {
    $target = storage_path('framework/testing/api-docs-'.bin2hex(random_bytes(4)).'.json');

    try {
        $exitCode = Artisan::call('api:docs', ['--path' => $target]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and(File::exists($target))->toBeTrue()
            ->and($output)->toContain('Описание API сохранено');

        /** @var array<string, mixed> $document */
        $document = json_decode((string) File::get($target), true, 512, JSON_THROW_ON_ERROR);

        expect($document['paths'])->not->toBeEmpty();
    } finally {
        File::delete($target);
    }
});

it('подтверждает актуальность зафиксированного описания', function (): void {
    $exitCode = Artisan::call('api:docs', ['--check' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Описание API актуально');
});

it('возвращает ошибку, когда описание расходится с кодом', function (): void {
    $copy = storage_path('framework/testing/api-docs-stale-'.bin2hex(random_bytes(4)).'.json');

    try {
        File::ensureDirectoryExists(dirname($copy));
        File::put($copy, (string) File::get(base_path((string) config('scramble.export_path'))));

        // Имитируем устаревший артефакт: убираем один маршрут из копии.
        /** @var array<string, mixed> $document */
        $document = json_decode((string) File::get($copy), true, 512, JSON_THROW_ON_ERROR);
        $document['paths'] = array_slice($document['paths'], 1, null, true);

        File::put($copy, (string) json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $exitCode = Artisan::call('api:docs', ['--check' => true, '--path' => $copy]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('Описание API устарело');
    } finally {
        File::delete($copy);
    }
});

it('сообщает об отсутствии зафиксированного файла', function (): void {
    $missing = storage_path('framework/testing/api-docs-missing-'.bin2hex(random_bytes(4)).'.json');

    $exitCode = Artisan::call('api:docs', ['--check' => true, '--path' => $missing]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('не найден');
});
