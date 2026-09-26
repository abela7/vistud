/*
| The invitation page (resources/views/auth/accept-invitation.blade.php).
| The link is /invitation#<token>: browsers never send the part after "#",
| so the token stays out of every server log (PM decision Q3). This moves
| it into the form, takes it out of the address bar, and keeps it for this
| tab only, so the form still has it when a mistake in a field sends the
| page back.
*/

const KEY = 'vistud.invitation';

function stored() {
    try {
        return sessionStorage.getItem(KEY) ?? '';
    } catch {
        return '';
    }
}

function store(token) {
    try {
        if (token) sessionStorage.setItem(KEY, token);
        else sessionStorage.removeItem(KEY);
    } catch {
        // Storage blocked: the token only lives in the form.
    }
}

const form = document.querySelector('[data-invitation-form]');

if (form) {
    const fromLink = decodeURIComponent(window.location.hash.slice(1)).trim();
    if (fromLink) {
        store(fromLink);
        history.replaceState(history.state, '', window.location.pathname + window.location.search);
    }

    const token = fromLink || stored();
    form.querySelector('[name="token"]').value = token;
    try {
        form.querySelector('[name="timezone"]').value = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
    } catch {
        // No time zone: the account uses UTC until the person changes it.
    }

    if (!token) {
        document.querySelector('[data-invitation-form-panel]').hidden = true;
        document.querySelector('[data-invitation-invalid]').hidden = false;
    }
} else {
    // Anywhere else, including the "link doesn't work" answer, the token is spent or useless.
    store('');
}
