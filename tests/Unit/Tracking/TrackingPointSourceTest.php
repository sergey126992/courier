<?php

declare(strict_types=1);

use App\Domain\Tracking\TrackingPointSource;

it('отдаёт подпись источника точки', function (): void {
    expect(TrackingPointSource::Courier->label())->toBe('Курьер');
});

it('перечисляет значения источников для ограничения таблицы', function (): void {
    expect(TrackingPointSource::values())->toBe(['courier']);
});
