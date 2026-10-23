/*
| The UI skill's blocks once they are on the page (App\Engine\ChatBlocks draws them): a choice's button sends its
| words as the student's message, and a copy block's button copies its text. One listener on the chat, so blocks
| that stream in later work too; a step's tick is the browser's own checkbox and is kept nowhere.
*/

/** The text on the clipboard, by the page's means or the old command when it has none. */
async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        let done = false;
        try {
            done = document.execCommand('copy');
        } catch {}
        area.remove();

        return done;
    }
}

/**
 * Wires the blocks inside `root`, once. `choose(words)` is called with a tapped choice's words.
 *
 * @param {HTMLElement | null} root
 * @param {{ choose: (words: string) => void }} handlers
 */
export function hydrateBlocks(root, { choose }) {
    if (!root || root.dataset.blocksReady) return;
    root.dataset.blocksReady = 'true';
    root.addEventListener('click', async (event) => {
        const choice = event.target.closest('[data-chat-choice]');
        if (choice) {
            event.preventDefault();
            choose(choice.dataset.chatChoice ?? choice.textContent.trim());

            return;
        }
        const copy = event.target.closest('[data-chat-copy]');
        if (!copy) return;
        event.preventDefault();
        const text = copy.closest('.chat-copy')?.querySelector('.chat-copy-text')?.textContent ?? '';
        const done = await copyText(text);
        const label = copy.querySelector('span') ?? copy;
        label.textContent = done ? 'Copied!' : 'Press Ctrl+C';
        copy.classList.toggle('is-copied', done);
        setTimeout(() => {
            label.textContent = 'Copy';
            copy.classList.remove('is-copied');
        }, 1600);
    });
}
