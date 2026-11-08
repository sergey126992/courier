<?php

declare(strict_types=1);

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Профиль пользователя (ЧТЗ 9.1, пункт 5; FR-UI-118)
|--------------------------------------------------------------------------
|
| `account` доступен только авторизованным: любая роль видит имя, email,
| роль и статус почты, а поля отправителя и их правка есть только у роли
| «отправитель» и проверяются политикой UserPolicy.
|
*/

it('закрывает профиль гостю', function (): void {
    $this->get('/account')->assertRedirect('/login');
});

it('показывает курьеру и администратору профиль без полей отправителя', function (): void {
    $users = [
        User::factory()->courier()->create(),
        User::factory()->admin()->create(),
    ];

    foreach ($users as $user) {
        $response = $this->actingAs($user)->get('/account');

        $response->assertOk()
            ->assertSee(__('ui.profile.title'))
            ->assertSee((string) $user->name)
            ->assertSee((string) $user->email)
            ->assertSee($user->role->label())
            ->assertSee(__('ui.profile.future_screens'))
            ->assertDontSee(__('ui.profile.phone'))
            ->assertDontSee(__('ui.profile.company'))
            ->assertDontSee('action="'.route('account.update').'"', false);
    }
});

it('показывает отправителю поля профиля отправителя', function (): void {
    $user = User::factory()->sender()->create();
    $user->senderProfile?->update([
        'phone' => '+7 900 000-00-00',
        'company' => 'ООО Ромашка',
        'default_address' => ['district' => 'Центральный', 'street' => 'Старая', 'house' => '1'],
    ]);

    $this->actingAs($user)->get('/account')
        ->assertOk()
        ->assertSee(__('ui.profile.sender_title'))
        ->assertSee(__('ui.profile.phone'))
        ->assertSee(__('ui.profile.company'))
        ->assertSee(__('ui.profile.address'))
        ->assertSee('+7 900 000-00-00')
        ->assertSee('ООО Ромашка')
        ->assertSee('Старая')
        ->assertSee('action="'.route('account.update').'"', false);
});

it('позволяет отправителю изменить телефон, компанию и адрес', function (): void {
    $user = User::factory()->sender()->create();
    $user->senderProfile?->update([
        'default_address' => [
            'district' => 'Центральный',
            'street' => 'Старая',
            'house' => '1',
            'latitude' => 55.75,
            'longitude' => 37.61,
        ],
    ]);

    $this->actingAs($user)
        ->post(route('account.update'), [
            'phone' => '+7 900 111-11-11',
            'company' => 'ООО Лютик',
            'default_address' => [
                'district' => 'Северный',
                'street' => 'Новая',
                'house' => '5',
            ],
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHas('status', __('ui.profile.saved'));

    $profile = $user->senderProfile()->sole();

    expect($profile->phone)->toBe('+7 900 111-11-11')
        ->and($profile->company)->toBe('ООО Лютик')
        ->and((array) $profile->default_address)->toMatchArray([
            'district' => 'Северный',
            'street' => 'Новая',
            'house' => '5',
            // Координаты форма не показывает и не должна затирать.
            'latitude' => 55.75,
            'longitude' => 37.61,
        ]);
});

it('показывает статус почты и повторную отправку письма', function (): void {
    $unverified = User::factory()->unverified()->courier()->create();

    $this->actingAs($unverified)->get('/account')
        ->assertOk()
        ->assertSee(__('ui.profile.email_unverified'))
        ->assertSee(__('ui.actions.resend_verification'));

    $verified = User::factory()->courier()->create();

    $this->actingAs($verified)->get('/account')
        ->assertOk()
        ->assertSee(__('ui.profile.email_verified'))
        ->assertDontSee(__('ui.actions.resend_verification'));
});

it('не меняет чужой профиль и отклоняет правку роли без права', function (): void {
    $actor = User::factory()->sender()->create(['name' => 'Первый Отправитель']);
    $other = User::factory()->sender()->create(['name' => 'Второй Отправитель']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($actor)
        ->post(route('account.update'), ['name' => 'Новое имя', 'phone' => '+7 900 222-22-22'])
        ->assertRedirect(route('account'));

    expect($actor->fresh()?->name)->toBe('Новое имя')
        ->and($other->fresh()?->name)->toBe('Второй Отправитель');

    $this->assertDatabaseMissing('sender_profiles', [
        'user_id' => $other->getKey(),
        'phone' => '+7 900 222-22-22',
    ]);

    // Администратор не имеет личного профиля для правки: отказ даёт политика.
    $this->actingAs($admin)
        ->post(route('account.update'), ['name' => 'Админ'])
        ->assertStatus(403);
});
