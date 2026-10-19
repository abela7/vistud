/*
| Records one class in the browser (resources/views/livewire/workspaces/class-recorder.blade.php).
| Pause keeps the same file. Pieces go up in order, and a piece that fails is sent again
| a few times before the take is stopped. What arrived stays on the server either way, and
| the page offers to keep it. Finish waits until the last piece is kept.
| The screen is kept awake while recording, and leaving the page asks first.
| The player uses one clock, even when an older class was kept in more than one take.
*/

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');

const clock = (seconds) => {
    const whole = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor((whole % 3600) / 60);
    const rest = whole % 60;
    if (hours > 0) return `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
    return `${minutes}:${String(rest).padStart(2, '0')}`;
};

// 10:00 is ten minutes, 1:02:03 is an hour, two minutes and three seconds. Anything else is null.
const seconds = (text) => {
    const match = /^(\d+):(\d{2})(?::(\d{2}))?$/.exec(String(text ?? '').trim());
    if (!match) return null;
    const long = match[3] !== undefined;
    return long ? Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3]) : Number(match[1]) * 60 + Number(match[2]);
};

const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

const mimeType = () => ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type)) || '';

const LEAVE = 'You are still recording this class. Leave and the recording stops. What was recorded so far is kept, and you can keep it when you come back.';

const register = () => {
    window.Alpine.data('classRecorder', () => ({
        canRecord: typeof MediaRecorder !== 'undefined' && Boolean(navigator.mediaDevices?.getUserMedia),
        canPause: typeof MediaRecorder !== 'undefined' && typeof MediaRecorder.prototype.pause === 'function',
        recording: false,
        paused: false,
        error: '',
        clock: '0:00',
        partId: '',
        stream: null,
        recorder: null,
        sending: Promise.resolve(),
        failed: false,
        startedAt: 0,
        pausedFor: 0,
        pausedAt: 0,
        timer: 0,
        wake: null,
        guard: null,
        leave: null,
        // Class time at the start of this take: what earlier takes of the class already hold.
        base: 0,
        // A note being written while the class is recorded: where the recording was when the student tapped Note.
        noting: false,
        noteFrom: 0,
        noteText: '',
        noteBusy: false,
        // The notes kept so far in this recording, shown on the recording screen.
        kept: [],
        // The recorder hidden, only the notes showing (so the screen is quiet in a room).
        compact: false,

        init() {
            this.base = Number(this.$root.dataset.offset || 0);
            this.guard = (event) => {
                if (!this.recording) return;
                event.preventDefault();
                event.returnValue = LEAVE;
            };
            this.leave = (event) => {
                if (this.recording && !window.confirm(LEAVE)) event.preventDefault();
            };
            this.visible = () => {
                if (document.visibilityState === 'visible' && this.recording && !this.paused) this.keepAwake();
            };
            window.addEventListener('beforeunload', this.guard);
            document.addEventListener('livewire:navigate', this.leave);
            document.addEventListener('visibilitychange', this.visible);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.guard);
            document.removeEventListener('livewire:navigate', this.leave);
            document.removeEventListener('visibilitychange', this.visible);
            window.clearInterval(this.timer);
            if (this.recorder) {
                this.recorder.ondataavailable = null;
                this.recorder.onstop = null;
                if (this.recorder.state !== 'inactive') this.recorder.stop();
            }
            this.release();
            this.letSleep();
        },

        elapsed() {
            const held = this.paused ? Date.now() - this.pausedAt : 0;
            return (Date.now() - this.startedAt - this.pausedFor - held) / 1000;
        },

        async record() {
            this.error = '';
            if (!this.canRecord) return;
            const id = await this.$wire.startPart();
            if (!id) return;
            this.partId = id;
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch {
                this.error = 'The microphone could not be used. Allow it in your browser, then try again.';
                return;
            }
            const type = mimeType();
            this.recorder = new MediaRecorder(this.stream, type ? { mimeType: type } : undefined);
            this.failed = false;
            this.sending = Promise.resolve();
            let index = 1;
            this.recorder.ondataavailable = (event) => {
                if (!event.data || event.data.size === 0 || this.failed) return;
                const piece = index;
                index += 1;
                const reached = Math.round(this.elapsed());
                this.sending = this.sending.then(() => this.send(piece, event.data, this.recorder.mimeType || type || 'audio/webm', reached));
            };
            this.recorder.onstop = () => this.stopped();
            this.startedAt = Date.now();
            this.pausedFor = 0;
            this.pausedAt = 0;
            this.paused = false;
            this.clock = '0:00';
            this.recording = true;
            this.compact = false;
            this.timer = window.setInterval(() => {
                this.clock = clock(this.elapsed());
            }, 250);
            this.recorder.start(5000);
            this.keepAwake();
        },

        pause() {
            if (!this.recorder || this.recorder.state !== 'recording') return;
            this.recorder.pause();
            this.pausedAt = Date.now();
            this.paused = true;
            this.clock = clock(this.elapsed());
            window.clearInterval(this.timer);
            this.letSleep();
        },

        resume() {
            if (!this.recorder || this.recorder.state !== 'paused') return;
            this.pausedFor += Date.now() - this.pausedAt;
            this.paused = false;
            this.recorder.resume();
            this.timer = window.setInterval(() => {
                this.clock = clock(this.elapsed());
            }, 250);
            this.keepAwake();
        },

        // Seconds as a clock: 4:07, or 1:02:09 past the hour.
        stamp(total) {
            const hours = Math.floor(total / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const seconds = total % 60;

            return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}` : `${minutes}:${String(seconds).padStart(2, '0')}`;
        },

        // Class time now, in seconds: the take's own clock on top of the earlier takes.
        now() {
            return Math.max(0, Math.round(this.base + this.elapsed()));
        },

        // Note: the moment it is tapped is the start of what the note is about; the recording keeps going.
        startNote() {
            if (!this.recording) return;
            this.noteFrom = this.now();
            this.noteText = '';
            this.noting = true;
            this.$nextTick(() => this.$refs.note?.focus());
        },

        cancelNote() {
            this.noting = false;
            this.noteText = '';
        },

        // Save note: the moment it is saved is the end. It is kept at once, against the recording's own minutes.
        async saveNote() {
            if (!this.noting || this.noteBusy) return;
            const text = this.noteText.trim();
            if (text === '') {
                this.cancelNote();
                return;
            }
            this.noteBusy = true;
            try {
                const until = Math.max(this.now(), this.noteFrom + 1);
                await this.$wire.addLiveNote(this.noteFrom, until, text);
                this.kept.push({ id: `${this.noteFrom}-${this.kept.length}`, from: this.noteFrom, until, text });
                this.noting = false;
                this.noteText = '';
            } finally {
                this.noteBusy = false;
            }
        },

        async stop() {
            // A note still open is kept, ending where the class ends.
            if (this.noting) await this.saveNote();
            if (this.recorder && this.recorder.state !== 'inactive') this.recorder.stop();
        },

        async stopped() {
            window.clearInterval(this.timer);
            const length = Math.max(0, Math.round(this.elapsed()));
            this.base += length;
            this.paused = false;
            this.release();
            this.letSleep();
            try {
                await this.sending;
            } catch {
                this.failed = true;
            }
            this.recording = false;
            if (this.failed) {
                this.error = 'The connection dropped and a piece did not send. Everything before it is kept: use "Keep what was recorded" below.';
                this.$wire.$refresh();
                return;
            }
            await this.$wire.finishPart(this.partId, length);
        },

        // Three tries, a little apart, so a bad moment on the network does not cost the class.
        async send(index, blob, mime, reached) {
            if (this.failed) return;
            const post = () => {
                const body = new FormData();
                body.append('index', String(index));
                body.append('mime', mime);
                body.append('elapsed', String(reached));
                body.append('chunk', blob, 'chunk');
                return fetch(`${this.$root.dataset.chunkBase}/${this.partId}/chunks`, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                });
            };
            for (const pause of [0, 1000, 4000]) {
                if (pause > 0) await wait(pause);
                try {
                    const response = await post();
                    if (response.ok) return;
                    // The server said no for a reason a second try will not change.
                    if (response.status >= 400 && response.status < 500 && response.status !== 419 && response.status !== 429) break;
                } catch {
                    // Offline for a moment: try again.
                }
            }
            this.failed = true;
            if (this.recorder && this.recorder.state !== 'inactive') this.recorder.stop();
            throw new Error('chunk');
        },

        async keepAwake() {
            if (!('wakeLock' in navigator) || this.wake) return;
            try {
                this.wake = await navigator.wakeLock.request('screen');
                this.wake.addEventListener('release', () => {
                    this.wake = null;
                });
            } catch {
                this.wake = null;
            }
        },

        letSleep() {
            this.wake?.release().catch(() => {});
            this.wake = null;
        },

        release() {
            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;
        },
    }));

    window.Alpine.data('classPlayer', (parts) => ({
        parts: Array.isArray(parts) ? parts : [],
        index: 0,
        clock: '0:00',
        rate: 1,

        get current() {
            return this.parts[this.index]?.src ?? '';
        },

        before(index) {
            return this.parts.slice(0, index).reduce((sum, part) => sum + Number(part.seconds || 0), 0);
        },

        tick() {
            this.clock = clock(this.before(this.index) + (this.$refs.audio?.currentTime || 0));
        },

        ended() {
            if (this.index + 1 >= this.parts.length) return;
            this.go(this.index + 1, 0, true);
        },

        // Moves to a place on the class clock, in whichever take holds it.
        seek(at) {
            const target = typeof at === 'number' ? at : seconds(at);
            if (target === null) return;
            let take = 0;
            while (take + 1 < this.parts.length && target >= this.before(take + 1)) take += 1;
            this.go(take, Math.max(0, target - this.before(take)), true);
        },

        go(take, time, play) {
            const audio = this.$refs.audio;
            if (!audio) return;
            const apply = () => {
                audio.playbackRate = this.rate;
                audio.currentTime = time;
                if (play) audio.play().catch(() => {});
                this.tick();
            };
            if (take === this.index) {
                apply();
                return;
            }
            this.index = take;
            this.$nextTick(() => {
                audio.load();
                audio.addEventListener('loadedmetadata', apply, { once: true });
            });
        },

        skip(by) {
            this.seek(Math.max(0, this.before(this.index) + (this.$refs.audio?.currentTime || 0) + by));
        },

        speed(value) {
            this.rate = value;
            if (this.$refs.audio) this.$refs.audio.playbackRate = value;
        },

        use(field) {
            this.tick();
            this.$wire.set(field, this.clock);
        },
    }));
};

if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
