<?php

declare(strict_types=1);

use App\Models\ChannelBinding;
use App\Models\TelegramLinkToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Guard-тесты схемы привязки Telegram (ЧТЗ 7.1, ЧТЗ 9.2 п. 10)
|--------------------------------------------------------------------------
|
| Уникальность и индексы токенов привязки держит база, а не проверка перед
| вставкой. Частичный уникальный индекс активной привязки проверяется
| в общем guard-тесте схемы сервиса.
|
*/

uses(RefreshDatabase::class);

it('создаёт таблицу токенов привязки', function (): void {
    expect(Schema::hasTable('telegram_link_tokens'))->toBeTrue()
        ->and(Schema::hasColumn('telegram_link_tokens', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('telegram_link_tokens', 'token_hash'))->toBeTrue()
        ->and(Schema::hasColumn('telegram_link_tokens', 'expires_at'))->toBeTrue()
        ->and(Schema::hasColumn('telegram_link_tokens', 'used_at'))->toBeTrue()
        ->and(Schema::hasColumn('telegram_link_tokens', 'created_at'))->toBeTrue();
});

it('хранит моменты времени токена как timestamptz и не заводит updated_at', function (): void {
    $types = DB::table('information_schema.columns')
        ->select('column_name', 'data_type')
        ->where('table_schema', 'public')
        ->where('table_name', 'telegram_link_tokens')
        ->pluck('data_type', 'column_name');

    expect($types['expires_at'])->toBe('timestamp with time zone')
        ->and($types['used_at'])->toBe('timestamp with time zone')
        ->and($types['created_at'])->toBe('timestamp with time zone')
        ->and(Schema::hasColumn('telegram_link_tokens', 'updated_at'))->toBeFalse();
});

it('запрещает два токена с одинаковым хешем', function (): void {
    $token = TelegramLinkToken::factory()->create(['token_hash' => hash('sha256', 'same')]);

    expectDatabaseRejection(function () use ($token): void {
        TelegramLinkToken::factory()->create([
            'user_id' => 99,
            'token_hash' => (string) $token->token_hash,
        ]);
    });
});

it('держит индексы поиска последнего токена и очистки по сроку', function (): void {
    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'telegram_link_tokens')
        ->pluck('indexdef', 'indexname');

    expect((string) $indexes['telegram_link_tokens_token_hash_unique'])->toContain('UNIQUE')
        ->and((string) $indexes['telegram_link_tokens_user_created_desc_index'])->toContain('DESC')
        ->and((string) $indexes['telegram_link_tokens_expires_at_index'])->toContain('(expires_at)');
});

it('держит одну активную привязку Telegram и освобождает ключ после отзыва', function (): void {
    $first = ChannelBinding::factory()->create([
        'user_id' => 77,
        'channel' => 'telegram',
        'address' => '100',
        'verified_at' => now()->utc(),
    ]);

    expectDatabaseRejection(function (): void {
        ChannelBinding::factory()->create([
            'user_id' => 77,
            'channel' => 'telegram',
            'address' => '200',
            'verified_at' => now()->utc(),
        ]);
    });

    $first->forceFill(['revoked_at' => now()->utc()])->save();

    $replacement = ChannelBinding::factory()->create([
        'user_id' => 77,
        'channel' => 'telegram',
        'address' => '200',
        'verified_at' => now()->utc(),
    ]);

    expect($replacement->revoked_at)->toBeNull();
});
