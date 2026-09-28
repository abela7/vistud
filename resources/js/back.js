/*
| Back links (resources/views/components/back.blade.php). When the page before
| this one is where the link goes, the browser's own Back is used instead, so
| that page comes back as it was left: scrolled to the same place. Otherwise
| the page above opens in place (resources/js/page.js leaves these links to
| this script).
|
| Pages swapped in without reloading keep the first page's document.referrer,
| so the page before is kept here as each new one arrives.
*/

let previous = document.referrer;
let current = window.location.href;

document.addEventListener('livewire:navigated', () => {
    if (window.location.href === current) return;
    previous = current;
    current = window.location.href;
});

function samePage(a, b) {
    return a.origin === b.origin && a.pathname === b.pathname && a.search === b.search;
}

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-back]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    let before = null;
    try {
        before = previous ? new URL(previous) : null;
    } catch {
        before = null;
    }
    const target = new URL(link.href, window.location.href);
    if (before && samePage(before, target) && window.history.length > 1) {
        event.preventDefault();
        window.history.back();
    } else if (window.Livewire?.navigate) {
        event.preventDefault();
        window.Livewire.navigate(target.href);
    }
});
