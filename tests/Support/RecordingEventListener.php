<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Events\EventEnvelope;
use App\Services\Events\Contracts\EventListener;
use RuntimeException;

/**
 * Тестовый слушатель: запоминает принятые события и умеет отказывать.
 */
final class RecordingEventListener implements EventListener
{
    /**
     * Идентификаторы принятых событий: повтор не создаёт второй эффект.
     *
     * @var list<string>
     */
    public array $handled = [];

    /**
     * Число вызовов, включая неуспешные.
     */
    public int $calls = 0;

    public function __construct(
        public bool $failing = false,
        private string $name = 'tests.recording',
    ) {}

    public function handle(EventEnvelope $event): void
    {
        $this->calls++;

        if ($this->failing) {
            throw new RuntimeException('Слушатель отказал в тесте.');
        }

        $this->handled[] = $event->messageId;
    }

    /**
     * Стабильное имя обработчика: в тестах задаётся явно.
     */
    public function handler(): string
    {
        return $this->name;
    }
}
