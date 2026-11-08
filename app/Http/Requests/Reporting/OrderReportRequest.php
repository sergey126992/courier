<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use App\Domain\Reporting\Exceptions\InvalidReportPeriod;
use App\Domain\Reporting\Exceptions\ReportPeriodTooLong;
use App\Domain\Reporting\ReportPeriod;
use App\Http\Problem\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Параметры отчёта по заказам (ЧТЗ 8.2).
 *
 * `from` и `to` необязательны и задаются по ISO-8601 с датой и временем.
 * Значение без смещения трактуется в таймзоне приложения, а значения по
 * умолчанию берутся из `config('reporting.default_period')`, а не из
 * литералов (FR-RPT-141…146). Границы периода устанавливает доменный
 * {@see ReportPeriod}: предел длительности приходит в него аргументом.
 */
final class OrderReportRequest extends FormRequest
{
    /**
     * Секунд в сутках: предел конфигурации задан в днях.
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Доступ к отчёту проверяет Gate `reports:read` (FR-RPT-104).
     *
     * Роль в запросе не проверяется: правило живёт в домене и политике.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reports:read') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'string', 'date'],
            'to' => ['sometimes', 'nullable', 'string', 'date'],
        ];
    }

    /**
     * Формат дат, порядок границ и предел длительности (FR-RPT-145, FR-RPT-146).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkDateTimeFormat($validator, 'from');
                $this->checkDateTimeFormat($validator, 'to');

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $this->period();
                } catch (ReportPeriodTooLong) {
                    // Предел длительности — отдельный код ошибки с пределом в пояснении.
                    throw ApiException::reportPeriodTooLong($this->maxPeriodDays());
                } catch (InvalidReportPeriod) {
                    $validator->errors()->add('to', (string) __('reporting.errors.PERIOD_NOT_AFTER'));
                }
            },
        ];
    }

    /**
     * Нормализованный период отчёта — полуинтервал `[from, to)`.
     *
     * Вызывается только после успешной валидации запроса.
     *
     * @throws ReportPeriodTooLong длительность больше предела из конфигурации
     * @throws InvalidReportPeriod окончание не позже начала
     */
    public function period(): ReportPeriod
    {
        return new ReportPeriod($this->fromMoment(), $this->toMoment(), $this->maxDurationSeconds());
    }

    /**
     * Наибольшая длительность периода в днях из конфигурации.
     */
    public function maxPeriodDays(): int
    {
        return (int) config('reporting.max_period_days');
    }

    /**
     * Начало периода: значение запроса или правило по умолчанию.
     */
    private function fromMoment(): CarbonImmutable
    {
        return $this->moment('from');
    }

    /**
     * Окончание периода: значение запроса или правило по умолчанию.
     */
    private function toMoment(): CarbonImmutable
    {
        return $this->moment('to');
    }

    /**
     * Момент границы: явное значение важнее правила из конфигурации.
     */
    private function moment(string $bound): CarbonImmutable
    {
        $value = $this->input($bound);

        if (is_string($value) && $value !== '') {
            return $this->parseMoment($value);
        }

        return $this->defaultMoment($bound);
    }

    /**
     * Разбор ISO-8601: без смещения применяется таймзона приложения (FR-RPT-143).
     */
    private function parseMoment(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, (string) config('app.timezone'));
    }

    /**
     * Правило периода по умолчанию из конфигурации этапа (FR-RPT-144, FR-RPT-185).
     */
    private function defaultMoment(string $bound): CarbonImmutable
    {
        $rule = (string) config('reporting.default_period.'.$bound);
        $timezone = (string) config('app.timezone');

        return match ($rule) {
            'start_of_month' => CarbonImmutable::now($timezone)->startOfMonth(),
            'now' => CarbonImmutable::now($timezone),
            // Пустое или неизвестное правило — ошибка настройки, а не тихий дефолт.
            default => throw new InvalidArgumentException(
                sprintf('Неизвестное правило периода отчёта для «%s»: «%s».', $bound, $rule),
            ),
        };
    }

    /**
     * Предел длительности в секундах: конфигурация задаёт дни (FR-RPT-146).
     */
    private function maxDurationSeconds(): int
    {
        return $this->maxPeriodDays() * self::SECONDS_PER_DAY;
    }

    /**
     * Параметр должен быть ISO-8601 с датой и временем, а не одной датой (FR-RPT-141).
     */
    private function checkDateTimeFormat(Validator $validator, string $field): void
    {
        $value = $this->input($field);

        if (! is_string($value) || $value === '') {
            return;
        }

        // Дата, время с точностью до минут, необязательные секунды/доли и смещение.
        $pattern = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

        if (preg_match($pattern, $value) !== 1) {
            $validator->errors()->add($field, (string) __('reporting.errors.DATETIME_FORMAT'));
        }
    }
}
