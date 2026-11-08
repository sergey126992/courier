@props([
    'columns' => [],
    'rows' => [],
    'row' => null,
])

@php
    $labels = [];
    foreach ($columns as $key => $value) {
        if (is_array($value)) {
            $labels[(string) ($value['key'] ?? $key)] = (string) ($value['label'] ?? '');
        } else {
            $labels[(string) $key] = (string) $value;
        }
    }

    $items = is_iterable($rows) ? (is_array($rows) ? $rows : iterator_to_array($rows)) : [];
    $renderRow = is_callable($row) ? $row : null;
    $valueText = static function (mixed $value): string {
        if ($value instanceof \Illuminate\Contracts\Support\Htmlable) {
            return trim(strip_tags($value->toHtml()));
        }

        return is_scalar($value) ? (string) $value : '';
    };
@endphp

<div {{ $attributes->class(['data-list']) }}>
    <table class="data-list-table">
        <thead>
            <tr>
                @foreach ($labels as $label)
                    <th scope="col">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    @foreach ($labels as $key => $label)
                        @php
                            $value = $item[$key] ?? null;
                            $full = $valueText($value);
                        @endphp
                        <td>
                            <span class="list-value" @if ($full !== '') title="{{ $full }}" @endif>{{ $value }}</span>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <ul class="data-list-cards">
        @foreach ($items as $item)
            @php
                $keys = array_keys($labels);
                $titleKey = $keys[0] ?? null;
                $titleValue = $titleKey !== null ? ($item[$titleKey] ?? '') : '';
                $rest = array_slice($labels, 1, null, true);
            @endphp
            <li class="data-list-card">
                <div class="data-list-card-head">
                    @php $titleFull = $valueText($titleValue); @endphp
                    <span class="data-list-card-title list-value" @if ($titleFull !== '') title="{{ $titleFull }}" @endif>{{ $titleValue }}</span>
                </div>

                @if ($rest !== [])
                    <dl class="data-list-card-meta">
                        @foreach ($rest as $key => $label)
                            @php $full = $valueText($item[$key] ?? null); @endphp
                            <div>
                                <dt>{{ $label }}</dt>
                                <dd class="list-value" @if ($full !== '') title="{{ $full }}" @endif>{{ $item[$key] ?? '' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if ($renderRow !== null)
                    <div class="data-list-card-extra">{{ $renderRow($item) }}</div>
                @endif
            </li>
        @endforeach
    </ul>
</div>
