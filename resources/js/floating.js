/*
| Where a floating thing goes (the owner's review, 2026-09-29: the workspace switcher's menu was cut off by the
| collapsed sidebar). A menu, or the bar over a selected picture, is shown in the browser's top layer (a
| popover), so no sidebar, list or panel can clip it and nothing sits on top of it. It is placed beside its
| button (or picture), and always inside the window: below the button, or above it where there is more room,
| on the button's end edge, its start edge or its middle, and scrolling inside itself when even that is not
| enough. The editor's toolbar menus (resources/js/note/editor.js) place themselves the same way.
*/

/** Pixels between a button and its menu. */
const GAP = 4;

/** Pixels a floating thing keeps clear of the window's edges. */
const MARGIN = 8;

/** The least height worth showing; a menu shorter than its list scrolls. */
const LEAST = 96;

/**
 * Places `panel`, already shown, beside `anchor` (an element, or a rectangle).
 *
 * @param {'start'|'end'|'center'} options.align which edge of the anchor the panel lines up with, or its middle
 * @param {'below'|'above'} options.prefer the side to open on when both have room
 * @param {number} options.insetTop the top of the space it may use, for a bar that stays put at the top of the window
 */
export function placeBeside(anchor, panel, { align = 'end', prefer = 'below', insetTop = 0 } = {}) {
    const box = anchor instanceof Element ? anchor.getBoundingClientRect() : anchor;
    const width = document.documentElement.clientWidth;
    const height = window.innerHeight;
    const floor = Math.max(MARGIN, insetTop + MARGIN);

    // Its own size first, from a known spot and without a limit of ours: its stylesheet's limits still apply.
    panel.style.maxHeight = '';
    panel.style.maxWidth = `${width - 2 * MARGIN}px`;
    panel.style.top = '0px';
    panel.style.left = '0px';
    const size = panel.getBoundingClientRect();

    const below = height - box.bottom - GAP - MARGIN;
    const above = box.top - GAP - floor;
    const opensBelow = prefer === 'above'
        ? size.height > above && below > above
        : size.height <= below || below >= above;
    const room = Math.max(opensBelow ? below : above, LEAST);
    if (size.height > room) panel.style.maxHeight = `${room}px`;
    const shown = Math.min(size.height, room);

    let top = opensBelow ? box.bottom + GAP : box.top - GAP - shown;
    top = Math.min(Math.max(top, floor), Math.max(floor, height - shown - MARGIN));
    let left = { start: box.left, end: box.right - size.width, center: box.left + (box.width - size.width) / 2 }[align];
    left = Math.min(Math.max(left, MARGIN), Math.max(MARGIN, width - size.width - MARGIN));

    panel.style.top = `${top}px`;
    panel.style.left = `${left}px`;
}

/** A menu: placed beside its button (see placeBeside). */
export function placeMenu(anchor, panel, align = 'end') {
    placeBeside(anchor, panel, { align });
}

/** Forgets where a panel was placed, for the next time it opens. */
export function clearPlacement(panel) {
    for (const property of ['top', 'left', 'maxHeight', 'maxWidth']) panel.style[property] = '';
}
