/* UnitSync S1 management interactions: filter panel, matrix navigation, quick shortcuts. */
(function () {
    'use strict';

    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /* ---------- Filter panel (search + Filters button) ---------- */
    function initFilterForm(form) {
        var wrap = qs('.s1-filter-wrap', form);
        var toggle = qs('[data-s1-toggle]', form);
        var panel = qs('[data-s1-panel]', form);
        if (!wrap || !toggle || !panel) return;

        var badge = qs('[data-s1-count]', form);
        var backdrop = qs('[data-s1-backdrop]', form);
        var closeBtn = qs('[data-s1-close]', form);
        var search = qs('input[type="search"]', form);
        var clearSearch = qs('[data-s1-search-clear]', form);
        var company = qs('select[name="company_id"]', form);
        var platoon = qs('select[name="platoon_id"]', form);
        var hint = qs('[data-s1-platoon-hint]', form);

        function setOpen(open) {
            wrap.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                var first = qs('select, input', panel);
                if (first) first.focus({ preventScroll: true });
            }
        }

        function refreshCount() {
            var n = 0;
            qsa('select', panel).forEach(function (s) {
                var set = ['', '0', 'all'].indexOf(s.value) === -1;
                s.classList.toggle('is-set', set);
                if (set) n++;
            });
            var radio = qs('input[type="radio"]:checked', panel);
            if (radio && ['', 'all'].indexOf(radio.value) === -1) n++;
            if (badge) { badge.textContent = n; badge.hidden = n === 0; }
            toggle.classList.toggle('has-filters', n > 0);
        }

        function syncPlatoons() {
            if (!company || !platoon) return;
            var c = company.value;
            var numeric = /^\d+$/.test(c) && c !== '0';
            qsa('option', platoon).forEach(function (o) {
                var oc = o.getAttribute('data-company');
                var hide = numeric && oc !== null && oc !== c;
                o.hidden = hide;
                o.disabled = hide;
            });
            var sel = platoon.options[platoon.selectedIndex];
            if (sel && sel.disabled) platoon.value = '';
            if (hint) hint.hidden = !numeric;
        }

        toggle.addEventListener('click', function () { setOpen(!wrap.classList.contains('open')); });
        if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); toggle.focus(); });
        if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
        document.addEventListener('click', function (e) {
            if (wrap.classList.contains('open') && !wrap.contains(e.target)) setOpen(false);
        });
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && wrap.classList.contains('open')) { setOpen(false); toggle.focus(); }
        });
        panel.addEventListener('change', function (e) {
            if (e.target === company) syncPlatoons();
            refreshCount();
        });

        if (search && clearSearch) {
            var hadValue = search.value !== '';
            search.addEventListener('input', function () { clearSearch.hidden = search.value === ''; });
            clearSearch.addEventListener('click', function () {
                search.value = '';
                clearSearch.hidden = true;
                if (hadValue) form.submit(); else search.focus();
            });
        }

        syncPlatoons();
        refreshCount();
    }

    /* ---------- Attendance matrix navigation ---------- */
    function initMatrix(wrap) {
        var table = qs('table', wrap);
        var nav = qs('[data-s1-nav-for="' + wrap.id + '"]');
        if (!table) return;

        var prev = nav && qs('[data-s1-scroll="prev"]', nav);
        var next = nav && qs('[data-s1-scroll="next"]', nav);
        var first = nav && qs('[data-s1-scroll="start"]', nav);
        var last = nav && qs('[data-s1-scroll="end"]', nav);
        var riskBtn = nav && qs('[data-s1-risk-toggle]', nav);

        function colWidth() {
            var th = qs('th.s1-sess', table);
            return th ? th.getBoundingClientRect().width : 90;
        }
        function step() {
            var w = colWidth();
            return w * Math.max(1, Math.floor((wrap.clientWidth - 420) / w));
        }
        function go(left) {
            wrap.scrollTo({ left: left, behavior: reduceMotion ? 'auto' : 'smooth' });
        }
        function update() {
            var max = wrap.scrollWidth - wrap.clientWidth;
            if (nav) nav.classList.toggle('no-scroll', max <= 2);
            if (prev) prev.disabled = wrap.scrollLeft <= 2;
            if (first) first.disabled = wrap.scrollLeft <= 2;
            if (next) next.disabled = wrap.scrollLeft >= max - 2;
            if (last) last.disabled = wrap.scrollLeft >= max - 2;
            wrap.classList.toggle('is-scrolled', wrap.scrollLeft > 2);
        }

        if (prev) prev.addEventListener('click', function () { go(wrap.scrollLeft - step()); });
        if (next) next.addEventListener('click', function () { go(wrap.scrollLeft + step()); });
        if (first) first.addEventListener('click', function () { go(0); });
        if (last) last.addEventListener('click', function () { go(wrap.scrollWidth); });
        wrap.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);

        // Arrow keys when the table area itself is focused
        wrap.addEventListener('keydown', function (e) {
            if (e.target !== wrap) return;
            if (e.key === 'ArrowRight') { e.preventDefault(); go(wrap.scrollLeft + colWidth() * 2); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); go(wrap.scrollLeft - colWidth() * 2); }
            else if (e.key === 'Home') { e.preventDefault(); go(0); }
            else if (e.key === 'End') { e.preventDefault(); go(wrap.scrollWidth); }
        });

        // Drag to scroll sideways (mouse only; touch scrolls natively)
        var down = false, moved = false, startX = 0, startLeft = 0;
        wrap.addEventListener('mousedown', function (e) {
            if (e.button !== 0 || e.target.closest('a, button, input, select, textarea')) return;
            down = true; moved = false; startX = e.pageX; startLeft = wrap.scrollLeft;
        });
        window.addEventListener('mousemove', function (e) {
            if (!down) return;
            var dx = e.pageX - startX;
            if (!moved && Math.abs(dx) > 4) { moved = true; wrap.classList.add('is-dragging'); }
            if (moved) wrap.scrollLeft = startLeft - dx;
        });
        window.addEventListener('mouseup', function () { down = false; wrap.classList.remove('is-dragging'); });

        // Highlight the hovered session column (row highlight is CSS)
        var lastIdx = -1;
        function clearHl() {
            qsa('.s1-col-hl', table).forEach(function (c) { c.classList.remove('s1-col-hl'); });
            lastIdx = -1;
        }
        table.addEventListener('mouseover', function (e) {
            var cell = e.target.closest('td.s1-sess, th.s1-sess');
            if (!cell) { clearHl(); return; }
            var idx = cell.cellIndex;
            if (idx === lastIdx) return;
            clearHl();
            lastIdx = idx;
            qsa('tr', table).forEach(function (r) {
                var c = r.cells[idx];
                if (c && c.classList.contains('s1-sess')) c.classList.add('s1-col-hl');
            });
        });
        table.addEventListener('mouseleave', clearHl);

        // "At risk on this page" toggle
        if (riskBtn) {
            riskBtn.addEventListener('click', function () {
                var on = table.classList.toggle('s1-only-risk');
                riskBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
                wrap.scrollTop = 0;
            });
        }

        update();
    }

    /* ---------- Boot ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        qsa('[data-s1-filters]').forEach(initFilterForm);
        qsa('.s1-matrix-wrap').forEach(initMatrix);

        // "/" jumps to the search box, like most admin tools
        document.addEventListener('keydown', function (e) {
            if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
            var t = e.target;
            if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) return;
            var s = qs('.s1-search input, .rf-search input');
            if (s) { e.preventDefault(); s.focus(); s.select(); }
        });
    });
})();