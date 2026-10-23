/*
| Voice typing that works on any device (App\Engine\Dictation): the microphone records what is said, and when the
| student stops, the recording goes to ViStud, which turns it into words with the model chosen in their AI settings
| and hands them back for the box. Nothing is kept. Phones that can't run the browser's own speech recognition can
| all record.
*/

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');

/** Whether this browser can record from the microphone. */
export const recordingSupported = () => typeof window !== 'undefined' && Boolean(navigator.mediaDevices?.getUserMedia && window.MediaRecorder);

/**
 * Starts recording. Throws when the microphone is refused or missing.
 *
 * @returns {Promise<{ stop: () => Promise<Blob>, cancel: () => void }>}
 */
export async function record() {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'].find((t) => window.MediaRecorder.isTypeSupported?.(t)) ?? '';
    const recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
    const chunks = [];
    const release = () => stream.getTracks().forEach((track) => track.stop());
    recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data);
    };
    recorder.start();

    return {
        stop: () => new Promise((resolve) => {
            recorder.onstop = () => {
                release();
                resolve(new Blob(chunks, { type: recorder.mimeType || type || 'audio/webm' }));
            };
            recorder.stop();
        }),
        cancel: () => {
            recorder.onstop = release;
            try {
                recorder.stop();
            } catch {
                release();
            }
        },
    };
}

/** The file extension a recording's type means, so the server knows what it has. */
const extensionOf = (type) => (type.includes('mp4') ? 'mp4' : type.includes('ogg') ? 'ogg' : type.includes('wav') ? 'wav' : 'webm');

/**
 * The words in a recording, from ViStud. Throws an Error with words for the student when it can't.
 *
 * @returns {Promise<string>}
 */
export async function writeDown(url, blob) {
    const form = new FormData();
    form.append('audio', blob, `speech.${extensionOf(blob.type)}`);
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        });
    } catch {
        throw new Error('ViStud couldn\'t be reached. Check the connection and try again.');
    }
    let body = null;
    try {
        body = await response.json();
    } catch {}
    if (!response.ok) {
        throw new Error(body?.error?.message ?? ({ 413: 'That recording is too long.', 419: 'Your session has ended. Reload the page and try again.', 429: 'Too many at once. Wait a moment, then try again.' }[response.status] ?? 'Your words couldn\'t be written down. Try again.'));
    }

    return String(body?.text ?? '');
}
