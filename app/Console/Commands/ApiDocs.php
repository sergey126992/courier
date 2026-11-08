<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Сборка и проверка описания API.
 *
 * Описание строится из кода пакетом Scramble: ручные аннотации не нужны.
 * Режим --check используется в CI: расхождение сгенерированного документа
 * с зафиксированным файлом делает сборку красной (FR-API-8).
 */
final class ApiDocs extends Command
{
    protected $signature = 'api:docs
        {--check : Сравнить сгенерированное описание с зафиксированным файлом}
        {--path= : Путь к файлу описания (по умолчанию config/scramble.php) }';

    protected $description = 'Собирает описание API из кода и проверяет расхождение с зафиксированным файлом.';

    public function handle(): int
    {
        $target = $this->targetPath();

        return $this->option('check')
            ? $this->check($target)
            : $this->generate($target);
    }

    /**
     * Перегенерация зафиксированного файла.
     */
    private function generate(string $target): int
    {
        File::ensureDirectoryExists(dirname($target));

        if (! $this->export($target)) {
            return self::FAILURE;
        }

        $summary = $this->summary($target);

        if ($summary === null) {
            $this->error('Описание API сгенерировано, но файл не читается как JSON.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Описание API сохранено: %s (путей: %d, схем: %d).',
            $this->relative($target),
            $summary['paths'],
            $summary['schemas'],
        ));

        return self::SUCCESS;
    }

    /**
     * Проверка расхождения с зафиксированным файлом.
     *
     * Сгенерированный документ пишется во временный файл и сравнивается построчно,
     * чтобы сообщение указывало на первое отличие и не зависело от окружения.
     */
    private function check(string $target): int
    {
        if (! File::exists($target)) {
            $this->error(sprintf(
                'Файл %s не найден. Выполните php artisan api:docs.',
                $this->relative($target),
            ));

            return self::FAILURE;
        }

        $temporary = $this->temporaryPath();

        try {
            if (! $this->export($temporary)) {
                return self::FAILURE;
            }

            if (! $this->sameContent($target, $temporary)) {
                $this->error(sprintf(
                    'Описание API устарело: %s не совпадает с кодом. Выполните php artisan api:docs и зафиксируйте файл.',
                    $this->relative($target),
                ));

                return self::FAILURE;
            }
        } finally {
            File::delete($temporary);
        }

        $this->info(sprintf('Описание API актуально: %s.', $this->relative($target)));

        return self::SUCCESS;
    }

    /**
     * Выгрузка описания пакетом Scramble.
     */
    private function export(string $path): bool
    {
        $exitCode = $this->call('scramble:export', ['--path' => $path]);

        if ($exitCode !== self::SUCCESS) {
            $this->error('Не удалось построить описание API: генератор завершился с ошибкой.');

            return false;
        }

        return File::exists($path);
    }

    /**
     * Число путей и схем в описании; null, если файл не является JSON-документом.
     *
     * @return array{paths: int, schemas: int}|null
     */
    private function summary(string $path): ?array
    {
        try {
            $document = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($document)) {
            return null;
        }

        $paths = is_array($document['paths'] ?? null) ? count($document['paths']) : 0;
        $schemas = is_array($document['components']['schemas'] ?? null)
            ? count($document['components']['schemas'])
            : 0;

        return ['paths' => $paths, 'schemas' => $schemas];
    }

    /**
     * Построчное сравнение: первое отличие попадает в сообщение.
     */
    private function sameContent(string $expected, string $actual): bool
    {
        $left = explode("\n", (string) File::get($expected));
        $right = explode("\n", (string) File::get($actual));

        if (count($left) !== count($right)) {
            $this->warn(sprintf('Разное число строк: %d в файле, %d в коде.', count($left), count($right)));
        }

        return $left === $right;
    }

    /**
     * Путь к зафиксированному файлу описания.
     */
    private function targetPath(): string
    {
        $option = $this->option('path');

        if (is_string($option) && $option !== '') {
            return $this->absolute($option);
        }

        return $this->absolute((string) config('scramble.export_path', 'docs/api/openapi.json'));
    }

    private function temporaryPath(): string
    {
        return $this->absolute('storage/framework/api-docs-'.bin2hex(random_bytes(6)).'.json');
    }

    /**
     * Абсолютный путь: относительный считается от корня приложения.
     */
    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
