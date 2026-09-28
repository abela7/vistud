/*
| Selection helper for Alpine.js (DESIGN.md §5).
| Manages select mode, per-item and range selection (Shift+click),
| select all / clear, and keyboard navigation (Escape, Ctrl/Cmd+A).
| Works with in-app navigation without leaking listeners.
*/
import { onPage } from './page.js';

export function selectable() {
    return {
        isSelecting: false,
        selected: {},
        lastToggled: null,

        init() {
            this.$watch('isSelecting', (val) => {
                if (!val) {
                    this.clearSelection();
                }
            });

            document.addEventListener('livewire:navigating', () => {
                this.exitMode();
            }, { once: true });
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
            const checkboxes = root.querySelectorAll('[data-select-key]');
            return Array.from(checkboxes).map((el) => el.dataset.selectKey);
        },

        get count() {
            const visible = new Set(this.visibleKeys());
            return Object.keys(this.selected).filter((k) => this.selected[k] && visible.has(k)).length;
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
        },

        clearSelection() {
            this.selected = {};
            this.lastToggled = null;
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
        },

        handleRowClick(event, key) {
            if (!this.isSelecting) return;
            if (event.target.closest('button, input, select, textarea, [data-no-select], dialog')) {
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
