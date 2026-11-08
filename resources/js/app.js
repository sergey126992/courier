import './bootstrap';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

/*
|--------------------------------------------------------------------------
| Alpine.js: только локальное поведение оболочки
|--------------------------------------------------------------------------
|
| Тема, drawer, модальные окна и подтверждения. Бизнес-правил здесь нет:
| роль, доступность пунктов и результаты действий готовит сервер (Blade,
| policies), Alpine лишь переключает локальное состояние интерфейса.
|
*/

window.Alpine = Alpine;
window.Livewire = Livewire;

/*
| Опции переключателя темы (FR-UI-154, 155): cookie и localStorage хранят
| выбранное значение (light | dark | system), data-theme на <html> —
| всегда разрешённое light | dark.
*/
Alpine.data('themeToggle', (initial = 'system', cookieName = 'ui_theme') => ({
    theme: initial,
    cookieName,
    media: null,

    init() {
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.media.addEventListener('change', () => {
            if (this.theme === 'system') {
                this.apply();
            }
        });
    },

    set(value) {
        this.theme = value;
        this.persist(value);
        this.apply();
    },

    apply() {
        const dark = this.media ? this.media.matches : false;
        const resolved = this.theme === 'system' ? (dark ? 'dark' : 'light') : this.theme;

        document.documentElement.setAttribute('data-theme', resolved);
    },

    persist(value) {
        try {
            localStorage.setItem(this.cookieName, value);
        } catch (error) {
            // Приватный режим может запрещать localStorage: cookie остаётся.
        }

        document.cookie = this.cookieName + '=' + value + ';path=/;max-age=31536000;SameSite=Lax';
    },
}));

function focusableIn(panel) {
    return Array.from(
        panel.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ).filter((element) => element.offsetParent !== null);
}

function focusFirst(panel) {
    if (!panel) {
        return;
    }

    const target = panel.querySelector('[data-autofocus]') || focusableIn(panel)[0] || panel;

    if (target instanceof HTMLElement) {
        target.focus();
    }
}

function trapFocus(panel, event) {
    if (!panel) {
        return;
    }

    const focusable = focusableIn(panel);

    if (focusable.length === 0) {
        event.preventDefault();
        panel.focus();

        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

/*
| Модальное окно (FR-UI-173): Escape, ловушка фокуса, возврат фокуса.
| Открытие и закрытие — событиями open-modal/close-modal с именем окна.
*/
Alpine.data('shellDialog', (name, initiallyOpen = false) => ({
    open: initiallyOpen === true,
    name,
    previousFocus: null,

    init() {
        this.$watch('open', (value) => {
            if (value) {
                this.previousFocus = document.activeElement;
                this.$nextTick(() => focusFirst(this.$refs.panel));
            } else if (this.previousFocus instanceof HTMLElement) {
                this.previousFocus.focus();
                this.previousFocus = null;
            }
        });

        if (this.open) {
            this.$nextTick(() => focusFirst(this.$refs.panel));
        }
    },

    show() {
        this.open = true;
    },

    close() {
        if (!this.open) {
            return;
        }

        this.open = false;
        this.$dispatch('dialog-closed', { name: this.name });
    },

    confirm() {
        this.$dispatch('dialog-confirmed', { name: this.name });
        this.close();
    },

    trap(event) {
        trapFocus(this.$refs.panel, event);
    },
}));

/*
| Drawer мобильной навигации (FR-UI-163): модальный слой, Escape,
| закрытие по подложке, возврат фокуса на кнопку открытия.
*/
Alpine.data('shellDrawer', (name) => ({
    open: false,
    name,
    previousFocus: null,

    show() {
        this.previousFocus = document.activeElement;
        this.open = true;
        this.$nextTick(() => focusFirst(this.$refs.panel));
    },

    close() {
        if (!this.open) {
            return;
        }

        this.open = false;

        if (this.previousFocus instanceof HTMLElement) {
            this.previousFocus.focus();
        }

        this.previousFocus = null;
    },

    trap(event) {
        trapFocus(this.$refs.panel, event);
    },
}));

/*
|--------------------------------------------------------------------------
| Поле адреса с подсказками (FR-UI-121)
|--------------------------------------------------------------------------
|
| Локальное поведение <x-app.address-autocomplete>: открытие и закрытие
| панели подсказок, Escape, стрелки вверх и вниз, Home и End, Enter
| выбирает выделенную подсказку, уход фокуса закрывает список. Подсказки —
| настоящие кнопки, поэтому Tab к ним и выбор мышью работают без этого
| кода. Бизнес-логики нет: поиск и выбор адреса делает Livewire.
|
*/

function addressAutocompleteOptions(root) {
    const list = root.querySelector('[role="listbox"]');

    return list ? Array.from(list.querySelectorAll('[role="option"]')) : [];
}

Alpine.data('addressAutocomplete', (initiallyOpen = false) => ({
    expanded: initiallyOpen === true,
    dismissed: false,
    activeIndex: -1,
    // Идентификатор активной подсказки для aria-activedescendant: отдельное
    // реактивное свойство, чтобы привязка в Blade обновляла DOM.
    activeOptionId: null,
    refocusOnEmpty: false,
    observer: null,

    init() {
        // Подсказки приходят от Livewire: панель следует за фактическим DOM.
        this.observer = new MutationObserver(() => this.sync());
        this.observer.observe(this.$el, { childList: true, subtree: true });
        this.sync();
    },

    destroy() {
        if (this.observer) {
            this.observer.disconnect();
        }
    },

    options() {
        return addressAutocompleteOptions(this.$el);
    },

    input() {
        return this.$refs.input instanceof HTMLElement ? this.$refs.input : null;
    },

    sync() {
        const count = this.options().length;

        if (count === 0) {
            this.activeIndex = -1;
        } else if (this.activeIndex > count - 1) {
            this.activeIndex = count - 1;
        }

        this.expanded = count > 0 && !this.dismissed;
        this.syncActiveOptionId();

        if (this.refocusOnEmpty && count === 0) {
            this.refocusOnEmpty = false;
            this.$nextTick(() => this.focusInput());
        }
    },

    // Держит aria-activedescendant в актуальном состоянии: null, когда
    // активной подсказки нет или список закрыт.
    syncActiveOptionId() {
        const option = this.activeIndex >= 0 && !this.dismissed
            ? this.options()[this.activeIndex]
            : null;

        this.activeOptionId = option ? option.id : null;
    },

    undismiss() {
        if (!this.dismissed) {
            return;
        }

        this.dismissed = false;
        this.sync();
    },

    dismiss() {
        this.dismissed = true;
        this.activeIndex = -1;
        this.activeOptionId = null;
        this.expanded = false;
        this.focusInput();
    },

    focusInput() {
        const input = this.input();

        if (input) {
            input.focus();
        }
    },

    onInput() {
        this.dismissed = false;
        this.activeIndex = -1;
        this.activeOptionId = null;
        this.sync();
    },

    move(step) {
        const count = this.options().length;

        if (count === 0) {
            return;
        }

        this.dismissed = false;
        this.activeIndex = this.activeIndex < 0
            ? (step > 0 ? 0 : count - 1)
            : (this.activeIndex + step + count) % count;
        this.expanded = true;
        this.syncActiveOptionId();
        this.focusActiveOption();
    },

    moveTo(index) {
        if (this.options().length === 0) {
            return;
        }

        this.dismissed = false;
        this.activeIndex = index;
        this.expanded = true;
        this.syncActiveOptionId();
        this.focusActiveOption();
    },

    focusActiveOption() {
        const input = this.input();

        // Пока фокус в поле ввода, выделение передаётся через aria-activedescendant.
        if (!input || document.activeElement === input) {
            return;
        }

        const option = this.options()[this.activeIndex];

        if (option) {
            option.focus();
        }
    },

    choose() {
        const option = this.options()[this.activeIndex];

        if (!option) {
            return false;
        }

        this.refocusOnEmpty = true;
        option.click();

        return true;
    },

    onEnter(event) {
        const target = event.target instanceof HTMLElement ? event.target : null;

        if (!target) {
            return;
        }

        // Enter на самой подсказке нажимает кнопку штатно — второй клик не нужен.
        if (target.closest('[role="option"]')) {
            this.refocusOnEmpty = true;

            return;
        }

        // Выбор стрелками работает только из поля ввода: кнопка очистки и прочие
        // элементы внутри компонента продолжают работать штатно.
        if (target !== this.input()) {
            return;
        }

        if (this.choose()) {
            event.preventDefault();
        }
    },

    onKeydown(event) {
        if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.move(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.move(-1);
        } else if (event.key === 'Home') {
            if (this.options().length > 0) {
                event.preventDefault();
                this.moveTo(0);
            }
        } else if (event.key === 'End') {
            if (this.options().length > 0) {
                event.preventDefault();
                this.moveTo(this.options().length - 1);
            }
        } else if (event.key === 'Enter') {
            this.onEnter(event);
        } else if (event.key === 'Escape' && (this.expanded || this.activeIndex >= 0)) {
            event.preventDefault();
            this.dismiss();
        }
    },

    onFocusOut(event) {
        const next = event.relatedTarget;

        // Переход фокуса внутрь компонента (к подсказке, кнопке очистки) список не закрывает.
        if (next instanceof HTMLElement && this.$el.contains(next)) {
            return;
        }

        this.dismissed = true;
        this.activeIndex = -1;
        this.expanded = false;
    },
}));

/*
|--------------------------------------------------------------------------
| Вкладки (FR-UI-178)
|--------------------------------------------------------------------------
|
| Локальное поведение <x-app.tabs>: выбор вкладки, переключение панелей,
| перемещение по вкладкам стрелками, Home и End. Бизнес-правил нет.
|
*/

function activateTab(tab) {
    const root = tab.closest('[data-tabs]');

    if (!root) {
        return;
    }

    root.querySelectorAll('[role="tab"]').forEach((item) => {
        const selected = item === tab;
        item.setAttribute('aria-selected', selected ? 'true' : 'false');
        item.tabIndex = selected ? 0 : -1;

        const panel = document.getElementById(item.getAttribute('aria-controls'));

        if (panel) {
            panel.hidden = !selected;
        }
    });

    tab.focus();
}

function initTabs(root) {
    const tabs = Array.from(root.querySelectorAll('[role="tab"]'));
    const selected = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') ?? tabs[0];

    if (selected) {
        activateTab(selected);
    }
}

document.querySelectorAll('[data-tabs]').forEach(initTabs);

document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-tabs] [role="tab"]');

    if (tab) {
        activateTab(tab);
    }
});

document.addEventListener('keydown', (event) => {
    const tab = event.target.closest('[data-tabs] [role="tab"]');

    if (!tab) {
        return;
    }

    const list = tab.closest('[role="tablist"]');
    const tabs = list ? Array.from(list.querySelectorAll('[role="tab"]')) : [];
    const index = tabs.indexOf(tab);
    let next = null;

    if (event.key === 'ArrowRight') {
        next = tabs[(index + 1) % tabs.length];
    } else if (event.key === 'ArrowLeft') {
        next = tabs[(index - 1 + tabs.length) % tabs.length];
    } else if (event.key === 'Home') {
        next = tabs[0];
    } else if (event.key === 'End') {
        next = tabs[tabs.length - 1];
    }

    if (next) {
        event.preventDefault();
        activateTab(next);
    }
});

/*
|--------------------------------------------------------------------------
| Запуск Livewire и Alpine (FR-UI-142)
|--------------------------------------------------------------------------
|
| Livewire стартует тот же экземпляр Alpine, что описан выше: второй
| экземпляр не создаётся, директивы компонентов регистрируются один раз.
| Директива @livewireScriptConfig в layout отключает автоподключение скрипта.
|
*/

Livewire.start();
