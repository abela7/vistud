/*
| Voice to text for a chat box (the browser's Web Speech API). What is said is handed over as it is said: the
| phrases the browser is sure of, and the one it is still hearing. Browsers stop listening after a pause (a phone
| after nearly every phrase), so it starts again by itself until the student stops it, or until it has heard
| nothing three times running.
*/

/** Whether this browser can turn speech into text. */
export const speechSupported = () => typeof window !== 'undefined' && Boolean(window.SpeechRecognition || window.webkitSpeechRecognition);

/** What was there and what was just said, with one space between them. */
export function joinSpeech(before, said) {
    const left = (before ?? '').replace(/[ \t]+$/, '');
    const right = (said ?? '').trim();
    if (left === '' || right === '') return left || right;
    return /\s$/.test(left) ? left + right : `${left} ${right}`;
}

/**
 * Starts listening. onWords(sure, stillHearing) on every result: `sure` is new text to keep, `stillHearing` the
 * phrase so far (it replaces the last one). onHearing(true|false) when a voice starts or stops. onStop(reason)
 * once, if it stops by itself: 'denied' (no microphone), 'network', 'silence' or 'failed'.
 *
 * @param {{ lang: string, onWords: (sure: string, stillHearing: string) => void, onHearing?: (on: boolean) => void, onStop?: (reason: string) => void }} options
 * @returns {() => void} Stops listening, without calling onStop.
 */
export function listen({ lang, onWords, onHearing, onStop }) {
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let wanted = true;
    let current = null;
    let quietRuns = 0;
    let stillHearing = '';

    const end = (reason) => {
        if (!wanted) return;
        wanted = false;
        onHearing?.(false);
        onStop?.(reason);
    };

    const start = () => {
        const recognition = new Recognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;
        recognition.lang = lang;

        recognition.onspeechstart = () => onHearing?.(true);
        recognition.onspeechend = () => onHearing?.(false);
        recognition.onresult = (event) => {
            quietRuns = 0;
            let sure = '';
            let hearing = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                const words = event.results[i][0]?.transcript ?? '';
                if (event.results[i].isFinal) sure += words;
                else hearing += words;
            }
            stillHearing = hearing.trim();
            onWords(sure.trim(), stillHearing);
        };
        recognition.onerror = (event) => {
            if (['not-allowed', 'service-not-allowed', 'audio-capture'].includes(event.error)) end('denied');
            else if (event.error === 'network') end('network');
            // 'no-speech' and 'aborted' end this run; onend starts the next.
        };
        recognition.onend = () => {
            current = null;
            onHearing?.(false);
            // A phrase the browser never settled is kept as it was last heard.
            if (stillHearing !== '') {
                onWords(stillHearing, '');
                stillHearing = '';
            }
            if (!wanted) return;
            if (++quietRuns > 3) {
                end('silence');
                return;
            }
            setTimeout(() => {
                if (wanted && current === null) start();
            }, 250);
        };

        current = recognition;
        try {
            recognition.start();
        } catch {
            current = null;
            end('failed');
        }
    };

    start();

    return () => {
        wanted = false;
        const recognition = current;
        current = null;
        if (!recognition) return;
        // Nothing more is taken from it: what was shown is what the student keeps.
        recognition.onresult = recognition.onend = recognition.onerror = recognition.onspeechstart = recognition.onspeechend = null;
        try {
            recognition.abort();
        } catch {}
    };
}
