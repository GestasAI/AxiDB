/**
 * Notas - notas.js: progressive enhancement vanilla.
 *
 * Subsistema: examples/notas
 * Responsable: anadir UX no-bloqueante (busqueda live, autosave indicator,
 *              shortcuts) sin romper la funcionalidad server-rendered.
 *              Si JS esta desactivado, las paginas siguen funcionando 100%.
 *
 *              No depende de fetch del SDK. Usa el DOM existente y ahorra
 *              roundtrips redundantes al servidor.
 */

(() => {
    'use strict';
    const $  = (s, root = document) => root.querySelector(s);
    const $$ = (s, root = document) => root.querySelectorAll(s);

    // -----------------------------------------------------------------------
    // index.php — busqueda live con debounce de 300 ms
    // -----------------------------------------------------------------------
    const searchForm  = $('form.search');
    const searchInput = searchForm ? $('input[type="search"]', searchForm) : null;
    if (searchForm && searchInput) {
        let timer = null;
        const submit = () => searchForm.submit();
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            // Si vacian el cuadro y habia query, recarga limpia
            if (searchInput.value === '' && new URLSearchParams(location.search).has('q')) {
                location.href = 'index.php';
                return;
            }
            // Solo dispara si la query es >= 2 caracteres (UX: no busca por una letra)
            if (searchInput.value.trim().length >= 2) {
                timer = setTimeout(submit, 300);
            }
        });
        // Atajo "/" para enfocar la barra de busqueda (estilo GitHub).
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && document.activeElement.tagName !== 'INPUT'
                              && document.activeElement.tagName !== 'TEXTAREA') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
    }

    // -----------------------------------------------------------------------
    // index.php — Ctrl+Enter en el form de creacion = submit
    // -----------------------------------------------------------------------
    const createForm = $('section.creator form');
    if (createForm) {
        const ta = $('textarea[name="body"]', createForm);
        ta?.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                createForm.submit();
            }
        });
    }

    // -----------------------------------------------------------------------
    // editor.php — autosave indicator (no auto-save real; solo marca "dirty")
    // y atajo Ctrl+S = guardar.
    // -----------------------------------------------------------------------
    const editorForm = $('article.editor form.form-stacked');
    if (editorForm) {
        let dirty = false;
        const indicator = document.createElement('span');
        indicator.className = 'dirty-indicator';
        indicator.textContent = '';
        const actions = $('.actions', editorForm);
        actions?.appendChild(indicator);

        const markDirty = () => {
            if (!dirty) {
                dirty = true;
                indicator.textContent = '· cambios sin guardar';
                indicator.classList.add('on');
            }
        };
        $$('input, textarea', editorForm).forEach(el => {
            el.addEventListener('input',  markDirty);
            el.addEventListener('change', markDirty);
        });

        // Ctrl+S guarda
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                editorForm.submit();
            }
        });

        // Aviso si intentas salir con cambios sin guardar
        window.addEventListener('beforeunload', (e) => {
            if (dirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Tras Submit con exito (?ok=1 en URL), limpia el dirty
        if (new URLSearchParams(location.search).has('ok')) {
            dirty = false;
            indicator.textContent = '· guardado';
            indicator.classList.add('saved');
            setTimeout(() => indicator.textContent = '', 2000);
        }
    }

    // -----------------------------------------------------------------------
    // Atajos globales: ? muestra una mini-cheatsheet
    // -----------------------------------------------------------------------
    document.addEventListener('keydown', (e) => {
        if (e.key === '?' && e.shiftKey
            && document.activeElement.tagName !== 'INPUT'
            && document.activeElement.tagName !== 'TEXTAREA') {
            e.preventDefault();
            alert('Atajos:\n' +
                  '  /         enfoca barra de busqueda\n' +
                  '  Ctrl+Enter  envia formulario nuevo\n' +
                  '  Ctrl+S    guarda en el editor\n' +
                  '  ?         esta ayuda');
        }
    });
})();
