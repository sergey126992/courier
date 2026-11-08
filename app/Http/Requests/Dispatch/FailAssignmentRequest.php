<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use App\Domain\Dispatch\FailureReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Неудачная попытка вручения (ЧТЗ 8.3).
 *
 * Причина обязательна и берётся только из закрытого перечня; комментарий
 * необязателен и ограничен 500 символами. Владение назначением проверяет
 * политика в контроллере: чужое назначение должно быть неотличимо от
 * несуществующего (404), поэтому здесь проверки прав нет.
 */
final class FailAssignmentRequest extends FormRequest
{
    /**
     * Максимальная длина комментария курьера.
     */
    public const MAX_COMMENT_LENGTH = 500;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(FailureReason::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_COMMENT_LENGTH],
        ];
    }

    /**
     * Причина неудачи из перечня.
     */
    public function reason(): FailureReason
    {
        return FailureReason::from((string) $this->validated('reason'));
    }

    /**
     * Комментарий курьера; null — комментарий не передан.
     */
    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) ? $comment : null;
    }
}
