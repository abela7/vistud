/*
| Back links (resources/views/components/back.blade.php). When the page before
| this one is where the link goes, the browser's own Back is used instead, so
| that page comes back as it was left: scrolled to the same place.
*/

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-back]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    let previous = null;
    try {
        previous = document.referrer ? new URL(document.referrer) : null;
    } catch {
        previous = null;
    }
    const target = new URL(link.href, window.location.href);
    if (previous && previous.origin === target.origin && previous.pathname === target.pathname && previous.search === target.search && window.history.length > 1) {
        event.preventDefault();
        window.history.back();
    }
});
