/* =========================================================
   UnitSync - Platoon Leader pages (leader/*.php)
   Load with:  <script src=".../assets/js/leader.js" defer></script>

   Modules (each one only runs if its markup is on the page):
   1. Reveal      - staggered entrance for [data-pl-reveal]
   2. Picker      - session picker <details> (close on outside click / Esc)
   3. Sheet       - take-attendance form: counters, filters, search,
                    auto-save, quick actions, submit flow
   4. Roster      - confirmation before removing a cadet

   Uses the global helpers showConfirm() and showToast() when they exist
   and falls back to window.confirm() / silence when they do not.
   ========================================================= */
(function () {
    'use strict';

    /* ---------- tiny helpers ---------- */
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function esc(value) {
        var d = document.createElement('div');
        d.textContent = value == null ? '' : String(value);
        return d.innerHTML;
    }

    function toast(message, type, ms) {
        if (typeof showToast === 'function') { showToast(message, type || 'info', ms); }
    }

    // Wraps the site modal; falls back to the browser dialog.
    function ask(opts) {
        if (typeof showConfirm === 'function') { return showConfirm(opts); }
        var tmp = document.createElement('div');
        tmp.innerHTML = opts.message || '';
        return Promise.resolve(window.confirm((opts.title ? opts.title + '\n\n' : '') + tmp.textContent.trim()));
    }

    /* ---------- 1. Reveal ---------- */
    function initReveal(page) {
        var items = $$('[data-pl-reveal]', page);
        if (!items.length) { return; }
        items.forEach(function (el, i) { el.style.setProperty('--delay', (i * 70) + 'ms'); });
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                items.forEach(function (el) { el.classList.add('is-visible'); });
            });
        });
    }

    /* ---------- 2. Session picker ---------- */
    function initPicker(picker) {
        picker.addEventListener('toggle', function () {
            if (!picker.open) { return; }
            var active = $('.is-active', picker);
            var list = $('.pl-picker-list', picker);
            if (active && list) {
                list.scrollTop = active.offsetTop - list.clientHeight / 2 + active.clientHeight / 2;
            }
        });
        document.addEventListener('click', function (e) {
            if (picker.open && !picker.contains(e.target)) { picker.open = false; }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && picker.open) {
                picker.open = false;
                var s = $('summary', picker);
                if (s) { s.focus(); }
            }
        });
    }

    /* ---------- 3. Attendance sheet ---------- */
    function initSheet(form) {
        var editable  = form.getAttribute('data-editable') === '1';
        var submitted = form.getAttribute('data-submitted') === '1';
        var saveUrl   = form.getAttribute('data-save-url');
        var sessionId = parseInt(form.getAttribute('data-session-id'), 10);
        var label     = form.getAttribute('data-label') || 'this session';
        var csrfInput = $('input[name="csrf_token"]', form);
        var csrf      = csrfInput ? csrfInput.value : '';

        var rows      = $$('.pl-cadet', form);
        var total     = rows.length;
        var progress  = $('[data-progress]', form);
        var leftText  = $('[data-left]', form);
        var noMatch   = $('[data-nomatch]', form);
        var saveBox   = $('[data-save]', form);
        var saveText  = $('[data-save-text]', form);
        var searchBox = $('[data-search]', form);

        var filter = 'all';
        var term = '';
        var counts = { P: 0, A: 0, L: 0, E: 0, marked: 0, unmarked: 0, all: total };

        var timer = null;
        var saving = false;
        var queued = false;
        var dirty = false;
        var changeSeq = 0;
        var leaving = false;
        var warnedSubmitted = false;

        function statusOf(row) {
            var r = $('input[type="radio"]:checked', row);
            return r ? r.value : '';
        }

        /* --- row state --- */
        function syncRow(row) {
            var s = statusOf(row);
            row.setAttribute('data-status', s);
            var late = $('[data-detail="late"]', row);
            var exc = $('[data-detail="excuse"]', row);
            var clr = $('.pl-clear', row);
            if (late) { late.hidden = (s !== 'L'); }
            if (exc) { exc.hidden = (s !== 'E'); }
            if (clr) { clr.hidden = (s === ''); }
        }

        /* --- counters + progress --- */
        function tally() {
            counts.P = counts.A = counts.L = counts.E = 0;
            rows.forEach(function (row) {
                var s = statusOf(row);
                if (s && counts.hasOwnProperty(s)) { counts[s]++; }
            });
            counts.marked = counts.P + counts.A + counts.L + counts.E;
            counts.unmarked = total - counts.marked;

            $$('[data-count]', form).forEach(function (el) {
                var key = el.getAttribute('data-count');
                if (counts.hasOwnProperty(key)) { el.textContent = counts[key]; }
            });

            var pct = total ? Math.round((counts.marked / total) * 100) : 0;
            if (progress) {
                progress.style.setProperty('--pl-pct', pct + '%');
                progress.setAttribute('aria-valuenow', pct);
                progress.classList.toggle('is-done', total > 0 && counts.unmarked === 0);
            }
            if (leftText) {
                leftText.textContent = counts.unmarked > 0
                    ? counts.unmarked + ' left to mark'
                    : 'All cadets marked';
                leftText.classList.toggle('is-warn', counts.unmarked > 0);
            }
        }

        /* --- filter + search --- */
        function applyFilter() {
            var visible = 0;
            rows.forEach(function (row) {
                var s = statusOf(row);
                var okFilter = filter === 'all' || (filter === 'unmarked' ? s === '' : s === filter);
                var okTerm = !term || (row.getAttribute('data-search') || '').indexOf(term) !== -1;
                row.hidden = !(okFilter && okTerm);
                if (!row.hidden) { visible++; }
            });
            if (noMatch) { noMatch.hidden = visible > 0; }
        }

        function setFilter(value) {
            filter = value;
            $$('[data-filter]', form).forEach(function (btn) {
                btn.setAttribute('aria-pressed', btn.getAttribute('data-filter') === value ? 'true' : 'false');
            });
            applyFilter();
        }

        $$('[data-filter]', form).forEach(function (btn) {
            btn.addEventListener('click', function () { setFilter(btn.getAttribute('data-filter')); });
        });

        if (searchBox) {
            searchBox.addEventListener('input', function () {
                term = searchBox.value.trim().toLowerCase();
                applyFilter();
            });
        }

        /* --- auto-save --- */
        function setSave(state, text) {
            if (!saveBox) { return; }
            saveBox.className = 'pl-save ' + state;
            if (saveText) { saveText.textContent = text; }
        }

        function collect() {
            return rows.map(function (row) {
                var min = $('.pl-minutes', row);
                var exc = $('.pl-excuse', row);
                return {
                    cadet_id: parseInt(row.getAttribute('data-cadet-id'), 10),
                    status: statusOf(row),
                    minutes_late: min ? min.value.trim() : null,
                    excuse_reason: exc ? exc.value.trim() : null
                };
            });
        }

        // An Excused cadet without a reason would be rejected by the server.
        function needsReason() {
            return rows.some(function (row) {
                var exc = $('.pl-excuse', row);
                return statusOf(row) === 'E' && exc && !exc.value.trim();
            });
        }

        function clearRowErrors() {
            rows.forEach(function (row) {
                row.classList.remove('has-row-error');
                var err = $('[data-err]', row);
                if (err) { err.hidden = true; err.textContent = ''; }
                var exc = $('.pl-excuse', row);
                if (exc) { exc.classList.remove('is-invalid'); }
            });
        }

        function showRowErrors(map) {
            Object.keys(map).forEach(function (cid) {
                var row = $('.pl-cadet[data-cadet-id="' + cid + '"]', form);
                if (!row) { return; }
                row.classList.add('has-row-error');
                var err = $('[data-err]', row);
                if (err) { err.textContent = map[cid]; err.hidden = false; }
                var exc = $('.pl-excuse', row);
                if (exc && statusOf(row) === 'E') { exc.classList.add('is-invalid'); }
            });
        }

        function save() {
            if (saving) { queued = true; return; }
            if (needsReason()) {
                setSave('saving', 'Add an excuse reason to save');
                return;
            }
            saving = true;
            queued = false;
            var seq = changeSeq;
            setSave('saving', 'Saving…');

            fetch(saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ session_id: sessionId, csrf_token: csrf, records: collect() })
            }).then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    return { ok: res.ok, data: data };
                });
            }).then(function (r) {
                if (r.ok && r.data && r.data.success) {
                    clearRowErrors();
                    if (seq === changeSeq) { dirty = false; }
                    setSave('saved', 'Saved ' + (r.data.saved_at || 'just now'));
                } else {
                    setSave('error', (r.data && r.data.error) || 'Could not save. Try again.');
                    if (r.data && r.data.row_errors) { showRowErrors(r.data.row_errors); }
                }
            }).catch(function () {
                setSave('error', 'No connection. Changes not saved.');
            }).then(function () {
                saving = false;
                if (queued || (dirty && seq !== changeSeq)) { schedule(); }
            });
        }

        function schedule() {
            dirty = true;
            changeSeq++;
            setSave('saving', 'Unsaved changes…');
            clearTimeout(timer);
            timer = setTimeout(save, 900);
        }

        function warnSubmittedOnce() {
            if (submitted && !warnedSubmitted) {
                warnedSubmitted = true;
                toast('Editing a submitted sheet resets any S1 approval when you save.', 'warning', 6000);
            }
        }

        /* --- editing --- */
        if (editable) {
            form.addEventListener('change', function (e) {
                var t = e.target;
                if (t.matches('input[type="radio"]')) {
                    var row = t.closest('.pl-cadet');
                    if (!row) { return; }
                    syncRow(row);
                    tally();
                    warnSubmittedOnce();
                    schedule();
                    if (t.value === 'E') {
                        var exc = $('.pl-excuse', row);
                        if (exc && !exc.value) { exc.focus(); }
                    }
                } else if (t.matches('.pl-minutes, .pl-excuse')) {
                    schedule();
                }
            });

            form.addEventListener('input', function (e) {
                if (e.target.matches('.pl-minutes, .pl-excuse')) { schedule(); }
            });

            form.addEventListener('click', function (e) {
                var clr = e.target.closest('.pl-clear');
                if (clr) {
                    var row = clr.closest('.pl-cadet');
                    $$('input[type="radio"]', row).forEach(function (r) { r.checked = false; });
                    syncRow(row);
                    tally();
                    schedule();
                    return;
                }
                var chip = e.target.closest('[data-minutes]');
                if (chip) {
                    var r2 = chip.closest('.pl-cadet');
                    var input = $('.pl-minutes', r2);
                    if (input) { input.value = chip.getAttribute('data-minutes'); schedule(); }
                }
            });

            var markAll = $('[data-mark-all]', form);
            if (markAll) {
                markAll.addEventListener('click', function () {
                    var changed = 0;
                    rows.forEach(function (row) {
                        if (statusOf(row) !== '') { return; }
                        var p = $('input[type="radio"][value="P"]', row);
                        if (p) { p.checked = true; syncRow(row); changed++; }
                    });
                    tally();
                    applyFilter();
                    if (changed > 0) {
                        schedule();
                        toast('Marked ' + changed + ' cadet(s) as Present.', 'success');
                    } else {
                        toast('Everyone is already marked.', 'info');
                    }
                });
            }

            /* --- submit flow --- */
            $$('[data-submit]', form).forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    submitFlow();
                });
            });

            form.addEventListener('submit', function () { leaving = true; });

            // Enter inside a field should not trigger Save draft by accident.
            form.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.matches('.pl-minutes, .pl-excuse, [data-search]')) {
                    e.preventDefault();
                }
            });

            window.addEventListener('beforeunload', function (e) {
                if (dirty && !leaving) { e.preventDefault(); e.returnValue = ''; }
            });
        }

        function submitFlow() {
            if (counts.unmarked > 0) {
                var names = rows.filter(function (r) { return statusOf(r) === ''; })
                    .map(function (r) { var n = $('.pl-cadet-name', r); return n ? n.textContent.trim() : 'Unnamed cadet'; });
                var preview = names.slice(0, 5).map(esc).join('<br>');
                var more = names.length > 5
                    ? '<div style="margin-top:4px;font-style:italic;">…and ' + (names.length - 5) + ' more</div>' : '';
                ask({
                    title: 'Mark everyone before submitting',
                    message: '<p style="margin-bottom:10px;"><strong>' + names.length + ' cadet(s)</strong> still need a mark:</p>' +
                        '<div style="background:#FFF5F5;border:1px solid #FEB2B2;border-radius:6px;padding:10px 12px;color:#C53030;font-size:13px;max-height:140px;overflow-y:auto;">' +
                        preview + more + '</div>',
                    confirmText: 'Show them',
                    cancelText: 'Close'
                }).then(function (go) {
                    if (go === false) { return; }
                    if (searchBox) { searchBox.value = ''; term = ''; }
                    setFilter('unmarked');
                    var first = rows.filter(function (r) { return !r.hidden; })[0];
                    if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                });
                return;
            }

            ask({
                title: 'Submit attendance to S1',
                message: '<p style="margin-bottom:8px;">Submit attendance for <strong>' + esc(label) + '</strong>?</p>' +
                    '<p style="font-size:13px;margin:0;">It goes to <strong>Battalion S1</strong> and <strong>Brigade S1</strong> for review.</p>',
                confirmText: 'Submit attendance',
                cancelText: 'Cancel'
            }).then(function (ok) {
                if (!ok) { return; }
                var action = $('input[name="form_action"][type="hidden"]', form);
                if (!action) {
                    action = document.createElement('input');
                    action.type = 'hidden';
                    action.name = 'form_action';
                    form.appendChild(action);
                }
                action.value = 'submit';
                leaving = true;
                form.submit();
            });
        }

        /* --- initial paint --- */
        rows.forEach(syncRow);
        tally();
        applyFilter();
    }

    /* ---------- 4. Roster: confirm remove ---------- */
    function initRoster() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-remove-cadet');
            if (!btn) { return; }
            e.preventDefault();
            var form = btn.closest('form');
            var name = btn.getAttribute('data-name') || 'this cadet';
            var code = btn.getAttribute('data-code') || '';

            ask({
                title: 'Remove cadet from platoon',
                message: '<p style="margin-bottom:12px;">Remove <strong>' + esc(name) + '</strong>' +
                    (code ? ' (' + esc(code) + ')' : '') + ' from your platoon?</p>' +
                    '<div style="background:#FFF5F5;border:1px solid #FEB2B2;border-radius:6px;padding:10px 12px;color:#C53030;font-size:13px;">' +
                    '<strong>This cannot be undone here.</strong> The cadet becomes <strong>Unassigned</strong> and only S1 can reassign them.</div>',
                confirmText: 'Remove cadet',
                cancelText: 'Cancel',
                isDanger: true
            }).then(function (ok) {
                if (ok && form) { form.submit(); }
            });
        });
    }

    /* ---------- boot ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        var page = $('.pl');
        if (page) {
            page.classList.add('pl-js');
            initReveal(page);
        }
        $$('.pl-picker').forEach(initPicker);
        $$('[data-pl-sheet]').forEach(initSheet);
        if ($('.btn-remove-cadet')) { initRoster(); }
    });
})();
