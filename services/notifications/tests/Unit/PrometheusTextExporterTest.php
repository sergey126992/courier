<?php

declare(strict_types=1);

use App\Metrics\MetricsFormat;
use App\Metrics\MetricsSnapshot;
use App\Metrics\PrometheusSample;
use App\Metrics\PrometheusTextExporter;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Формат Prometheus text 0.0.4 (FR-OPS-501…506, ЧТЗ 9.1 п. 3–4)
|--------------------------------------------------------------------------
|
| Проверяются строки ответа: имя метрики, тип, HELP, порядок меток,
| накопительные границы гистограммы и белый список меток. Значения
| собираются в снимок напрямую: сеть и база тесту не нужны.
|
*/

/**
 * Экспортёр с белым списком меток ЧТЗ (FR-OPS-507).
 */
function prometheusTestExporter(): PrometheusTextExporter
{
    return new PrometheusTextExporter(['channel', 'state', 'event_type', 'stream', 'result', 'platform', 'provider']);
}

/**
 * Снимок с одной серией каждого вида.
 */
function prometheusTestSnapshot(): MetricsSnapshot
{
    return new MetricsSnapshot(
        counters: [
            MetricsSnapshot::SENT => ['email' => 42],
            MetricsSnapshot::FAILED => ['push' => 3],
            MetricsSnapshot::RETRIED => ['push' => 5],
        ],
        durations: [
            'email' => ['buckets' => ['0.1' => 0, '0.5' => 0, '1' => 1, '2' => 1, '5' => 1, '10' => 1], 'sum' => 0.75, 'count' => 1],
        ],
        dlqDepth: [['channel' => 'push', 'state' => 'open', 'value' => 3]],
        consumers: [['stream' => 'events.orders', 'lag_seconds' => 4, 'pending' => 2]],
        outboxOldestUnpublishedSeconds: 7,
        degraded: [],
        generatedAt: Carbon::parse('2026-11-07 10:00:00', 'UTC'),
    );
}

it('пишет имя, тип, HELP и значения каждой метрики', function (): void {
    $text = prometheusTestExporter()->export(prometheusTestSnapshot());

    expect($text)->toContain(
        "# HELP notifications_sent_total Отправленные уведомления по каналам\n"
        ."# TYPE notifications_sent_total counter\n"
        ."notifications_sent_total{channel=\"email\"} 42\n",
    )
        ->and($text)->toContain("# TYPE notifications_failed_total counter\nnotifications_failed_total{channel=\"push\"} 3\n")
        ->and($text)->toContain("# TYPE notifications_retried_total counter\nnotifications_retried_total{channel=\"push\"} 5\n")
        ->and($text)->toContain('# TYPE notifications_send_duration_seconds histogram')
        ->and($text)->toContain('notifications_send_duration_seconds_bucket{channel="email",le="0.1"} 0')
        ->and($text)->toContain('notifications_send_duration_seconds_bucket{channel="email",le="+Inf"} 1')
        ->and($text)->toContain('notifications_send_duration_seconds_sum{channel="email"} 0.75')
        ->and($text)->toContain('notifications_send_duration_seconds_count{channel="email"} 1')
        ->and($text)->toContain("# TYPE notifications_dlq_depth gauge\nnotifications_dlq_depth{channel=\"push\",state=\"open\"} 3\n")
        ->and($text)->toContain("# TYPE notifications_consumer_lag_seconds gauge\nnotifications_consumer_lag_seconds{stream=\"events.orders\"} 4\n")
        ->and($text)->toContain("# TYPE notifications_consumer_pending gauge\nnotifications_consumer_pending{stream=\"events.orders\"} 2\n")
        ->and($text)->toContain("# TYPE notifications_outbox_oldest_unpublished_seconds gauge\nnotifications_outbox_oldest_unpublished_seconds 7\n");
});

it('сортирует границы гистограммы по числу, а не по строке', function (): void {
    $snapshot = new MetricsSnapshot(
        counters: [MetricsSnapshot::SENT => [], MetricsSnapshot::FAILED => [], MetricsSnapshot::RETRIED => []],
        durations: ['push' => ['buckets' => ['10' => 2, '2' => 2, '0.5' => 1], 'sum' => 4.0, 'count' => 2]],
        dlqDepth: [],
        consumers: [],
        outboxOldestUnpublishedSeconds: 0,
        degraded: [],
        generatedAt: Carbon::parse('2026-11-07 10:00:00', 'UTC'),
    );

    $text = prometheusTestExporter()->export($snapshot);

    preg_match_all('/notifications_send_duration_seconds_bucket\{channel="push",le="([^"]+)"\} (\d+)/', $text, $matches);

    expect($matches[1])->toBe(['0.5', '2', '10', '+Inf'])
        ->and($matches[2])->toBe(['1', '2', '2', '2']);
});

it('отбрасывает метки вне белого списка', function (): void {
    $text = prometheusTestExporter()->render('notifications_sent_total', 'Счётчик', 'counter', [
        new PrometheusSample('notifications_sent_total', [
            'channel' => 'email',
            'user_id' => '7',
            'order_id' => '42',
            'notification_id' => '42',
            'correlation_id' => 'corr-1',
        ], 5.0),
    ]);

    expect($text)->toContain('notifications_sent_total{channel="email"} 5')
        ->and($text)->not->toContain('user_id')
        ->and($text)->not->toContain('order_id')
        ->and($text)->not->toContain('notification_id')
        ->and($text)->not->toContain('correlation_id');
});

it('экранирует значения меток и текст HELP', function (): void {
    $text = prometheusTestExporter()->render('custom_metric', 'строка с "кавычкой"', 'gauge', [
        new PrometheusSample('custom_metric', ['channel' => "a\"b\\c\nd"], 1.0),
    ]);

    // Кавычка, слэш и перевод строки в значении метки экранируются так,
    // как требует формат 0.0.4: \" , \\ и \n.
    expect($text)->toContain('custom_metric{channel="a\\"b\\\\c\\nd"} 1')
        ->and($text)->toContain('# HELP custom_metric строка с "кавычкой"');
});

it('пишет числа без экспоненты и хвостовых нулей', function (): void {
    expect(MetricsFormat::float(1.0))->toBe('1')
        ->and(MetricsFormat::float(0.1))->toBe('0.1')
        ->and(MetricsFormat::float(2.25))->toBe('2.25')
        ->and(MetricsFormat::float(1000000.0))->toBe('1000000')
        ->and(MetricsFormat::float(INF))->toBe('+Inf');
});
