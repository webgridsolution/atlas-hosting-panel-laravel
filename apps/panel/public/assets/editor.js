/* ==========================================================================
   Atlas panel — the file editor
   --------------------------------------------------------------------------
   A textarea with the three things that make editing a config file bearable:
   line numbers, Tab that indents instead of leaving the field, and Ctrl/Cmd+S.
   Everything here is an enhancement — with JavaScript off the form still
   posts and saves.
   ========================================================================== */

(function () {
    'use strict';

    var textarea = document.getElementById('content');
    var gutter = document.querySelector('[data-editor-gutter]');
    var counter = document.querySelector('[data-editor-lines]');
    var dirty = document.querySelector('[data-editor-dirty]');
    var form = document.getElementById('fm-editor-form');

    if (!textarea) return;

    var saved = textarea.value;
    var readOnly = textarea.hasAttribute('readonly');

    /* ---- line numbers --------------------------------------------------- */

    function renumber() {
        var lines = textarea.value.split('\n').length;

        // A trailing newline creates an empty last line; numbering it is right,
        // because that is where the cursor can be.
        gutter.textContent = '';

        var frag = document.createDocumentFragment();
        for (var i = 1; i <= lines; i++) {
            frag.appendChild(document.createTextNode(i));
            if (i < lines) frag.appendChild(document.createTextNode('\n'));
        }
        gutter.appendChild(frag);

        if (counter) {
            counter.textContent = lines + (lines === 1 ? ' line' : ' lines');
        }
    }

    renumber();

    /* ---- Tab indents, Shift+Tab outdents -------------------------------- */

    textarea.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab') return;

        event.preventDefault();

        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var value = textarea.value;

        // A multi-line selection indents as a block, which is what a block
        // selection means.
        var multiline = value.slice(start, end).indexOf('\n') !== -1;

        if (!multiline && !event.shiftKey) {
            textarea.value = value.slice(0, start) + '    ' + value.slice(end);
            textarea.selectionStart = textarea.selectionEnd = start + 4;
            return;
        }

        var lineStart = value.lastIndexOf('\n', start - 1) + 1;
        var lineEnd = value.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = value.length;

        var block = value.slice(lineStart, lineEnd);
        var changed = event.shiftKey
            ? block.replace(/^ {1,4}/gm, '')
            : block.replace(/^/gm, '    ');

        textarea.value = value.slice(0, lineStart) + changed + value.slice(lineEnd);

        // Keep a selection over the block rather than collapsing to a caret.
        textarea.selectionStart = lineStart;
        textarea.selectionEnd = lineStart + changed.length;
    });

    /* ---- Ctrl/Cmd+S saves ---------------------------------------------- */

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            if (!readOnly && form) form.requestSubmit();
        }
    });

    /* ---- dirty state ---------------------------------------------------- */

    function markDirty() {
        if (!dirty) return;

        if (textarea.value === saved) {
            dirty.textContent = '';
            return;
        }

        dirty.textContent = 'unsaved changes';
        dirty.style.color = 'var(--warn)';
    }

    textarea.addEventListener('input', function () {
        renumber();
        markDirty();
    });

    textarea.addEventListener('scroll', function () {
        gutter.scrollTop = textarea.scrollTop;
    });

    // Leaving with unsaved changes is almost always a mistake, so it asks.
    window.addEventListener('beforeunload', function (event) {
        if (readOnly || textarea.value === saved) return;

        event.preventDefault();
        event.returnValue = '';
    });

    // The form submit itself is a save, so it must not prompt.
    if (form) {
        form.addEventListener('submit', function () {
            saved = textarea.value;
        });
    }
})();
