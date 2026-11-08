@php
    $variant = (int) $variant;
    $primaryClass = $variant === 1 ? 'pv-btn-primary' : 'pv-btn-solid';
    $assignClass = $variant === 2 ? 'pv-btn-solid' : 'pv-btn-outline';
@endphp

<div class="pv-screen pv-v{{ $variant }}">
    <header class="pv-topbar">
        <div class="pv-brand">
            <span class="pv-brand-mark" aria-hidden="true">К</span>
            <span class="pv-brand-name">Карго</span>
        </div>

        <nav class="pv-nav" aria-label="Основная навигация">
            @foreach ($mock['nav'] as $item)
                <a class="pv-nav-link" href="#" @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="pv-topbar-end">
            <div class="pv-theme-toggle" role="group" aria-label="Тема оформления">
                <button type="button" class="pv-theme-option" data-theme-toggle data-theme-value="light" aria-pressed="true">Светлая</button>
                <button type="button" class="pv-theme-option" data-theme-toggle data-theme-value="dark" aria-pressed="false">Тёмная</button>
            </div>

            <div class="pv-user">
                <span class="pv-avatar" aria-hidden="true">{{ $mock['user']['initials'] }}</span>
                <span class="pv-user-text">
                    <span class="pv-user-name">{{ $mock['user']['name'] }}</span>
                    <span class="pv-user-role">{{ $mock['user']['role'] }}</span>
                </span>
            </div>
        </div>
    </header>

    <div class="pv-main">
        <div class="pv-page-head">
            <div>
                <h3 class="pv-page-title">Очередь заказов</h3>
                <p class="pv-page-sub">Зона «{{ $mock['summary']['zone'] }}» · обновлено {{ $mock['summary']['updatedAt'] }}</p>
            </div>

            <div class="pv-page-actions">
                @if ($variant === 2)
                    <p class="pv-counter"><span class="pv-dot" aria-hidden="true"></span>{{ $mock['summary']['pending'] }} требуют назначения</p>
                @endif

                <button type="button" class="pv-btn {{ $primaryClass }}">Назначить курьера</button>
            </div>
        </div>

        @if ($variant === 3)
            <div class="pv-focus">
                <div>
                    <p class="pv-focus-title">{{ $mock['focus']['title'] }}</p>
                    <p class="pv-focus-text">{{ $mock['focus']['text'] }}</p>
                </div>
                <div class="pv-focus-actions">
                    <button type="button" class="pv-btn pv-btn-on-accent">{{ $mock['focus']['action'] }}</button>
                </div>
            </div>
        @endif

        <div class="pv-filters">
            @foreach ($mock['filters'] as $name => $filter)
                <div class="pv-field">
                    <label class="pv-label" for="pv{{ $variant }}-{{ $name }}">{{ $filter['label'] }}</label>
                    <select class="pv-select" id="pv{{ $variant }}-{{ $name }}" name="{{ $name }}">
                        @foreach ($filter['options'] as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>

        @if ($variant === 2)
            <p class="pv-zone"><span class="pv-dot" aria-hidden="true"></span>Активная зона: {{ $mock['summary']['zone'] }}</p>
        @endif

        <div class="pv-grid">
            <section class="pv-panel" aria-labelledby="pv{{ $variant }}-orders">
                <div class="pv-panel-head">
                    <h4 class="pv-panel-title" id="pv{{ $variant }}-orders">Заказы без курьера</h4>
                    <span class="pv-panel-note">{{ $mock['summary']['pending'] }} в очереди</span>
                </div>

                <ul class="pv-orders">
                    @foreach ($mock['orders'] as $order)
                        <li class="pv-order" @if (in_array($variant, [1, 2], true) && $order['selected']) data-selected="true" @endif>
                            <div class="pv-order-main">
                                <div class="pv-order-head">
                                    <span class="pv-order-number">{{ $order['number'] }}</span>
                                    <span class="pv-badge">{{ $order['status'] }}</span>
                                    @if ($order['flag'])
                                        <span class="pv-badge pv-badge-{{ $order['flag']['tone'] }}">{{ $order['flag']['label'] }}</span>
                                    @endif
                                </div>
                                <p class="pv-order-route">
                                    <span>{{ $order['pickup'] }}</span>
                                    <span aria-hidden="true">→</span>
                                    <span>{{ $order['dropoff'] }}</span>
                                </p>
                            </div>

                            <dl class="pv-order-meta">
                                <div><dt>Транспорт</dt><dd>{{ $order['vehicle'] }}</dd></div>
                                <div><dt>Вес</dt><dd>{{ $order['weight'] }}</dd></div>
                                <div><dt>ETA</dt><dd>{{ $order['eta'] }}</dd></div>
                                <div><dt>Цена</dt><dd>{{ $order['price'] }}</dd></div>
                            </dl>

                            <div class="pv-order-action">
                                <button type="button" class="pv-btn pv-btn-sm pv-btn-outline">Выбрать</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <aside class="pv-side" aria-labelledby="pv{{ $variant }}-candidates">
                <div class="pv-panel">
                    <div class="pv-panel-head">
                        <h4 class="pv-panel-title" id="pv{{ $variant }}-candidates">Кандидаты</h4>
                        <span class="pv-panel-note">{{ count($mock['candidates']) }} рядом</span>
                    </div>

                    <div class="pv-candidates">
                        @foreach ($mock['candidates'] as $candidate)
                            <div class="pv-candidate">
                                <div class="pv-candidate-head">
                                    <span class="pv-candidate-name">{{ $candidate['name'] }}</span>
                                    <span class="pv-candidate-distance">{{ $candidate['distance'] }}</span>
                                </div>
                                <p class="pv-candidate-meta">{{ $candidate['vehicle'] }} · подход {{ $candidate['eta'] }}</p>
                                <button type="button" class="pv-btn pv-btn-sm pv-btn-block {{ $assignClass }}">Назначить</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>
