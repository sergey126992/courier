<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Exceptions\ParcelOutsideDeliveryRadius;
use App\Domain\Pricing\Exceptions\ParcelTooHeavyForTransport;

it('допускает вес, равный предельному', function (TransportType $transport): void {
    $params = pricingParams($transport);
    $input = pricingInput($transport, weightGrams: $params->maxWeightGrams());

    expect(pricingStrategy($transport)->estimate($input)->total()->amount())->toBeGreaterThan(0);
})->with([
    'пеший' => [TransportType::OnFoot],
    'лёгкий транспорт' => [TransportType::Light],
    'автомобиль' => [TransportType::Car],
]);

it('отклоняет вес на грамм больше предела', function (TransportType $transport): void {
    $params = pricingParams($transport);
    $input = pricingInput($transport, weightGrams: $params->maxWeightGrams() + 1);

    try {
        pricingStrategy($transport)->estimate($input);
        $this->fail('Ожидалось исключение о превышении веса.');
    } catch (ParcelTooHeavyForTransport $exception) {
        expect($exception->maxWeightGrams())->toBe($params->maxWeightGrams())
            ->and($exception->transport())->toBe($transport);
    }
})->with([
    'пеший' => [TransportType::OnFoot],
    'лёгкий транспорт' => [TransportType::Light],
    'автомобиль' => [TransportType::Car],
]);

it('допускает расстояние, равное радиусу обслуживания', function (TransportType $transport): void {
    $params = pricingParams($transport);
    $input = pricingInput($transport, distanceKm: $params->maxRadiusKm());

    expect(pricingStrategy($transport)->estimate($input)->etaMinutes())->toBeGreaterThan(0);
})->with([
    'пеший' => [TransportType::OnFoot],
    'лёгкий транспорт' => [TransportType::Light],
    'автомобиль' => [TransportType::Car],
]);

it('отклоняет расстояние больше радиуса обслуживания', function (TransportType $transport): void {
    $params = pricingParams($transport);
    $input = pricingInput($transport, distanceKm: $params->maxRadiusKm() + 0.1);

    try {
        pricingStrategy($transport)->estimate($input);
        $this->fail('Ожидалось исключение о выходе за радиус.');
    } catch (ParcelOutsideDeliveryRadius $exception) {
        expect($exception->maxRadiusKm())->toBe($params->maxRadiusKm())
            ->and($exception->transport())->toBe($transport);
    }
})->with([
    'пеший' => [TransportType::OnFoot],
    'лёгкий транспорт' => [TransportType::Light],
    'автомобиль' => [TransportType::Car],
]);
