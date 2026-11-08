<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Services\Events\Exceptions\EventContractInvalid;
use App\Services\Events\Exceptions\EventContractUnknown;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Проверка конверта события (вместе с payload) по общим JSON Schema (FR-NTF-221…225).
 *
 * Схемы лежат в единственном каталоге (`events.schema_path`, по умолчанию
 * `contracts/notifications`) и читаются по имени контракта: точки типа события
 * заменяются дефисами, к имени добавляется версия (`order.picked_up` →
 * `order-picked-up.v1.json`). Каталог сканируется один раз за процесс.
 *
 * Типы, у которых контракта нет (отменённый заказ, смены курьера), не
 * проверяются: их политика и формат в M11 не меняются (ЧТЗ 8.3, п. 4).
 * Если контракт есть, а нужной версии нет — это ошибка контракта, а не
 * молчаливый пропуск: событие остаётся в Outbox с кодом причины.
 *
 * Персональные данные в сообщения ошибок не попадают (FR-NTF-235): наружу
 * передаются только тип события, версия, ключевое слово и путь поля.
 */
final class JsonSchemaValidator
{
    /**
     * Имя файла схемы: «слаг контракта» и версия (`order-created.v1.json`).
     */
    private const FILE_PATTERN = '/^(?<slug>[a-z0-9]+(?:-[a-z0-9]+)*)\.(?<version>v[0-9]+)\.json$/';

    /**
     * Каталог схем: слаг контракта → версия → путь к файлу.
     *
     * @var array<string, array<string, string>>|null
     */
    private ?array $catalog = null;

    private readonly Validator $validator;

    /**
     * @throws InvalidArgumentException каталог схем не найден
     */
    public function __construct(private readonly string $schemaDirectory)
    {
        if ($schemaDirectory === '' || ! is_dir($schemaDirectory)) {
            throw new InvalidArgumentException(sprintf(
                'Каталог JSON Schema контрактов не найден: "%s".',
                $schemaDirectory,
            ));
        }

        $this->validator = new Validator;
    }

    /**
     * Каталог схем, из которого читает валидатор (единственный экземпляр, FR-NTF-221).
     */
    public function directory(): string
    {
        return $this->schemaDirectory;
    }

    /**
     * Есть ли у типа события контракт оповещений хотя бы одной версии.
     */
    public function hasContract(string $eventType): bool
    {
        return array_key_exists(self::contractSlug($eventType), $this->catalog());
    }

    /**
     * Каталог контрактов: слаг → версия → путь к файлу схемы.
     *
     * @return array<string, array<string, string>>
     */
    public function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $catalog = [];

        foreach (scandir($this->schemaDirectory) ?: [] as $entry) {
            if (preg_match(self::FILE_PATTERN, $entry, $matches) !== 1) {
                continue;
            }

            $catalog[$matches['slug']][$matches['version']] = $this->schemaDirectory.DIRECTORY_SEPARATOR.$entry;
        }

        ksort($catalog);

        return $this->catalog = $catalog;
    }

    /**
     * Слаг контракта из типа события: точки и подчёркивания заменяются дефисами.
     *
     * `order.picked_up` → `order-picked-up`, `user.contact_changed` →
     * `user-contact-changed`: имя файла совпадает с именем контракта из ЧТЗ 8.4.
     */
    public static function contractSlug(string $eventType): string
    {
        return str_replace(['.', '_'], '-', $eventType);
    }

    /**
     * Путь к файлу схемы нужной версии.
     *
     * @throws EventContractUnknown файла схемы нет
     */
    public function schemaFile(string $eventType, string $schemaVersion): string
    {
        $file = $this->catalog()[self::contractSlug($eventType)][$schemaVersion] ?? null;

        if ($file === null) {
            throw EventContractUnknown::make($eventType, $schemaVersion);
        }

        return $file;
    }

    /**
     * Проверяет конверт события (вместе с payload) по схеме до передачи
     * в транспорт (FR-NTF-225).
     *
     * @param array<string, mixed> $envelope форма конверта из EventEnvelope::toArray()
     *
     * @throws EventContractUnknown контракт есть, нужной версии схемы нет
     * @throws EventContractInvalid конверт или payload не соответствует схеме
     */
    public function validateEvent(string $eventType, string $schemaVersion, array $envelope): void
    {
        if (! $this->hasContract($eventType)) {
            return;
        }

        $result = $this->validator->validate(
            $this->objectGraph($envelope),
            $this->loadSchema($this->schemaFile($eventType, $schemaVersion)),
        );

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        // Opis возвращает внешнюю ошибку контейнера (`properties`), поэтому
        // для понятной причины берётся самое глубокое вложенное нарушение.
        $leaf = $error instanceof ValidationError ? self::leaf($error) : null;

        throw EventContractInvalid::make(
            eventType: $eventType,
            schemaVersion: $schemaVersion,
            keyword: $leaf?->keyword() ?? 'invalid',
            pointer: $leaf instanceof ValidationError ? self::pointer($leaf) : '/',
        );
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

    /**
     * Самое глубокое вложенное нарушение схемы.
     */
    private static function leaf(ValidationError $error): ValidationError
    {
        $subErrors = $error->subErrors();

        return $subErrors === [] ? $error : self::leaf($subErrors[0]);
    }

    /**
     * Путь проблемного поля в конверте без его значения.
     */
    private static function pointer(ValidationError $error): string
    {
        $path = $error->data()->fullPath();

        return $path === [] ? '/' : '/'.implode('/', $path);
    }
}
