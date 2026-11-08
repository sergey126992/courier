<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Сообщения валидации
|--------------------------------------------------------------------------
|
| Переведены только правила, которые используются на этапе M1.
| Остальные ключи берутся из встроенного английского словаря фреймворка.
|
*/

return [
    'array' => 'Поле «:attribute» должно быть структурой.',
    'between' => [
        'numeric' => 'Поле «:attribute» должно быть от :min до :max.',
    ],
    'boolean' => 'Поле «:attribute» должно быть логическим значением.',
    'confirmed' => 'Поле «:attribute» не совпадает с подтверждением.',
    'date' => 'Поле «:attribute» должно содержать корректную дату.',
    'date_format' => 'Поле «:attribute» должно соответствовать формату :format.',
    'decimal' => 'Поле «:attribute» должно содержать число не более чем с :decimal знаками после запятой.',
    'different' => 'Поле «:attribute» должно отличаться от поля «:other».',
    'email' => 'Поле «:attribute» должно содержать корректный адрес электронной почты.',
    'enum' => 'Выбранное значение поля «:attribute» недопустимо.',
    'gt' => [
        'numeric' => 'Поле «:attribute» должно быть больше :value.',
    ],
    'in' => 'Поле «:attribute» содержит недопустимое значение.',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'max' => [
        'string' => 'Поле «:attribute» не должно быть длиннее :max символов.',
    ],
    'min' => [
        'numeric' => 'Поле «:attribute» должно быть не меньше :min.',
        'string' => 'Поле «:attribute» должно содержать не меньше :min символов.',
    ],
    'numeric' => 'Поле «:attribute» должно быть числом.',
    'regex' => 'Поле «:attribute» имеет неверный формат.',
    'prohibited' => 'Поле «:attribute» изменять нельзя.',
    'prohibited_unless' => 'Поле «:attribute» запрещено, пока :other не входит в :values.',
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'required_if' => 'Поле «:attribute» обязательно, когда :other равно :value.',
    'required_with' => 'Поле «:attribute» обязательно, когда заполнено :values.',
    'size' => [
        'string' => 'Поле «:attribute» должно содержать :size символов.',
    ],
    'string' => 'Поле «:attribute» должно быть строкой.',
    'unique' => 'Такое значение поля «:attribute» уже используется.',

    'attributes' => [
        'base_fee_amount' => 'базовая ставка',
        'boundary' => 'граница зоны',
        'code' => 'код зоны',
        'comment' => 'комментарий',
        'company' => 'компания',
        'currency' => 'валюта',
        'dropoff' => 'адрес доставки',
        'dropoff.district' => 'район адреса доставки',
        'dropoff.house' => 'дом адреса доставки',
        'dropoff.latitude' => 'широта адреса доставки',
        'dropoff.longitude' => 'долгота адреса доставки',
        'dropoff.street' => 'улица адреса доставки',
        'is_active' => 'признак активности',
        'parcel' => 'посылка',
        'parcel.declared_value' => 'заявленная стоимость',
        'parcel.declared_value.amount' => 'сумма заявленной стоимости',
        'parcel.declared_value.currency' => 'валюта заявленной стоимости',
        'parcel.dimensions' => 'габариты',
        'parcel.dimensions.height' => 'высота',
        'parcel.dimensions.length' => 'длина',
        'parcel.dimensions.width' => 'ширина',
        'parcel.weight_grams' => 'вес посылки',
        'reason' => 'причина',
        'recorded_at' => 'время точки',
        'quietFrom' => 'начало тихих часов',
        'quietTo' => 'конец тихих часов',
        'quietTimezone' => 'часовой пояс',
        'zone' => 'зона',
        'zone_id' => 'зона',
        'default_address' => 'адрес по умолчанию',
        'default_address.district' => 'район',
        'default_address.house' => 'дом',
        'default_address.latitude' => 'широта',
        'default_address.longitude' => 'долгота',
        'declared_value' => 'заявленная стоимость',
        'declared_value.amount' => 'сумма заявленной стоимости',
        'declared_value.currency' => 'валюта заявленной стоимости',
        'default_address.street' => 'улица',
        'dimensions' => 'габариты',
        'dimensions.height' => 'высота',
        'dimensions.length' => 'длина',
        'dimensions.width' => 'ширина',
        'distance_km' => 'расстояние',
        'email' => 'email',
        'is_paid_parking' => 'признак платной парковки',
        'latitude' => 'широта',
        'light_vehicle_kind' => 'вид лёгкого транспорта',
        'longitude' => 'долгота',
        'name' => 'имя',
        'password' => 'пароль',
        'password_confirmation' => 'подтверждение пароля',
        'phone' => 'телефон',
        'pickup' => 'адрес забора',
        'pickup.district' => 'район адреса забора',
        'pickup.house' => 'дом адреса забора',
        'pickup.latitude' => 'широта адреса забора',
        'pickup.longitude' => 'долгота адреса забора',
        'pickup.street' => 'улица адреса забора',
        'requested_at' => 'момент заявки',
        'role' => 'роль',
        'token' => 'токен',
        'transport' => 'транспорт',
        'weight_grams' => 'вес посылки',
    ],
];
