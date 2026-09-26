import './appearance.js';
import './forms.js';
import './shell.js';
import './invitation.js';

// The note editor is only loaded on a note's page.
const noteEditor = document.querySelector('[data-note-editor]');
if (noteEditor) {
    import('./note/editor.js').then(({ mount }) => mount(noteEditor));
}
