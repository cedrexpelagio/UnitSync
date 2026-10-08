/* =========================================================
   UnitSync - Take Attendance (leader/attendance.php)
   - training-day dropdown (single button)
   - minimal "x / n marked" counter + collapsible donut summary
   - search and "unmarked only" filter
   - debounced auto-save with a save queue (no edits are lost while saving)
   - late-minute quick chips, clear button, Mark All Present
   - warns before leaving with unsaved changes
   Config comes from data-* attributes on #att-sheet:
     data-session-id, data-save-url, data-editable (1|0), data-total
   ========================================================= */
(function () {
    'use strict';

    // ---------- Training-day dropdown ----------
    var dayBtn  = document.getElementById('att-day-btn');
    var dayMenu = document.getElementById('att-day-menu');

    if (dayBtn && dayMenu) {
        var dayOptions = function () {
            return Array.prototype.slice.call(dayMenu.querySelectorAll('.att-day-opt:not(.is-disabled)'));
        };

        var openDay = function () {
            dayMenu.hidden = false;
            dayBtn.setAttribute('aria-expanded', 'true');
            var cur = dayMenu.querySelector('.att-day-opt.is-active') || dayOptions()[0];
            if (cur) {
                dayMenu.scrollTop = cur.offsetTop - dayMenu.clientHeight / 2 + cur.offsetHeight / 2;
                cur.focus({ preventScroll: true });
            }
        };

        var closeDay = function (returnFocus) {
            dayMenu.hidden = true;
            dayBtn.setAttribute('aria-expanded', 'false');
            if (returnFocus) dayBtn.focus();
        };

        dayBtn.addEventListener('click', function () {
            dayMenu.hidden ? openDay() : closeDay(false);
        });

        document.addEventListener('mousedown', function (e) {
            if (dayMenu.hidden) return;
            if (dayMenu.contains(e.target) || dayBtn.contains(e.target)) return;
            closeDay(false);
        });

        dayMenu.addEventListener('keydown', function (e) {
            var opts = dayOptions();
            var i = opts.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); (opts[i + 1] || opts[0]).focus(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); (opts[i - 1] || opts[opts.length - 1]).focus(); }
            else if (e.key === 'Home') { e.preventDefault(); opts[0].focus(); }
            else if (e.key === 'End') { e.preventDefault(); opts[opts.length - 1].focus(); }
            else if (e.key === 'Escape') { e.preventDefault(); closeDay(true); }
            else if (e.key === 'Tab') { closeDay(false); }
        });

        dayBtn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' && dayMenu.hidden) { e.preventDefault(); openDay(); }
            if (e.key === 'Escape' && !dayMenu.hidden) { closeDay(true); }
        });
    }

    var sheet = document.getElementById('att-sheet');
    var table = document.getElementById('attendance-table');
    if (!sheet || !table) return;

    var editable = sheet.getAttribute('data-editable') === '1';
    var total    = parseInt(sheet.getAttribute('data-total'), 10) || 0;
    var rows     = Array.prototype.slice.call(table.querySelectorAll('.att-row'));

    // ---------- Counter + donut ----------
    var CIRC = 2 * Math.PI * 42;                       // matches r="42" in the SVG
    var order = ['P', 'A', 'L', 'E'];

    var el = {
        marked:  document.getElementById('cnt-marked'),
        bar:     document.getElementById('att-count-bar'),
        wrap:    document.getElementById('att-count'),
        pct:     document.getElementById('donut-pct'),
        P: document.getElementById('cnt-p'),
        A: document.getElementById('cnt-a'),
        L: document.getElementById('cnt-l'),
        E: document.getElementById('cnt-e'),
        U: document.getElementById('cnt-unmarked')
    };

    function renderCounts() {
        var c = { P: 0, A: 0, L: 0, E: 0 }, marked = 0;
        rows.forEach(function (r) {
            var s = r.getAttribute('data-status');
            if (c.hasOwnProperty(s)) { c[s]++; marked++; }
        });
        var pct = total ? Math.round(marked / total * 100) : 0;

        if (el.marked) el.marked.textContent = marked;
        if (el.bar) el.bar.style.width = pct + '%';
        if (el.wrap) el.wrap.classList.toggle('is-complete', total > 0 && marked === total);
        if (el.pct) el.pct.textContent = pct + '%';
        order.forEach(function (k) { if (el[k]) el[k].textContent = c[k]; });
        if (el.U) el.U.textContent = total - marked;

        // donut segments
        var acc = 0;
        order.forEach(function (k) {
            var seg = document.querySelector('.att-donut .seg-' + k.toLowerCase());
            if (!seg) return;
            var len = total ? c[k] / total * CIRC : 0;
            seg.setAttribute('stroke-dasharray', len + ' ' + (CIRC - len));
            seg.setAttribute('stroke-dashoffset', -acc);
            acc += len;
        });
    }

    // ---------- Show / hide summary (remembered) ----------
    var sumBtn   = document.getElementById('att-summary-toggle');
    var sumPanel = document.getElementById('att-summary');
    var STORE    = 'unitsync.attendance.summary';

    function setSummary(open) {
        if (!sumBtn || !sumPanel) return;
        sumPanel.hidden = !open;
        sumBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        var label = sumBtn.querySelector('.att-summary-label');
        if (label) label.textContent = open ? 'Hide summary' : 'Show summary';
        try { localStorage.setItem(STORE, open ? 'open' : 'closed'); } catch (e) { /* private mode */ }
    }

    if (sumBtn && sumPanel) {
        var stored = null;
        try { stored = localStorage.getItem(STORE); } catch (e) { /* ignore */ }
        setSummary(stored === 'open');
        sumBtn.addEventListener('click', function () { setSummary(sumPanel.hidden); });
    }

    // ---------- Search + "unmarked only" ----------
    var search      = document.getElementById('att-search');
    var unmarkedBtn = document.getElementById('att-filter-unmarked');
    var noResults   = document.getElementById('att-no-results');

    function applyFilter() {
        var q = search ? search.value.trim().toLowerCase() : '';
        var onlyUnmarked = unmarkedBtn && unmarkedBtn.getAttribute('aria-pressed') === 'true';
        var shown = 0;

        rows.forEach(function (r) {
            var okName = !q || (r.getAttribute('data-name') || '').indexOf(q) !== -1;
            var okMark = !onlyUnmarked || !r.getAttribute('data-status');
            r.hidden = !(okName && okMark);
            if (okName && okMark) shown++;
        });
        if (noResults) noResults.hidden = shown !== 0;
    }

    if (search) search.addEventListener('input', applyFilter);
    if (unmarkedBtn) {
        unmarkedBtn.addEventListener('click', function () {
            unmarkedBtn.setAttribute('aria-pressed', unmarkedBtn.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
            applyFilter();
        });
    }

    renderCounts();

    if (!editable) return;

    // ---------- Auto-save ----------
    var form      = document.getElementById('attendance-sheet-form');
    var csrfInput = sheet.querySelector('input[name="csrf_token"]');
    var csrf      = csrfInput ? csrfInput.value : '';
    var saveUrl   = sheet.getAttribute('data-save-url');
    var sessionId = parseInt(sheet.getAttribute('data-session-id'), 10);

    var indicator = document.getElementById('save-indicator');
    var statusTxt = document.getElementById('save-status-text');

    var timer = null, saving = false, pending = false, dirty = false;

    function setStatus(state, message) {
        if (!indicator || !statusTxt) return;
        indicator.className = 'save-indicator ' + state;
        statusTxt.textContent = message;
    }

    function toast(message, type) {
        if (typeof window.showToast === 'function') window.showToast(message, type);
    }

    function collect() {
        return rows.map(function (row) {
            var checked = row.querySelector('input[type="radio"]:checked');
            var late    = row.querySelector('.input-late');
            var excuse  = row.querySelector('.input-excuse');
            return {
                cadet_id:      parseInt(row.getAttribute('data-cadet-id'), 10),
                status:        checked ? checked.value : '',
                minutes_late:  late ? late.value.trim() : null,
                excuse_reason: excuse ? excuse.value.trim() : null
            };
        });
    }

    function clearRowErrors() {
        rows.forEach(function (row) {
            row.classList.remove('has-row-error');
            var msg = row.querySelector('.att-row-error');
            if (msg) { msg.hidden = true; msg.textContent = ''; }
            var ex = row.querySelector('.input-excuse');
            if (ex) ex.classList.remove('is-invalid');
        });
    }

    function showRowErrors(map) {
        Object.keys(map).forEach(function (cid) {
            var row = table.querySelector('.att-row[data-cadet-id="' + cid + '"]');
            if (!row) return;
            row.classList.add('has-row-error');
            var msg = row.querySelector('.att-row-error');
            if (msg) { msg.textContent = map[cid]; msg.hidden = false; }
            var ex = row.querySelector('.input-excuse');
            if (ex && row.getAttribute('data-status') === 'E') ex.classList.add('is-invalid');
        });
    }

    function save() {
        if (saving) { pending = true; return; }   // queue: save again right after
        saving = true;
        pending = false;
        setStatus('saving', 'Saving\u2026');

        fetch(saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ session_id: sessionId, csrf_token: csrf, records: collect() })
        })
        .then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        })
        .then(function (r) {
            clearRowErrors();
            if (r.ok && r.data && r.data.success) {
                if (!pending) {
                    dirty = false;
                    setStatus('saved', 'Saved at ' + (r.data.saved_at || 'just now'));
                }
            } else {
                var d = r.data || {};
                if (d.row_errors) showRowErrors(d.row_errors);
                setStatus('error', d.error || 'Save failed');
            }
        })
        .catch(function () {
            setStatus('error', 'Could not save. Check your connection.');
        })
        .then(function () {
            saving = false;
            if (pending) save();
        });
    }

    function scheduleSave() {
        dirty = true;
        setStatus('dirty', 'Unsaved changes\u2026');
        clearTimeout(timer);
        timer = setTimeout(save, 1000);
    }

    function setRowStatus(row, status) {
        row.setAttribute('data-status', status || '');
    }

    table.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.matches('input[type="radio"]')) return;
        var row = t.closest('.att-row');
        if (!row) return;

        setRowStatus(row, t.value);
        renderCounts();
        scheduleSave();

        if (t.value === 'L') {
            var late = row.querySelector('.input-late');
            if (late && !late.value) late.focus();
        } else if (t.value === 'E') {
            var ex = row.querySelector('.input-excuse');
            if (ex && !ex.value) ex.focus();
        }
    });

    table.addEventListener('input', function (e) {
        if (e.target.matches('.input-late, .input-excuse')) {
            e.target.classList.remove('is-invalid');
            scheduleSave();
        }
    });

    table.addEventListener('click', function (e) {
        var clearBtn = e.target.closest('.btn-unmark');
        if (clearBtn) {
            var row = clearBtn.closest('.att-row');
            if (!row) return;
            row.querySelectorAll('input[type="radio"]').forEach(function (r) { r.checked = false; });
            setRowStatus(row, '');
            renderCounts();
            scheduleSave();
            return;
        }

        var quick = e.target.closest('.att-quick button');
        if (quick) {
            var input = quick.closest('.att-row').querySelector('.input-late');
            if (input) {
                input.value = quick.getAttribute('data-minutes');
                scheduleSave();
            }
        }
    });

    // Mark All Present: only fills cadets that are still unmarked
    var markAll = document.getElementById('btn-mark-all-present');
    if (markAll) {
        markAll.addEventListener('click', function () {
            var changed = 0;
            rows.forEach(function (row) {
                if (row.getAttribute('data-status')) return;
                var p = row.querySelector('input[type="radio"][value="P"]');
                if (p) { p.checked = true; setRowStatus(row, 'P'); changed++; }
            });

            if (changed > 0) {
                renderCounts();
                scheduleSave();
                toast('Marked ' + changed + ' unmarked cadet(s) as Present.', 'success');
            } else {
                toast('All cadets are already marked.', 'info');
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function () {
            clearTimeout(timer);
            dirty = false;
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (dirty || saving) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();