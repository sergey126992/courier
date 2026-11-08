<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Notifications\NotificationErrorCode;
use App\Support\Contracts\SchemaDirectory;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\Validator;
use Throwable;

/**
 * Проверка конверта события по общей JSON Schema (FR-NTF-221…225).
 *
 * Схемы лежат в единственном каталоге и читаются по имени контракта: точки
 * и подчёркивания типа события заменяются дефисами, к имени добавляется
 * версия (`order.picked_up` → `order-picked-up.v1.json`). Каталог сканируется
 * один раз за процесс.
 *
 * Типы, у которых контракта M11 нет (отмена заказа, смены курьера), не
 * проверяются: их политика и формат не расширяются (ЧТЗ 8.3, п. 4). Если
 * контракт есть, а нужной версии нет — это неизвестный контракт.
 */
final class EventContractValidator
{
    /**
     * Имя файла схемы: слаг контракта и версия.
     */
    private const FILE_PATTERN = '/^(?<slug>[a-z0-9]+(?:-[a-z0-9]+)*)\.(?<version>v[0-9]+)\.json$/';

    /**
     * Каталог схем: слаг контракта → версия → путь к файлу.
     *
     * @var array<string, array<string, string>>|null
     */
    private ?array $catalog = null;

    private readonly Validator $validator;

    public function __construct(private readonly SchemaDirectory $schemas)
    {
        $this->validator = new Validator;
    }

    /**
     * Проверяет конверт события до эффекта.
     *
     * @param array<string, mixed> $document конверт вместе с payload
     * @return string|null код причины отказа; null — конверт принят
     */
    public function validate(string $eventType, string $schemaVersion, array $document): ?string
    {
        $file = $this->schemaFile($eventType, $schemaVersion);

        if ($file === null) {
            return $this->hasContract($eventType) ? NotificationErrorCode::CONTRACT_UNKNOWN : null;
        }

        try {
            $result = $this->validator->validate($this->objectGraph($document), $this->loadSchema($file));
        } catch (Throwable) {
            // Нечитаемая схема — ошибка контракта, а не повод ронять поток.
            return NotificationErrorCode::CONTRACT_INVALID;
        }

        return $result->isValid() ? null : NotificationErrorCode::CONTRACT_INVALID;
    }

    /**
     * Есть ли у типа события контракт хотя бы одной версии.
     */
    public function hasContract(string $eventType): bool
    {
        return array_key_exists(self::contractSlug($eventType), $this->catalog());
    }

    /**
     * Слаг контракта из типа события: `order.picked_up` → `order-picked-up`.
     */
    public static function contractSlug(string $eventType): string
    {
        return str_replace(['.', '_'], '-', $eventType);
    }

    /**
     * Путь к файлу схемы нужной версии; null — файла нет.
     */
    private function schemaFile(string $eventType, string $schemaVersion): ?string
    {
        return $this->catalog()[self::contractSlug($eventType)][$schemaVersion] ?? null;
    }

    /**
     * Каталог контрактов: слаг → версия → путь к файлу схемы.
     *
     * @return array<string, array<string, string>>
     */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $directory = $this->schemas->path();
        $catalog = [];

        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $entry) {
            if (preg_match(self::FILE_PATTERN, $entry, $matches) !== 1) {
                continue;
            }

            $catalog[$matches['slug']][$matches['version']] = $directory.DIRECTORY_SEPARATOR.$entry;
        }

        ksort($catalog);

        return $this->catalog = $catalog;
    }

    /**
     * @throws InvalidArgumentException файл схемы не читается или не является объектом
     */
    private function loadSchema(string $file): object
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Файл схемы не прочитан: "%s".', $file));
        }

        try {
            $schema = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('Схема "%s" не разобрана как JSON.', $file), 0, $exception);
        }

        if (! is_object($schema)) {
            throw new InvalidArgumentException(sprintf('Схема "%s" не является JSON-объектом.', $file));
        }

        return $schema;
    }

    /**
     * Конверт проверяется как объект JSON: пустой набор полей остаётся объектом.
     *
     * @param array<string, mixed> $data
     */
    private function objectGraph(array $data): mixed
    {
        try {
            return json_decode(
                json_encode($data, JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Событие не сериализуется в JSON.', 0, $exception);
        }
    }
}
