@extends('preview.layout')

@section('title', $demo['title'])

@php
    // $mock загружает PreviewController (base_path + проверка is_file).
    $recommendation = 'Окрашено только основное действие, активный пункт навигации и маркер выбранного заказа, остальное нейтральное и не отвлекает от очереди.';
@endphp

@section('content')
    <div class="pv-demo-head">
        <h1 class="pv-page-title">{{ $demo['title'] }}</h1>
        <p class="pv-page-sub">{{ $demo['shows'] }}</p>
    </div>

    @foreach ([1, 2, 3] as $variant)
        <section class="pv-variant" aria-labelledby="pv-variant-{{ $variant }}">
            <div class="pv-variant-head">
                <h2 class="pv-variant-title" id="pv-variant-{{ $variant }}">Вариант {{ $variant }}</h2>
                @if ($variant === $demo['recommendedVariant'])
                    <span class="pv-reco">рекомендую</span>
                @endif
            </div>

            @if ($variant === $demo['recommendedVariant'])
                <p class="pv-variant-note">{{ $recommendation }}</p>
            @endif

            @include('preview.demos.'.$slug.'.variants.variant-'.$variant, ['mock' => $mock, 'variant' => $variant])
        </section>
    @endforeach
@endsection
