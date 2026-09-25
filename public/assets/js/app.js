(() => {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    // --- Thème -------------------------------------------------------------
    const currentTheme = () => {
        const forced = document.documentElement.getAttribute('data-theme');
        if (forced) return forced;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };
    const syncThemeButtons = () => $$('[data-theme-toggle]').forEach((b) => b.setAttribute('data-current', currentTheme()));
    syncThemeButtons();
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        if (mq.addEventListener) mq.addEventListener('change', syncThemeButtons);
    }

    // --- Délégation des clics ---------------------------------------------
    const app = $('[data-app]');

    document.addEventListener('click', (e) => {
        const t = e.target;

        if (t.closest('[data-theme-toggle]')) {
            const next = currentTheme() === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (err) { /* stockage indisponible */ }
            syncThemeButtons();
            return;
        }

        if (t.closest('[data-nav-toggle]')) { app && app.classList.toggle('nav-open'); return; }
        if (t.closest('[data-nav-close]')) { app && app.classList.remove('nav-open'); return; }

        const toastClose = t.closest('[data-toast-close]');
        if (toastClose) { dismissToast(toastClose.closest('[data-toast]')); return; }

        const opener = t.closest('[data-open-dialog]');
        if (opener) {
            const dlg = document.getElementById(opener.dataset.openDialog);
            if (!dlg) return;
            if (opener.dataset.formAction) {
                const form = $('form', dlg);
                if (form) form.action = opener.dataset.formAction;
            }
            openDialog(dlg);
            return;
        }

        const closer = t.closest('[data-close-dialog]');
        if (closer) {
            e.preventDefault();
            const dlg = closer.closest('dialog');
            if (dlg) dlg.close();
            return;
        }

        const row = t.closest('tr[data-href]');
        if (row && !t.closest('a, button, input, select, textarea, form, label')) {
            window.location.href = row.dataset.href;
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && app) app.classList.remove('nav-open');
    });

    // --- Toasts ------------------------------------------------------------
    function dismissToast(toast) {
        if (!toast || toast.classList.contains('leaving')) return;
        toast.classList.add('leaving');
        setTimeout(() => toast.remove(), 260);
    }
    $$('[data-toast]').forEach((toast, i) => {
        const isError = toast.classList.contains('toast-error');
        setTimeout(() => dismissToast(toast), (isError ? 9000 : 5000) + i * 400);
    });

    // --- Boîtes de dialogue ------------------------------------------------
    function openDialog(dlg) {
        if (typeof dlg.showModal !== 'function' || dlg.open) return;
        dlg.showModal();
        const focusable = $('input:not([type=hidden]):not([type=file]), select, textarea', dlg);
        if (focusable) setTimeout(() => focusable.focus(), 30);
    }
    $$('dialog.modal').forEach((dlg) => {
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    });

    // --- Confirmation des actions sensibles --------------------------------
    const confirmDlg = $('#confirm-dialog');
    let pendingForm = null;

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form.matches('[data-confirm]') || form.dataset.confirmed === '1') return;

        e.preventDefault();
        if (!confirmDlg || typeof confirmDlg.showModal !== 'function') {
            if (window.confirm(form.dataset.confirm)) { form.dataset.confirmed = '1'; form.submit(); }
            return;
        }

        pendingForm = form;
        const danger = form.dataset.confirmVariant === 'danger';
        $('[data-confirm-title]', confirmDlg).textContent = form.dataset.confirmTitle || 'Confirmer l’action';
        $('[data-confirm-message]', confirmDlg).textContent = form.dataset.confirm;
        $('.confirm-icon', confirmDlg).classList.toggle('danger', danger);
        const ok = $('[data-confirm-ok]', confirmDlg);
        ok.textContent = form.dataset.confirmLabel || 'Confirmer';
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        confirmDlg.showModal();
        setTimeout(() => ok.focus(), 30);
    }, true);

    if (confirmDlg) {
        $('[data-confirm-ok]', confirmDlg).addEventListener('click', () => {
            if (!pendingForm) return;
            const form = pendingForm;
            pendingForm = null;
            form.dataset.confirmed = '1';
            confirmDlg.close();
            form.submit();
        });
        confirmDlg.addEventListener('close', () => { pendingForm = null; });
    }

    // Évite les doubles soumissions
    document.addEventListener('submit', (e) => {
        if (e.defaultPrevented) return;
        const btn = e.submitter || $('button[type=submit], button:not([type])', e.target);
        if (btn) setTimeout(() => { btn.disabled = true; }, 0);
    });

    // --- Calcul automatique du prix TTC ------------------------------------
    $$('[data-autocalc]').forEach((form) => {
        const qty = $('[name=quantite]', form);
        const pu = $('[name=prix_unitaire]', form);
        const ttc = $('[name=prix_ttc]', form);
        if (!qty || !pu || !ttc) return;
        const parse = (v) => parseFloat(String(v).replace(',', '.')) || 0;
        const recompute = () => {
            if (ttc.dataset.touched === '1') return;
            ttc.value = (parse(qty.value) * parse(pu.value)).toFixed(2);
        };
        qty.addEventListener('input', recompute);
        pu.addEventListener('input', recompute);
        ttc.addEventListener('input', () => { ttc.dataset.touched = ttc.value === '' ? '' : '1'; });
    });

    // --- Zones de dépôt de fichiers ----------------------------------------
    const formatSize = (b) => (b < 1024 ? b + ' o' : b < 1048576 ? Math.round(b / 1024) + ' Ko' : (b / 1048576).toFixed(1).replace('.', ',') + ' Mo');

    const ACCEPTED = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    // Ajoute des fichiers à ceux déjà sélectionnés (au lieu de les remplacer).
    const addFiles = (zone, files) => {
        const input = $('input[type=file]', zone);
        if (!input || typeof DataTransfer === 'undefined') return 0;
        const dt = new DataTransfer();
        Array.from(input.files).forEach((f) => dt.items.add(f));
        let added = 0;
        Array.from(files).forEach((f) => {
            if (ACCEPTED.includes(f.type)) { dt.items.add(f); added++; }
        });
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
        return added;
    };

    const removeFileAt = (zone, index) => {
        const input = $('input[type=file]', zone);
        const dt = new DataTransfer();
        Array.from(input.files).forEach((f, i) => { if (i !== index) dt.items.add(f); });
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
    };

    $$('.dropzone').forEach((zone) => {
        const input = $('input[type=file]', zone);
        const list = $('.dz-files', zone);
        if (!input) return;
        let previews = [];

        const render = () => {
            if (!list) return;
            previews.forEach((url) => URL.revokeObjectURL(url));
            previews = [];
            list.textContent = '';
            Array.from(input.files).forEach((f, i) => {
                const chip = document.createElement('span');
                chip.className = 'file-chip';
                if (f.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    const url = URL.createObjectURL(f);
                    previews.push(url);
                    img.src = url;
                    img.alt = '';
                    chip.appendChild(img);
                }
                const label = document.createElement('span');
                label.className = 'file-chip-name';
                label.textContent = f.name;
                chip.appendChild(label);
                const size = document.createElement('small');
                size.textContent = formatSize(f.size);
                chip.appendChild(size);
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'btn-icon btn-icon-sm danger';
                del.setAttribute('aria-label', 'Retirer ' + f.name);
                del.innerHTML = '<svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
                del.addEventListener('click', (e) => { e.stopPropagation(); removeFileAt(zone, i); });
                chip.appendChild(del);
                list.appendChild(chip);
            });
        };

        // Sélecteur séparé : « parcourir » ajoute aux fichiers existants au lieu de les remplacer.
        const picker = document.createElement('input');
        picker.type = 'file';
        picker.multiple = true;
        picker.accept = input.accept;
        picker.addEventListener('change', () => { addFiles(zone, picker.files); picker.value = ''; });

        zone.addEventListener('click', (e) => {
            if (!e.target.closest('.dz-files')) picker.click();
        });
        zone.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); picker.click(); }
        });
        ['dragenter', 'dragover'].forEach((evt) => zone.addEventListener(evt, (e) => {
            e.preventDefault();
            zone.classList.add('dragover');
        }));
        zone.addEventListener('dragleave', (e) => {
            if (!zone.contains(e.relatedTarget)) zone.classList.remove('dragover');
        });
        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            zone.classList.remove('dragover');
            if (e.dataTransfer && e.dataTransfer.files.length) addFiles(zone, e.dataTransfer.files);
        });
        input.addEventListener('change', render);

        const dlg = zone.closest('dialog');
        if (dlg) dlg.addEventListener('close', () => { input.value = ''; render(); });
    });

    // --- Coller une capture d'écran (Win+Maj+S puis Ctrl+V) ----------------
    const pad = (n) => String(n).padStart(2, '0');
    const captureName = (file, i) => {
        const d = new Date();
        const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        const stamp = `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
        return `capture-${stamp}${i ? '-' + (i + 1) : ''}.${ext}`;
    };

    document.addEventListener('paste', (e) => {
        const items = e.clipboardData ? Array.from(e.clipboardData.items) : [];
        const images = items
            .filter((it) => it.kind === 'file' && ACCEPTED.includes(it.type))
            .map((it) => it.getAsFile())
            .filter(Boolean);
        if (!images.length) return;
        // Un copier-coller depuis Word/Excel contient aussi du texte : on le laisse aller dans le champ.
        const inField = e.target.closest && e.target.closest('input, textarea, [contenteditable]');
        if (inField && items.some((it) => it.type === 'text/plain')) return;

        // Cible : la zone de la fenêtre ouverte, sinon on ouvre « Ajouter un article ».
        let dlg = $('dialog.modal[open]');
        if (!dlg || !$('.dropzone', dlg)) {
            const articleDlg = document.getElementById('dlg-article');
            if (!articleDlg || dlg) return;
            openDialog(articleDlg);
            dlg = articleDlg;
        }
        const zone = $('.dropzone', dlg);
        if (!zone) return;

        e.preventDefault();
        const existing = $('input[type=file]', zone).files.length;
        const named = images.map((f, i) => (
            f.name && f.name !== 'image.png' ? f : new File([f], captureName(f, existing + i), { type: f.type, lastModified: Date.now() })
        ));
        if (addFiles(zone, named) > 0) {
            zone.classList.add('pasted');
            setTimeout(() => zone.classList.remove('pasted'), 700);
        }
    });

    // Empêche le navigateur d'ouvrir un fichier lâché hors d'une zone de dépôt
    ['dragover', 'drop'].forEach((evt) => window.addEventListener(evt, (e) => {
        if (!e.target.closest || !e.target.closest('.dropzone')) e.preventDefault();
    }));

    // --- Formulaires de filtre soumis automatiquement (listes serveur) -----
    $$('form[data-autosubmit]').forEach((form) => {
        $$('select', form).forEach((s) => s.addEventListener('change', () => form.submit()));
    });

    // --- Sélection multiple et actions groupées ----------------------------
    const bulkForm = $('#bulk-form');
    const bulkTable = $('[data-bulk-table]');
    if (bulkForm && bulkTable) {
        const items = $$('[data-bulk-item]', bulkTable);
        const all = $('[data-bulk-all]', bulkTable);
        const count = $('[data-bulk-count]', bulkForm);
        const action = $('[data-bulk-action]', bulkForm);

        const refresh = () => {
            const checked = items.filter((i) => i.checked);
            const n = checked.length;
            // Les cases sont dans le tableau : leurs identifiants sont recopiés dans le formulaire.
            $$('input[name="ids[]"]', bulkForm).forEach((el) => el.remove());
            checked.forEach((i) => {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'ids[]';
                hidden.value = i.value;
                bulkForm.appendChild(hidden);
            });
            bulkForm.hidden = n === 0;
            count.textContent = n + ' sélectionné' + (n > 1 ? 's' : '');
            if (all) {
                all.checked = n > 0 && n === items.length;
                all.indeterminate = n > 0 && n < items.length;
            }
            items.forEach((i) => i.closest('tr').classList.toggle('is-selected', i.checked));
        };
        const syncExtra = () => {
            $$('[data-bulk-for]', bulkForm).forEach((el) => {
                const show = el.dataset.bulkFor.split(' ').includes(action.value);
                el.hidden = !show;
                el.disabled = !show;
            });
        };

        items.forEach((i) => i.addEventListener('change', refresh));
        if (all) all.addEventListener('change', () => { items.forEach((i) => { i.checked = all.checked; }); refresh(); });
        action.addEventListener('change', syncExtra);
        $('[data-bulk-clear]', bulkForm).addEventListener('click', () => { items.forEach((i) => { i.checked = false; }); refresh(); });
        syncExtra();
        refresh();
    }

    // --- Fenêtre « Modifier un utilisateur » -------------------------------
    const userDlg = $('#dlg-user');
    if (userDlg) {
        const form = $('[data-user-form]', userDlg);
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-edit-user]');
            if (!btn) return;
            const u = JSON.parse(btn.dataset.editUser);
            form.action = '/admin/utilisateurs/' + u.id + '/roles';
            $('[data-user-name]', userDlg).textContent = u.name;
            $('[data-user-email]', userDlg).textContent = u.email;
            const avatar = $('[data-user-avatar]', userDlg);
            let hash = 0;
            for (const ch of u.name) hash = (hash * 31 + ch.charCodeAt(0)) % 360;
            avatar.style.background = `linear-gradient(135deg,hsl(${hash} 72% 62%),hsl(${(hash + 38) % 360} 70% 50%))`;
            avatar.textContent = u.name.split(/[\s.\-]+/).filter(Boolean).map((p) => p[0]).join('').slice(0, 2).toUpperCase();
            $$('input[name="roles[]"]', form).forEach((cb) => { cb.checked = u.roles.includes(cb.value); });
            form.elements.service_id.value = String(u.service || 0);
            form.elements.is_admin.checked = u.admin;
            form.elements.is_active.checked = u.active;
            form.elements.is_admin.disabled = u.self;
            form.elements.is_active.disabled = u.self;
            $('[data-user-self]', userDlg).hidden = !u.self;
            openDialog(userDlg);
        });
    }

    // --- Recherche et filtres ----------------------------------------------
    $$('[data-filter-scope]').forEach((scope) => {
        const input = $('[data-filter-input]', scope);
        const chips = $$('[data-filter-chip]', scope);
        const items = $$('[data-filter-item]', scope);
        const empty = $('[data-filter-empty]', scope);
        const fields = $$('[data-filter-field]', scope);
        const counter = $('[data-filter-count]', scope);
        const DEST_SEP = ' › ';
        let active = 'all';

        const matchField = (item, field) => {
            const wanted = field.value;
            if (!wanted) return true;
            if (field.dataset.filterField === 'service') return item.dataset.service === wanted;
            if (field.dataset.filterField === 'destination') {
                // Un parent (pôle, bâtiment…) inclut tous ses sous-lieux.
                return (item.dataset.destinations || '').split('|').some((d) => d === wanted || d.startsWith(wanted + DEST_SEP));
            }
            return true;
        };

        const apply = () => {
            const q = input ? input.value.trim().toLowerCase() : '';
            let shown = 0;
            items.forEach((item) => {
                const text = (item.getAttribute('data-filter-text') || item.textContent).toLowerCase();
                const status = item.getAttribute('data-status');
                const matchText = !q || text.includes(q);
                const matchChip = active === 'all' || (status && active.split(',').includes(status));
                const matchFields = fields.every((f) => matchField(item, f));
                item.hidden = !(matchText && matchChip && matchFields);
                if (!item.hidden) shown++;
            });
            if (counter) {
                counter.textContent = shown === items.length ? '' : shown + ' / ' + items.length + ' affichée' + (shown > 1 ? 's' : '');
            }
            // Arborescences : un élément visible garde ses parents visibles.
            items.forEach((item) => {
                if (item.hidden) return;
                let parent = item.parentElement && item.parentElement.closest('[data-filter-item]');
                while (parent) {
                    parent.hidden = false;
                    parent = parent.parentElement && parent.parentElement.closest('[data-filter-item]');
                }
            });
            if (empty) empty.hidden = shown !== 0;
        };

        if (input) input.addEventListener('input', apply);
        fields.forEach((f) => f.addEventListener('change', apply));
        chips.forEach((chip) => chip.addEventListener('click', () => {
            chips.forEach((c) => c.classList.remove('active'));
            chip.classList.add('active');
            active = chip.getAttribute('data-filter-chip');
            apply();
        }));

        // --- Tri (liste déroulante + en-têtes de colonnes cliquables) ---
        const sortSelect = $('[data-sort-select]', scope);
        const headers = $$('[data-sort-key]', scope);
        const tbody = $('tbody', scope);
        if (!tbody || (!sortSelect && !headers.length)) return;

        const collator = new Intl.Collator('fr', { numeric: true, sensitivity: 'base' });
        const sortBy = (key, dir) => {
            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const va = a.dataset[key] || '';
                const vb = b.dataset[key] || '';
                // Les valeurs vides (sans service, sans destination) vont toujours en bas.
                if (!va !== !vb) return va ? -1 : 1;
                const cmp = key === 'total' ? parseFloat(va) - parseFloat(vb) : collator.compare(va, vb);
                return dir === 'asc' ? cmp : -cmp;
            });
            rows.forEach((r) => tbody.appendChild(r));
            headers.forEach((h) => {
                const on = h.dataset.sortKey === key;
                h.classList.toggle('is-sorted', on);
                h.classList.toggle('desc', on && dir === 'desc');
            });
            if (sortSelect) {
                const value = key + ':' + dir;
                if (Array.from(sortSelect.options).some((o) => o.value === value)) sortSelect.value = value;
            }
        };

        if (sortSelect) sortSelect.addEventListener('change', () => {
            const [key, dir] = sortSelect.value.split(':');
            sortBy(key, dir);
        });
        headers.forEach((h) => h.addEventListener('click', () => {
            const key = h.dataset.sortKey;
            const dir = h.classList.contains('is-sorted') && !h.classList.contains('desc') ? 'desc'
                : h.classList.contains('is-sorted') ? 'asc'
                : (key === 'date' || key === 'total' ? 'desc' : 'asc');
            sortBy(key, dir);
        }));
        if (sortSelect) {
            const [key, dir] = sortSelect.value.split(':');
            sortBy(key, dir);
        }
    });
})();
