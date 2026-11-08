@extends('preview.layout')

@section('title', 'Каталог demo')

@section('content')
    <div class="pv-demo-head">
        <h1 class="pv-page-title">Preview</h1>
        <p class="pv-page-sub">Каталог demo-страниц интерфейса. Последние сверху.</p>
    </div>

    <div class="pv-catalog">
        @forelse ($demos as $index => $demo)
            <div class="pv-catalog-row">
                <span class="pv-catalog-num">{{ $index + 1 }}</span>
                <div class="pv-catalog-body">
                    <a class="pv-catalog-title" href="{{ route($demo['route'], ['demo' => $demo['slug']]) }}">{{ $demo['title'] }}</a>
                    <p class="pv-catalog-shows">{{ $demo['shows'] }}</p>
                    <p class="pv-catalog-meta">
                        <span class="pv-badge">{{ $demo['status'] }}</span>
                        <span class="pv-catalog-reco">Рекомендую: вариант {{ $demo['recommendedVariant'] }}</span>
                    </p>
                </div>
            </div>
        @empty
            <div class="pv-catalog-row">
                <span class="pv-catalog-num">—</span>
                <div class="pv-catalog-body">
                    <p class="pv-catalog-shows">Demo пока нет.</p>
                </div>
            </div>
        @endforelse
    </div>
@endsection
