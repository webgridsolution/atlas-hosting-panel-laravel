/* ==========================================================================
   Atlas panel — file manager
   --------------------------------------------------------------------------
   Vanilla JS, no build step, no framework. Everything here is progressive:
   with JavaScript off the screen still browses, still opens the editor, and
   every card form still posts. What it adds is the part that makes the manager
   feel like a desktop app — a selection that carries across actions, one
   dialog at a time, and a drop target.
   ========================================================================== */

(function () {
    'use strict';

    var table = document.querySelector('[data-fm-table]');
    var selbar = document.querySelector('[data-fm-selbar]');
    var allBox = document.querySelector('[data-fm-all]');
    var deleteInputs = document.getElementById('fm-delete-inputs');
    var drop = document.querySelector('[data-fm-drop]');

    /* ---- dialogs ------------------------------------------------------- */

    function openDialog(id) {
        var dlg = document.getElementById(id);
        if (!dlg) return;

        dlg.setAttribute('data-open', 'true');

        // Focus the first field, which is what a dialog is for.
        var first = dlg.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
        if (first) {
            window.setTimeout(function () { first.focus(); }, 60);
        }
    }

    function closeDialogs() {
        document.querySelectorAll('.dlg[data-open="true"]').forEach(function (dlg) {
            dlg.removeAttribute('data-open');
        });
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-fm-open]');
        var closer = event.target.closest('[data-dlg-close]');

        if (closer) {
            event.preventDefault();
            closeDialogs();
            return;
        }

        if (opener) {
            // A toolbar button that needs a selection must not open on nothing.
            if (opener.hasAttribute('data-fm-needs-selection') && selected().length === 0) {
                event.preventDefault();
                flash('Select something first.');
                return;
            }

            event.preventDefault();
            openDialog('dlg-' + opener.getAttribute('data-fm-open'));
            fillSelectionForms();
            return;
        }

        // A click on the backdrop closes; a click inside the card does not.
        if (event.target.classList.contains('dlg')) {
            closeDialogs();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeDialogs();
    });

    function flash(message) {
        var box = document.createElement('div');
        box.className = 'alert';
        box.style.cssText = 'position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:90;box-shadow:var(--shadow)';
        box.textContent = message;
        document.body.appendChild(box);
        window.setTimeout(function () { box.remove(); }, 2600);
    }

    /* ---- selection ----------------------------------------------------- */

    function boxes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-fm-check]'));
    }

    function selected() {
        return boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    }

    function paintSelection() {
        var paths = selected();

        boxes().forEach(function (box) {
            var row = box.closest('[data-fm-row]');
            if (row) {
                row.setAttribute('data-selected', box.checked ? 'true' : 'false');
            }
        });

        if (selbar) {
            selbar.setAttribute('data-shown', paths.length > 0 ? 'true' : 'false');
            var count = selbar.querySelector('[data-fm-count]');
            if (count) {
                count.textContent = paths.length + ' selected';
            }
        }

        if (allBox) {
            allBox.checked = paths.length > 0 && paths.length === boxes().length;
            allBox.indeterminate = paths.length > 0 && paths.length < boxes().length;
        }

        // The delete form carries the selection as paths[].
        if (deleteInputs) {
            deleteInputs.innerHTML = '';
            paths.forEach(function (path) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'paths[]';
                input.value = path;
                deleteInputs.appendChild(input);
            });
        }
    }

    if (table) {
        table.addEventListener('change', function (event) {
            if (event.target.matches('[data-fm-check]')) {
                paintSelection();
            }

            if (event.target === allBox) {
                boxes().forEach(function (box) { box.checked = allBox.checked; });
                paintSelection();
            }
        });
    }

    document.querySelectorAll('[data-fm-clear]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            boxes().forEach(function (box) { box.checked = false; });
            paintSelection();
        });
    });

    /**
     * Copy, move, chmod and compress all take paths[]. Fill them at open time so
     * one dialog serves both "the ticked rows" and "the row I right-clicked".
     */
    function fillSelectionForms() {
        var paths = selected();
        if (paths.length === 0) paths = pendingSelection.slice();
        if (paths.length === 0) return;

        document.querySelectorAll('[data-fm-selection-form]').forEach(function (form) {
            form.querySelectorAll('input[data-fm-sel]').forEach(function (n) { n.remove(); });

            paths.forEach(function (path) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'paths[]';
                input.value = path;
                input.setAttribute('data-fm-sel', '1');
                form.appendChild(input);
            });
        });
    }

    /* ---- the per-row menu ---------------------------------------------- */

    var pendingSelection = [];

    document.querySelectorAll('[data-fm-menu]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var path = btn.getAttribute('data-fm-menu');
            var name = btn.getAttribute('data-fm-name');
            var isDir = btn.getAttribute('data-fm-dir') === '1';

            pendingSelection = [path];

            // Seed the rename and extract dialogs with this row.
            var renamePath = document.querySelector('[data-fm-rename-path]');
            if (renamePath) renamePath.textContent = path;

            var from = document.querySelector('#dlg-rename [data-fm-from]');
            if (from) from.value = path;

            var rn = document.getElementById('rn-name');
            if (rn) rn.value = name;

            var extractFrom = document.querySelector('#dlg-extract [data-fm-from]');
            if (extractFrom) extractFrom.value = path;

            var extractName = document.querySelector('[data-fm-extract-name]');
            if (extractName) extractName.textContent = path;

            // Extract only makes sense on an archive.
            var extractBtn = document.querySelector('[data-fm-act="extract"]');
            if (extractBtn) {
                var archive = /\.(zip|tar\.gz|tgz|tar|gz)$/i.test(name);
                extractBtn.style.display = !isDir && archive ? '' : 'none';
            }

            openDialog('dlg-menu');
        });
    });

    document.querySelectorAll('[data-fm-act]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var act = btn.getAttribute('data-fm-act');

            if (act === 'rename') {
                closeDialogs();
                openDialog('dlg-rename');
                return;
            }

            if (act === 'delete') {
                closeDialogs();

                var form = document.getElementById('fm-delete');
                if (form && pendingSelection.length > 0) {
                    deleteInputs.innerHTML = '';
                    pendingSelection.forEach(function (path) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'paths[]';
                        input.value = path;
                        deleteInputs.appendChild(input);
                    });
                    form.submit();
                }
                return;
            }

            closeDialogs();

            if (act === 'extract') {
                openDialog('dlg-extract');
                return;
            }

            var dlg = document.getElementById('dlg-' + act);
            if (dlg) {
                dlg.setAttribute('data-open', 'true');
                fillSelectionForms();
            }
        });
    });

    /* ---- drag and drop ------------------------------------------------- */

    var dragDepth = 0;

    if (drop) {
        ['dragenter', 'dragover'].forEach(function (name) {
            window.addEventListener(name, function (event) {
                if (!event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') === -1) {
                    return;
                }

                event.preventDefault();

                if (name === 'dragenter') dragDepth++;

                drop.setAttribute('data-shown', 'true');
            });
        });

        window.addEventListener('dragleave', function () {
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) drop.removeAttribute('data-shown');
        });

        window.addEventListener('drop', function (event) {
            event.preventDefault();
            dragDepth = 0;
            drop.removeAttribute('data-shown');

            if (!event.dataTransfer || !event.dataTransfer.files.length) return;

            var input = document.getElementById('up-files');
            if (!input) return;

            // Hand the dropped files to the real file input, then open the
            // dialog so the customer can see what is about to be sent.
            input.files = event.dataTransfer.files;
            openDialog('dlg-upload');
        });
    }

    /* ---- sortable columns keep the selection label honest -------------- */

    paintSelection();
})();
