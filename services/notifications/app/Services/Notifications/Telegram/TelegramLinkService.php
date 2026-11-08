<?php

declare(strict_types=1);

namespace App\Services\Notifications\Telegram;

use App\Domain\Notifications\ChannelBindingStatus;
use App\Domain\Notifications\Exceptions\TelegramBindingAbsent;
use App\Domain\Notifications\Exceptions\TelegramChannelDisabled;
use App\Domain\Notifications\Exceptions\TelegramLinkTokenExpired;
use App\Domain\Notifications\Exceptions\TelegramLinkTokenInvalid;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\TelegramLinkTokenHash;
use App\Models\ChannelBinding;
use App\Models\TelegramLinkToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Привязка чата Telegram по одноразовой ссылке (ЧТЗ 5.2, FR-NTF-411…421).
 *
 * Открытое значение токена создаётся здесь и больше нигде не сохраняется:
 * в `telegram_link_tokens` лежит только хеш (FR-NTF-413), поэтому повторный
 * выпуск не восстанавливает прежнюю ссылку. Подтверждение одноразовое
 * (`used_at`) и выполняется в одной транзакции с заменой активной привязки:
 * частичный уникальный индекс `(user_id, channel) WHERE revoked_at IS NULL`
 * не даёт пользователю двух активных привязок (FR-NTF-416).
 *
 * Адрес привязки (`chat_id`) — персональные данные: он записывается только
 * в базу и не попадает ни в журнал, ни в ответы API (FR-NTF-419).
 */
final class TelegramLinkService
{
    /**
     * Выпускает новый токен привязки и аннулирует предыдущий действующий.
     *
     * @throws TelegramChannelDisabled канал Telegram выключен в окружении (ЧТЗ 8.3)
     */
    public function start(int $userId): TelegramLinkStart
    {
        $this->assertChannelEnabled();

        $plain = $this->generateToken();
        $now = Carbon::now('UTC');
        $expiresAt = $now->copy()->addSeconds($this->ttlSeconds());

        DB::transaction(function () use ($userId, $plain, $expiresAt): void {
            // Действующим считается последний выпущенный токен (ЧТЗ 8.4):
            // прежние неиспользованные строки удаляются, иначе по одной
            // ссылке можно было бы подтвердить привязку дважды.
            TelegramLinkToken::query()
                ->where('user_id', $userId)
                ->whereNull('used_at')
                ->delete();

            TelegramLinkToken::query()->create([
                'user_id' => $userId,
                'token_hash' => TelegramLinkTokenHash::hash($plain),
                'expires_at' => $expiresAt,
                'used_at' => null,
            ]);
        });

        return new TelegramLinkStart(
            $this->deepLink($plain),
            $expiresAt,
            ChannelBindingStatus::Pending,
        );
    }

    /**
     * Подтверждает привязку чата действующим токеном (FR-NTF-414…416).
     *
     * @throws TelegramLinkTokenInvalid токен не найден или уже использован
     * @throws TelegramLinkTokenExpired срок действия токена истёк
     */
    public function confirm(string $token, string $address): ChannelBinding
    {
        $plain = trim($token);
        $chatId = trim($address);
        $now = Carbon::now('UTC');

        return DB::transaction(function () use ($plain, $chatId, $now): ChannelBinding {
            $record = TelegramLinkToken::query()
                ->where('token_hash', TelegramLinkTokenHash::hash($plain))
                ->lockForUpdate()
                ->first();

            // Сравнение постоянным по времени: по времени ответа нельзя
            // подобрать значение токена посимвольно (FR-NTF-413).
            if ($record === null || ! TelegramLinkTokenHash::matches($plain, (string) $record->token_hash)) {
                throw new TelegramLinkTokenInvalid;
            }

            if ($record->used_at !== null) {
                throw new TelegramLinkTokenInvalid;
            }

            if ($record->expires_at->lessThanOrEqualTo($now)) {
                throw new TelegramLinkTokenExpired;
            }

            $record->forceFill(['used_at' => $now])->save();

            return $this->bind((int) $record->user_id, $chatId, $now);
        });
    }

    /**
     * Отвязка Telegram пользователем (FR-NTF-417): строка не удаляется.
     *
     * @throws TelegramBindingAbsent активной привязки нет
     */
    public function revoke(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $binding = $this->activeBinding($userId);

            if ($binding === null) {
                throw new TelegramBindingAbsent;
            }

            $binding->forceFill(['revoked_at' => Carbon::now('UTC')])->save();
        });
    }

    /**
     * Производное состояние привязки Telegram (ЧТЗ 5.2).
     */
    public function status(int $userId): ChannelBindingStatus
    {
        if ($this->activeBinding($userId) !== null) {
            return ChannelBindingStatus::Verified;
        }

        if ($this->pendingTokenExists($userId)) {
            return ChannelBindingStatus::Pending;
        }

        $historyExists = ChannelBinding::query()
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::Telegram->value)
            ->exists();

        return $historyExists ? ChannelBindingStatus::Revoked : ChannelBindingStatus::Absent;
    }

    /**
     * Есть ли действующий (неиспользованный и неистёкший) токен.
     */
    public function pendingTokenExists(int $userId): bool
    {
        return TelegramLinkToken::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now('UTC'))
            ->exists();
    }

    /**
     * Активная привязка Telegram: одна на пользователя (FR-NTF-416).
     */
    public function activeBinding(int $userId): ?ChannelBinding
    {
        return ChannelBinding::query()
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::Telegram->value)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Создание или обновление активной привязки в текущей транзакции.
     */
    private function bind(int $userId, string $chatId, Carbon $now): ChannelBinding
    {
        $active = $this->activeBinding($userId);

        if ($active !== null && (string) $active->address === $chatId) {
            // Адрес не изменился: привязка остаётся одна, обновляется
            // только момент подтверждения (ЧТЗ 8.4).
            $active->forceFill(['verified_at' => $now])->save();

            // Ответ собирается по значениям из базы: моменты времени хранятся
            // в timestamptz и читаются в UTC, без пересчёта часового пояса
            // приложения (ЧТЗ 7, п. 5).
            return $active->refresh();
        }

        // Смена адреса: прежняя строка сохраняется в истории с revoked_at,
        // потому что активная привязка у пользователя одна (FR-NTF-416).
        $active?->forceFill(['revoked_at' => $now])->save();

        $created = ChannelBinding::query()->create([
            'user_id' => $userId,
            'channel' => NotificationChannel::Telegram->value,
            'address' => $chatId,
            'verified_at' => $now,
            'revoked_at' => null,
        ]);

        // Ответ собирается по значениям из базы: timestamptz читается в UTC
        // независимо от часового пояса приложения (ЧТЗ 7, п. 5).
        return $created->refresh();
    }

    /**
     * Канал Telegram должен быть включён в окружении (ЧТЗ 8.1, ЧТЗ 8.3).
     *
     * @throws TelegramChannelDisabled канал выключен
     */
    private function assertChannelEnabled(): void
    {
        $enabled = (array) config('notifications.enabled_channels', []);

        if (! in_array(NotificationChannel::Telegram->value, $enabled, true)) {
            throw new TelegramChannelDisabled;
        }
    }

    /**
     * Открытое значение токена: 32 случайных байта в hex.
     *
     * Формат подходит для параметра `start` deep-link Telegram
     * (ЧТЗ 8.3, FR-NTF-411).
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Deep-link на бота с одноразовым токеном (FR-NTF-411).
     */
    private function deepLink(string $plain): string
    {
        $username = ltrim(trim((string) config('notifications.telegram.bot_username')), '@');

        if ($username === '') {
            // Без имени бота ссылка недостижима: конфигурация проверяется
            // при старте процесса (FR-NTF-441), здесь защита от пустой ссылки.
            throw new RuntimeException('Не задано имя бота Telegram.');
        }

        return sprintf('https://t.me/%s?start=%s', $username, $plain);
    }

    /**
     * Время жизни токена в секундах из конфигурации (FR-NTF-412).
     */
    private function ttlSeconds(): int
    {
        return max(1, (int) config('notifications.telegram.link_token_ttl_seconds', 1));
    }
}
