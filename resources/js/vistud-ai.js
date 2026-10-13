/*
| ViStud AI's page (resources/views/livewire/study/vistud-ai-page.blade.php): full screen, and the conversations.
|
| Full screen hides the app's top bar and sidebar (the same switch a study session uses, data-session-fullscreen on
| <html>) and gives the window to the chat. The conversations sit beside the chat from a laptop up, where they can be
| folded away; below that they slide in over the chat and go when one is picked. Both choices are remembered.
*/

const WIDE = '(min-width: 64rem)';
const FULLSCREEN = 'vistud-ai-fullscreen';
const LIST = 'vistud-ai-list';

const stored = (key) => {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
};

const store = (key, value) => {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Storage unavailable: the choice lasts for this page.
    }
};

export function vistudAi() {
    const wide = window.matchMedia(WIDE);

    return {
        fullscreen: stored(FULLSCREEN) === '1',
        wide: wide.matches,
        listOpen: wide.matches ? stored(LIST) !== '0' : false,

        init() {
            const root = document.documentElement;
            root.toggleAttribute('data-session-fullscreen', this.fullscreen);
            this.$watch('fullscreen', (on) => {
                root.toggleAttribute('data-session-fullscreen', on);
                store(FULLSCREEN, on ? '1' : '0');
            });
            this.$watch('listOpen', (open) => {
                if (this.wide) store(LIST, open ? '1' : '0');
            });
            this.onWidth = (event) => {
                this.wide = event.matches;
                this.listOpen = event.matches ? stored(LIST) !== '0' : false;
            };
            wide.addEventListener('change', this.onWidth);
        },

        destroy() {
            wide.removeEventListener('change', this.onWidth);
            document.documentElement.removeAttribute('data-session-fullscreen');
        },

        /** A conversation was picked, or a new one started: on a narrow screen the list makes way for the chat. */
        picked() {
            if (!this.wide) this.listOpen = false;
        },

        /** Escape closes the sliding list first, then leaves full screen (not while writing in the box). */
        escape(event) {
            if (!this.wide && this.listOpen) {
                this.listOpen = false;
                return;
            }
            if (this.fullscreen && !event.target.closest('textarea, input, [contenteditable="true"]')) {
                this.fullscreen = false;
            }
        },
    };
}

const register = () => window.Alpine?.data('vistudAi', vistudAi);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
