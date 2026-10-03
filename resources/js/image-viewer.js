/*
| The picture viewer on a file's page (the owner's review, 2026-10-04: a tall photo was cut off, with no way to see
| the rest or to make it bigger). The picture is shown whole, fitted to its frame, whatever its shape; from there
| it can be made bigger or smaller (buttons, + and -, Ctrl and the wheel, a pinch), shown at the frame's width or at
| its real size (1:1), turned a quarter at a time, and moved by dragging, a finger or the arrow keys. A double click
| goes between fitted and bigger. It follows the frame when that changes (a window resized, notes opened beside
| it). Nothing is sent anywhere: the browser draws the picture it already has, so a large one costs nothing extra.
| Alpine.data('imageViewer'), used by resources/views/workspaces/file.blade.php.
*/

/** The most a picture is made bigger, as a multiple of its real size. */
const MAX_SCALE = 8;

/** Each press of + or − (and a notch of the wheel with Ctrl) changes the size by this much. */
const STEP = 1.25;

/** Room left around a fitted picture, in pixels. */
const MARGIN = 16;

export function imageViewer() {
    // What a hand is doing right now isn't shown, so it stays out of what Alpine watches.
    const pointers = new Map();
    let pinch = null;
    let drag = null;
    let observer = null;

    return {
        ready: false,
        failed: false,
        natural: { w: 0, h: 0 },
        stage: { w: 0, h: 0 },
        /** How many quarter turns clockwise: 0, 90, 180 or 270. */
        turned: 0,
        /** fit: all of it in the frame. width: as wide as the frame. actual: its real size. custom: zoomed by hand. */
        mode: 'fit',
        scale: 1,
        x: 0,
        y: 0,
        dragging: false,
        moved: false,

        init() {
            observer = new ResizeObserver(() => this.measure());
            observer.observe(this.$refs.stage);
            this.measure();
            // A picture the browser already had is loaded before this runs.
            if (this.$refs.picture.complete && this.$refs.picture.naturalWidth > 0) this.loaded();
        },

        destroy() {
            observer?.disconnect();
        },

        loaded() {
            const picture = this.$refs.picture;
            this.natural = { w: picture.naturalWidth, h: picture.naturalHeight };
            this.ready = true;
            this.failed = false;
            this.measure();
            this.setMode('fit');
        },

        measure() {
            const stage = this.$refs.stage;
            this.stage = { w: stage.clientWidth, h: stage.clientHeight };
            if (this.ready) this.refit();
        },

        // ---------- What the picture measures now ----------

        /** Its width and height as they are shown (swapped when it is turned on its side). */
        get shownW() {
            return this.turned % 180 === 0 ? this.natural.w : this.natural.h;
        },

        get shownH() {
            return this.turned % 180 === 0 ? this.natural.h : this.natural.w;
        },

        get percent() {
            return Math.round(this.scale * 100);
        },

        get dimensions() {
            return this.natural.w > 0 ? `${this.natural.w} × ${this.natural.h}` : '';
        },

        /** Bigger than the frame in some direction, so it can be moved. */
        get pannable() {
            return this.shownW * this.scale > this.stage.w + 1 || this.shownH * this.scale > this.stage.h + 1;
        },

        get transform() {
            return `translate(-50%, -50%) translate(${this.x}px, ${this.y}px) rotate(${this.turned}deg) scale(${this.scale})`;
        },

        /** The size that shows all of it, never bigger than its real size (a small picture isn't blown up). */
        fitScale() {
            if (this.shownW === 0 || this.stage.w === 0) return 1;

            return Math.min(1, (this.stage.w - 2 * MARGIN) / this.shownW, (this.stage.h - 2 * MARGIN) / this.shownH);
        },

        limit(scale) {
            return Math.min(MAX_SCALE, Math.max(Math.min(0.05, this.fitScale()), scale));
        },

        // ---------- Sizes ----------

        setMode(mode) {
            if (!this.ready) return;
            this.mode = mode;
            this.x = 0;
            this.y = 0;
            if (mode === 'fit') this.scale = this.fitScale();
            if (mode === 'width') this.scale = this.limit(this.stage.w / this.shownW);
            if (mode === 'actual') this.scale = 1;
            // A tall picture at its width, or at its real size, starts at its top: the way it is read.
            if (mode !== 'fit') this.y = Math.max(0, (this.shownH * this.scale - this.stage.h) / 2);
            this.clamp();
        },

        /** After the frame or the turn changed: a fitted picture stays fitted, one zoomed by hand stays where it is. */
        refit() {
            if (this.mode === 'custom') {
                this.scale = this.limit(this.scale);
                this.clamp();
            } else {
                this.setMode(this.mode);
            }
        },

        /** Sets the size, keeping the point at (cx, cy) from the frame's middle where it is. */
        zoomTo(scale, cx = 0, cy = 0) {
            if (!this.ready) return;
            const next = this.limit(scale);
            const ratio = next / this.scale;
            this.x = cx - (cx - this.x) * ratio;
            this.y = cy - (cy - this.y) * ratio;
            this.scale = next;
            this.mode = 'custom';
            this.clamp();
        },

        zoomBy(factor, cx = 0, cy = 0) {
            this.zoomTo(this.scale * factor, cx, cy);
        },

        zoomIn() {
            this.zoomBy(STEP);
        },

        zoomOut() {
            this.zoomBy(1 / STEP);
        },

        turn(quarters = 1) {
            this.turned = (this.turned + 90 * quarters + 360) % 360;
            this.refit();
        },

        /** The picture can't be moved out of the frame: past its edges there is only the frame. */
        clamp() {
            const reachX = Math.max(0, (this.shownW * this.scale - this.stage.w) / 2);
            const reachY = Math.max(0, (this.shownH * this.scale - this.stage.h) / 2);
            this.x = Math.min(reachX, Math.max(-reachX, this.x));
            this.y = Math.min(reachY, Math.max(-reachY, this.y));
        },

        /** Where a pointer is, from the frame's middle. */
        from(event) {
            const box = this.$refs.stage.getBoundingClientRect();

            return { x: event.clientX - box.left - this.stage.w / 2, y: event.clientY - box.top - this.stage.h / 2 };
        },

        // ---------- Hands ----------

        press(event) {
            if (!this.ready || (event.pointerType === 'mouse' && event.button !== 0)) return;
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            this.$refs.stage.setPointerCapture?.(event.pointerId);
            this.moved = false;
            if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y) || 1, scale: this.scale };
                drag = null;
            } else {
                drag = { x: event.clientX, y: event.clientY, fromX: this.x, fromY: this.y };
                this.dragging = true;
            }
        },

        move(event) {
            if (!pointers.has(event.pointerId)) return;
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (pinch && pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                const middle = this.from({ clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2 });
                this.zoomTo(pinch.scale * (Math.hypot(a.x - b.x, a.y - b.y) / pinch.distance), middle.x, middle.y);
                this.moved = true;
            } else if (drag) {
                const [dx, dy] = [event.clientX - drag.x, event.clientY - drag.y];
                if (Math.abs(dx) + Math.abs(dy) > 3) this.moved = true;
                this.x = drag.fromX + dx;
                this.y = drag.fromY + dy;
                this.clamp();
            }
        },

        release(event) {
            pointers.delete(event.pointerId);
            pinch = null;
            drag = null;
            this.dragging = false;
            // One finger left after a pinch carries on as a drag.
            if (pointers.size === 1) {
                const [rest] = [...pointers.values()];
                drag = { x: rest.x, y: rest.y, fromX: this.x, fromY: this.y };
                this.dragging = true;
            }
        },

        /** Ctrl (or a trackpad pinch) and the wheel zoom; the wheel alone moves a picture that is bigger than its frame. */
        wheel(event) {
            if (!this.ready) return;
            if (event.ctrlKey || event.metaKey) {
                event.preventDefault();
                const notch = Math.max(-50, Math.min(50, event.deltaY));
                const point = this.from(event);
                this.zoomBy(Math.exp(-notch * 0.006), point.x, point.y);
            } else if (this.pannable) {
                event.preventDefault();
                this.x -= event.deltaX;
                this.y -= event.deltaY;
                this.clamp();
            }
        },

        /** A double click: from fitted to bigger, where it was pressed; from anything else back to fitted. */
        toggle(event) {
            if (!this.ready || this.moved) return;
            if (this.mode === 'fit') {
                const point = this.from(event);
                this.zoomTo(Math.max(1, this.scale * 2), point.x, point.y);
            } else {
                this.setMode('fit');
            }
        },

        key(event) {
            if (event.altKey || event.ctrlKey || event.metaKey || !this.ready) return;
            const far = event.shiftKey ? 200 : 60;
            const moves = { ArrowLeft: [far, 0], ArrowRight: [-far, 0], ArrowUp: [0, far], ArrowDown: [0, -far] };
            if (event.key in moves) {
                if (!this.pannable) return;
                this.x += moves[event.key][0];
                this.y += moves[event.key][1];
                this.clamp();
            } else if (event.key === '+' || event.key === '=') this.zoomIn();
            else if (event.key === '-' || event.key === '_') this.zoomOut();
            else if (event.key === '0') this.setMode('fit');
            else if (event.key === '1') this.setMode('actual');
            else if (event.key.toLowerCase() === 'w') this.setMode('width');
            else if (event.key.toLowerCase() === 'r') this.turn(event.shiftKey ? -1 : 1);
            else return;
            event.preventDefault();
        },
    };
}

const register = () => window.Alpine?.data('imageViewer', imageViewer);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
