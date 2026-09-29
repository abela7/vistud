/*
| Selection helper for Alpine.js (DESIGN.md §5).
| Manages select mode, per-item and range selection (Shift+click),
| select all / clear, and keyboard navigation (Escape, Ctrl/Cmd+A).
| Works with in-app navigation without leaking listeners.
*/
import { onPage } from './page.js';

export function selectable(extra = {}) {
    const base = {
        isSelecting: false,
        selected: {},
        lastToggled: null,
        count: 0,

        init() {
            this.$watch('isSelecting', (val) => {
                // The pinned notes' button steps aside for the selection bar (resources/css/shell.css).
                document.documentElement.toggleAttribute('data-selecting', val);
                if (!val) {
                    this.clearSelection();
                }
            });

            this.$watch('selected', () => {
                this.updateCount();
            });

            document.addEventListener('livewire:navigating', () => {
                this.exitMode();
            }, { once: true });
        },

        updateCount() {
            const visible = new Set(this.visibleKeys());
            this.count = Object.keys(this.selected).filter((k) => this.selected[k] && visible.has(k)).length;
        },

        toggleMode() {
            this.isSelecting = !this.isSelecting;
            if (!this.isSelecting) {
                this.clearSelection();
            }
        },

        exitMode() {
            this.isSelecting = false;
            this.clearSelection();
        },

        visibleKeys() {
            const root = this.$root || document;
            const items = root.querySelectorAll('[data-select-key]');
            const unique = [];
            const seen = new Set();
            for (const el of items) {
                const key = el.dataset.selectKey;
                if (key && !seen.has(key)) {
                    seen.add(key);
                    unique.push(key);
                }
            }
            return unique;
        },

        isSelected(key) {
            return !!this.selected[key];
        },

        allSelected() {
            const keys = this.visibleKeys();
            return keys.length > 0 && keys.every((k) => this.selected[k]);
        },

        someSelected() {
            const keys = this.visibleKeys();
            return keys.some((k) => this.selected[k]);
        },

        toggleAll() {
            if (this.allSelected()) {
                this.clearSelection();
            } else {
                this.selectAll();
            }
        },

        selectAll() {
            const next = { ...this.selected };
            this.visibleKeys().forEach((k) => {
                next[k] = true;
            });
            this.selected = next;
            this.updateCount();
        },

        clearSelection() {
            this.selected = {};
            this.lastToggled = null;
            this.updateCount();
        },

        toggle(key, isRange = false) {
            const keys = this.visibleKeys();
            if (isRange && this.lastToggled && keys.includes(this.lastToggled)) {
                const startIdx = keys.indexOf(this.lastToggled);
                const endIdx = keys.indexOf(key);
                const [min, max] = [Math.min(startIdx, endIdx), Math.max(startIdx, endIdx)];
                const targetState = !this.selected[key];
                const next = { ...this.selected };
                for (let i = min; i <= max; i++) {
                    next[keys[i]] = targetState;
                }
                this.selected = next;
            } else {
                this.selected = {
                    ...this.selected,
                    [key]: !this.selected[key],
                };
            }
            this.lastToggled = key;
            this.updateCount();
        },

        handleRowClick(event, key) {
            if (!this.isSelecting) return;
            if (event.target.closest('button, input, select, textarea, [data-no-select], dialog, .selection-check')) {
                return;
            }
            event.preventDefault();
            this.toggle(key, event.shiftKey);
        },

        handleKeydown(event) {
            if (!this.isSelecting) return;
            if (event.key === 'Escape') {
                this.exitMode();
                return;
            }
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a') {
                const tag = document.activeElement?.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA') return;
                event.preventDefault();
                this.selectAll();
            }
        },

        selectedKeys() {
            const visible = new Set(this.visibleKeys());
            return Object.keys(this.selected).filter((k) => this.selected[k] && visible.has(k));
        },

        selectedTypes() {
            const counts = {};
            this.selectedKeys().forEach((k) => {
                const [type] = k.split(':');
                counts[type] = (counts[type] || 0) + 1;
            });
            return counts;
        },
    };

    return Object.defineProperties(base, Object.getOwnPropertyDescriptors(extra));
}

const register = () => {
    if (window.Alpine) {
        window.Alpine.data('selectable', selectable);
    }
};

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
