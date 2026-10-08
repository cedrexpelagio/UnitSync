/* =========================================================
   UnitSync - Cadet Roster interactivity (s1/roster.php)
   - One "Filters" button that opens a popover (bottom sheet on phones)
   - Live count badge of selected filters
   - Platoon list follows the selected company
   - Search clear button, Esc / outside-click / focus handling
   ========================================================= */
(function () {
    'use strict';

    var form     = document.getElementById('rosterFilters');
    if (!form) return;

    document.documentElement.classList.remove('no-js');

    var toggle   = document.getElementById('rfToggle');
    var panel    = document.getElementById('rfPanel');
    var backdrop = document.getElementById('rfBackdrop');
    var badge    = document.getElementById('rfCount');
    var closeBtn = document.getElementById('rfClose');
    var clearBtn = document.getElementById('rfClear');
    var search   = document.getElementById('q');
    var searchX  = document.getElementById('qClear');
    var company  = document.getElementById('company');
    var platoon  = document.getElementById('platoon');

    var mobileMQ = window.matchMedia('(max-width: 640px)');

    // ---------- Open / close ----------
    function isOpen() { return panel.classList.contains('open'); }

    function openPanel() {
        panel.classList.add('open');
        backdrop.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
        if (mobileMQ.matches) document.body.style.overflow = 'hidden';
        var first = panel.querySelector('select, input, button');
        if (first) first.focus();
    }

    function closePanel(returnFocus) {
        panel.classList.remove('open');
        backdrop.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (returnFocus) toggle.focus();
    }

    toggle.addEventListener('click', function () {
        isOpen() ? closePanel(false) : openPanel();
    });

    closeBtn.addEventListener('click', function () { closePanel(true); });
    backdrop.addEventListener('click', function () { closePanel(true); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) {
            e.preventDefault();
            closePanel(true);
        }
    });

    // Close when clicking anywhere outside the panel (desktop popover)
    document.addEventListener('mousedown', function (e) {
        if (!isOpen() || mobileMQ.matches) return;
        if (panel.contains(e.target) || toggle.contains(e.target)) return;
        closePanel(false);
    });

    // Keep Tab focus inside the panel while it is open
    panel.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') return;
        var items = panel.querySelectorAll(
            'button:not([disabled]), select:not([disabled]), input:not([disabled]), a[href]'
        );
        if (!items.length) return;
        var first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault(); first.focus();
        }
    });

    mobileMQ.addEventListener('change', function () {
        document.body.style.overflow = (isOpen() && mobileMQ.matches) ? 'hidden' : '';
    });

    // ---------- Live count on the button ----------
    function countFilters() {
        var n = 0;
        ['gender', 'program', 'company', 'platoon'].forEach(function (name) {
            var el = form.elements[name];
            if (el && el.value !== '') n++;
        });
        var st = form.querySelector('input[name="status"]:checked');
        if (st && st.value !== '') n++;
        return n;
    }

    function refreshBadge() {
        var n = countFilters();
        badge.textContent = n;
        badge.hidden = (n === 0);
        toggle.classList.toggle('has-filters', n > 0);
    }

    panel.addEventListener('change', function (e) {
        if (e.target.matches('select')) {
            e.target.classList.toggle('is-set', e.target.value !== '');
        }
        refreshBadge();
    });

    // ---------- Platoon follows company ----------
    function syncPlatoons() {
        if (!company || !platoon) return;
        var cid = company.value;
        var hint = document.getElementById('platoonHint');
        var selectedHidden = false;

        Array.prototype.forEach.call(platoon.options, function (opt) {
            if (opt.value === '') return;
            var match = (cid === '' || opt.getAttribute('data-company') === cid);
            opt.hidden = !match;
            opt.disabled = !match;
            if (!match && opt.selected) selectedHidden = true;
        });

        if (selectedHidden) {
            platoon.value = '';
            platoon.classList.remove('is-set');
        }
        if (hint) hint.hidden = (cid === '');
    }

    if (company) {
        company.addEventListener('change', function () {
            syncPlatoons();
            refreshBadge();
        });
    }

    // ---------- Clear (inside the panel): reset the fields, do not submit yet ----------
    clearBtn.addEventListener('click', function () {
        ['gender', 'program', 'company', 'platoon'].forEach(function (name) {
            var el = form.elements[name];
            if (el) { el.value = ''; el.classList.remove('is-set'); }
        });
        var all = form.querySelector('input[name="status"][value=""]');
        if (all) all.checked = true;
        syncPlatoons();
        refreshBadge();
    });

    // ---------- Search clear (x) ----------
    if (search && searchX) {
        searchX.addEventListener('click', function () {
            search.value = '';
            form.submit();
        });
    }

    // ---------- Init ----------
    syncPlatoons();
    refreshBadge();
})();