@props([
    'tabs' => [],
    'active' => null,
    'name' => 'tabs',
])

@php
    $items = [];
    foreach ($tabs as $key => $value) {
        if (is_array($value)) {
            $items[] = [
                'id' => (string) ($value['id'] ?? $key),
                'label' => (string) ($value['label'] ?? ''),
            ];
        } else {
            $items[] = ['id' => (string) $key, 'label' => (string) $value];
        }
    }

    $activeId = $active !== null ? (string) $active : ($items[0]['id'] ?? null);
    $groupId = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $name);
@endphp

<div {{ $attributes->class(['tabs-root']) }} data-tabs>
    <div class="tabs" role="tablist" aria-label="{{ __('ui.aria.tabs') }}">
        @foreach ($items as $item)
            @php
                $tabId = $groupId.'-tab-'.$item['id'];
                $panelId = $groupId.'-panel-'.$item['id'];
                $selected = $item['id'] === $activeId;
            @endphp
            <button
                type="button"
                class="tab"
                role="tab"
                id="{{ $tabId }}"
                aria-controls="{{ $panelId }}"
                aria-selected="{{ $selected ? 'true' : 'false' }}"
                tabindex="{{ $selected ? '0' : '-1' }}"
                data-tab-id="{{ $item['id'] }}"
            >{{ $item['label'] }}</button>
        @endforeach
    </div>

    <div class="tabs-panels">
        {{ $slot }}
    </div>
</div>
