<?php

declare(strict_types=1);

namespace App\Support\Contracts;

/**
 * Каталог общих JSON Schema контрактов (FR-NTF-221, FR-OPS-209).
 *
 * Схемы монтируются в контейнер только для чтения; копий в сервисе нет.
 * Каталог считается готовым, если он читается и содержит хотя бы одну схему:
 * так ошибка монтирования видна при старте, а не при первом событии.
 */
final class SchemaDirectory
{
    /**
     * Путь к каталогу схем из конфигурации (FR-OPS-211).
     */
    public function path(): string
    {
        return (string) config('notifications.contracts.path');
    }

    /**
     * Каталог читается и содержит файлы схем.
     */
    public function isReadable(): bool
    {
        $path = $this->path();

        if ($path === '' || ! is_dir($path) || ! is_readable($path)) {
            return false;
        }

        $schemas = glob($path.DIRECTORY_SEPARATOR.'*.json');

        return is_array($schemas) && $schemas !== [];
    }
}
