/* UnitSync S1 Dashboard behaviour
   - count-up KPI numbers and staggered reveal on scroll
   - accessible tabbed chart: animated donut (status) and line graph (growth)
   - data table fallback for the active chart
   No dependencies. Reads its data from #cadet-overview[data-dashboard]. */
(function () {
    'use strict';

    var root = document.getElementById('dashboard');
    var card = document.getElementById('cadet-overview');
    if (!root) return;

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var NS = 'http://www.w3.org/2000/svg';

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function parseDate(d) {
        var p = String(d).split('-');
        return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    }
    function shortDate(d) {
        var p = String(d).split('-');
        return MONTHS[parseInt(p[1], 10) - 1] + ' ' + parseInt(p[2], 10);
    }
    function longDate(d) {
        var p = String(d).split('-');
        return MONTHS[parseInt(p[1], 10) - 1] + ' ' + parseInt(p[2], 10) + ', ' + p[0];
    }
    function nextFrame(fn) { requestAnimationFrame(function () { requestAnimationFrame(fn); }); }

    /* ---------------------------------------------------------------
     * 1. Reveal on scroll + count-up
     * ------------------------------------------------------------- */
    root.classList.add('db-js');

    var reveals = root.querySelectorAll('.db-reveal');
    reveals.forEach(function (el, i) { el.style.setProperty('--delay', Math.min(i, 8) * 60 + 'ms'); });

    function countUp(el) {
        var target = parseInt(el.getAttribute('data-count'), 10) || 0;
        if (reduceMotion || target === 0) { el.textContent = target; return; }
        var start = null, dur = 900;
        function tick(ts) {
            if (start === null) start = ts;
            var t = Math.min((ts - start) / dur, 1);
            var eased = 1 - Math.pow(1 - t, 3);
            el.textContent = Math.round(target * eased);
            if (t < 1) requestAnimationFrame(tick); else el.textContent = target;
        }
        el.textContent = '0';
        requestAnimationFrame(tick);
    }

    function show(el) {
        el.classList.add('is-visible');
        el.querySelectorAll('[data-count]').forEach(countUp);
    }

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { show(en.target); io.unobserve(en.target); }
            });
        }, { threshold: 0.12 });
        reveals.forEach(function (el) { io.observe(el); });
    } else {
        reveals.forEach(show);
    }

    if (!card) return;

    /* ---------------------------------------------------------------
     * 2. Charts
     * ------------------------------------------------------------- */
    var data;
    try { data = JSON.parse(card.getAttribute('data-dashboard')); } catch (e) { data = { status: [], timeline: [] }; }
    var status = data.status || [];
    var timeline = data.timeline || [];

    var area = document.getElementById('chart-area');
    var note = document.getElementById('chart-note');
    var panel = document.getElementById('chart-panel');
    var tabs = card.querySelectorAll('.db-segmented [role="tab"]');
    var thumb = card.querySelector('.db-segmented-thumb');
    var rangeBox = document.getElementById('range-controls');
    var rangeBtns = rangeBox.querySelectorAll('[data-range]');
    var tableBtn = document.getElementById('toggle-table');
    var tableWrap = document.getElementById('chart-table-wrap');

    var state = { view: 'pie', range: 0, hidden: {}, tableOpen: false };

    function setThumb() {
        var sel = card.querySelector('.db-segmented [aria-selected="true"]');
        if (!sel || !thumb) return;
        thumb.style.width = sel.offsetWidth + 'px';
        thumb.style.transform = 'translateX(' + sel.offsetLeft + 'px)';
    }

    function emptyState(msg) {
        return '<div class="db-empty"><svg class="db-icon" style="width:32px;height:32px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>' +
            '<strong>' + esc(msg) + '</strong>' +
            '<a class="btn btn-primary btn-sm" href="' + esc((data.rosterUrl || '').replace('roster.php', 'import_cadets.php')) + '">Import cadets (CSV)</a></div>';
    }

    /* ---------- Donut ---------- */
    function renderPie() {
        note.textContent = 'Where every cadet stands right now. Hover or focus a status to inspect it, click to hide it.';
        var total = status.reduce(function (s, d) { return s + d.value; }, 0);
        if (total === 0) { area.innerHTML = emptyState('No cadets yet'); return; }

        var R = 80, C = 2 * Math.PI * R;

        var legend = status.map(function (d, i) {
            var pct = (d.value / total * 100).toFixed(1);
            return '<li><button type="button" data-i="' + i + '" aria-pressed="true" style="--c:' + esc(d.color) + '">' +
                '<span class="db-swatch"></span>' +
                '<span><span class="db-legend-name">' + esc(d.label) + '</span><span class="db-legend-hint">' + esc(d.hint) + '</span></span>' +
                '<span class="db-legend-num">' + d.value + '<small>' + pct + '%</small></span></button></li>';
        }).join('');

        var circles = status.map(function (d, i) {
            return '<circle class="db-seg" data-i="' + i + '" cx="100" cy="100" r="' + R + '" stroke="' + esc(d.color) + '" ' +
                'stroke-dasharray="0 ' + C.toFixed(2) + '" stroke-dashoffset="0" aria-hidden="true"/>';
        }).join('');

        area.innerHTML =
            '<div class="db-donut-layout">' +
              '<div class="db-donut-wrap">' +
                '<svg viewBox="0 0 200 200" role="img" aria-label="Donut chart of cadets by status. Total ' + total + '."><circle class="db-donut-track" cx="100" cy="100" r="' + R + '"/>' + circles + '</svg>' +
                '<div class="db-donut-center" aria-hidden="true"><span class="db-donut-value"></span><span class="db-donut-label"></span><span class="db-donut-pct"></span></div>' +
              '</div>' +
              '<ul class="db-legend">' + legend +
                '<li class="db-legend-cta">Open in roster: <a href="' + esc(data.rosterUrl) + '?status=active">Active</a> · <a href="' + esc(data.rosterUrl) + '?status=unassigned">Unassigned</a></li>' +
              '</ul>' +
            '</div>';

        var segs = area.querySelectorAll('.db-seg');
        var btns = area.querySelectorAll('.db-legend button');
        var cVal = area.querySelector('.db-donut-value');
        var cLab = area.querySelector('.db-donut-label');
        var cPct = area.querySelector('.db-donut-pct');

        function visibleTotal() {
            return status.reduce(function (s, d, i) { return s + (state.hidden[i] ? 0 : d.value); }, 0);
        }
        function centerDefault() {
            var vt = visibleTotal();
            cVal.textContent = vt;
            cLab.textContent = vt === total ? 'Total cadets' : 'Shown cadets';
            cPct.textContent = '';
        }
        function centerFor(i) {
            var vt = visibleTotal();
            cVal.textContent = status[i].value;
            cLab.textContent = status[i].label;
            cPct.textContent = (vt ? (status[i].value / vt * 100).toFixed(1) : '0.0') + '% of shown';
        }
        function layout(animate) {
            var vt = visibleTotal();
            var visibleCount = status.filter(function (d, i) { return !state.hidden[i] && d.value > 0; }).length;
            var gap = visibleCount > 1 ? 3 : 0;
            var cum = 0;
            segs.forEach(function (seg, i) {
                var hidden = state.hidden[i] || status[i].value === 0 || vt === 0;
                seg.classList.toggle('is-off', !!hidden);
                if (hidden) { seg.setAttribute('stroke-dasharray', '0 ' + C.toFixed(2)); return; }
                var len = status[i].value / vt * C;
                seg.setAttribute('stroke-dashoffset', (-cum).toFixed(2));
                seg.setAttribute('stroke-dasharray', Math.max(len - gap, 0.01).toFixed(2) + ' ' + C.toFixed(2));
                cum += len;
            });
            centerDefault();
        }
        function highlight(i) {
            segs.forEach(function (s, k) { s.classList.toggle('is-hover', k === i); s.classList.toggle('is-dim', i !== null && k !== i); });
            btns.forEach(function (b, k) { b.classList.toggle('is-hover', k === i); });
            if (i === null) centerDefault(); else centerFor(i);
        }

        segs.forEach(function (seg) {
            var i = parseInt(seg.getAttribute('data-i'), 10);
            seg.addEventListener('pointerenter', function () { highlight(i); });
            seg.addEventListener('pointerleave', function () { highlight(null); });
            seg.addEventListener('click', function () {
                var k = status[i].key;
                if (k === 'active' || k === 'unassigned') window.location.href = data.rosterUrl + '?status=' + k;
            });
        });
        btns.forEach(function (b) {
            var i = parseInt(b.getAttribute('data-i'), 10);
            b.addEventListener('pointerenter', function () { highlight(i); });
            b.addEventListener('pointerleave', function () { highlight(null); });
            b.addEventListener('focus', function () { highlight(i); });
            b.addEventListener('blur', function () { highlight(null); });
            b.addEventListener('click', function () {
                var visible = status.filter(function (d, k) { return !state.hidden[k]; }).length;
                var willHide = !state.hidden[i];
                if (willHide && visible === 1) return; // always keep one slice visible
                state.hidden[i] = willHide;
                b.setAttribute('aria-pressed', willHide ? 'false' : 'true');
                layout(true);
                centerFor(i);
                if (state.tableOpen) buildTable();
            });
        });

        // draw-in animation
        if (reduceMotion) { layout(false); } else { centerDefault(); nextFrame(function () { layout(true); }); }
        Object.keys(state.hidden).forEach(function (k) {
            if (state.hidden[k]) btns[k].setAttribute('aria-pressed', 'false');
        });
    }

    /* ---------- Line graph ---------- */
    function filteredTimeline() {
        if (!state.range) return timeline.slice();
        var cutoff = new Date();
        cutoff.setHours(0, 0, 0, 0);
        cutoff.setDate(cutoff.getDate() - state.range);
        return timeline.filter(function (p) { return parseDate(p.date) >= cutoff; });
    }

    function renderLine() {
        note.textContent = 'Running total of cadets by the date they were added. Hover, tap or use the arrow keys to inspect a day.';
        if (!timeline.length) { area.innerHTML = emptyState('No cadets yet'); return; }

        var pts = filteredTimeline();
        if (!pts.length) {
            area.innerHTML = '<div class="db-empty"><strong>No cadets were added in this range.</strong><span>Try a longer range.</span></div>';
            return;
        }

        var W = 640, H = 300, pl = 44, pr = 22, pt = 22, pb = 44;
        var n = pts.length;
        var maxY = pts[n - 1].total;
        var step = Math.max(1, Math.ceil(maxY / 4)), top = step * 4;
        var plotW = W - pl - pr, plotH = H - pt - pb;
        function xAt(i) { return n === 1 ? pl + plotW / 2 : pl + i * plotW / (n - 1); }
        function yAt(v) { return pt + plotH - (v / top) * plotH; }

        var grid = '';
        for (var t = 0; t <= 4; t++) {
            var v = t * step, y = yAt(v).toFixed(1);
            grid += '<line class="db-grid-line" x1="' + pl + '" y1="' + y + '" x2="' + (W - pr) + '" y2="' + y + '"/>' +
                '<text class="db-axis-text" x="' + (pl - 8) + '" y="' + (parseFloat(y) + 4) + '" text-anchor="end">' + v + '</text>';
        }
        var every = Math.ceil(n / 6), labels = '';
        pts.forEach(function (p, i) {
            if (i % every === 0 || i === n - 1) {
                labels += '<text class="db-axis-text" x="' + xAt(i).toFixed(1) + '" y="' + (H - pb + 22) + '" text-anchor="' + (n === 1 ? 'middle' : i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle') + '">' + shortDate(p.date) + '</text>';
            }
        });

        var linePts = pts.map(function (p, i) { return xAt(i).toFixed(1) + ' ' + yAt(p.total).toFixed(1); });
        var lineD = 'M' + linePts.join(' L');
        var areaD = lineD + ' L' + xAt(n - 1).toFixed(1) + ' ' + yAt(0).toFixed(1) + ' L' + xAt(0).toFixed(1) + ' ' + yAt(0).toFixed(1) + ' Z';
        var dots = '';
        if (n <= 40) {
            pts.forEach(function (p, i) {
                dots += '<circle class="db-dot" cx="' + xAt(i).toFixed(1) + '" cy="' + yAt(p.total).toFixed(1) + '" r="4.5" style="transition-delay:' + (reduceMotion ? 0 : 600 + i * 25) + 'ms"/>';
            });
        }

        var added = pts.reduce(function (s, p) { return s + p.added; }, 0);
        var last = pts[n - 1];

        area.innerHTML =
            '<div class="db-line-summary">' +
                '<div><strong>' + last.total + '</strong><span>Total cadets' + (state.range ? '' : ' to date') + '</span></div>' +
                '<div><strong>+' + added + '</strong><span>Added ' + (state.range ? 'in last ' + state.range + ' days' : 'overall') + '</span></div>' +
                '<div><strong>' + shortDate(last.date) + '</strong><span>Latest import</span></div>' +
            '</div>' +
            '<div class="db-line-wrap">' +
              '<svg class="db-line-svg" viewBox="0 0 ' + W + ' ' + H + '" tabindex="0" role="img" aria-label="Line graph of total cadets over time. Use left and right arrow keys to read each date.">' +
                '<defs><linearGradient id="db-area-grad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2E7D32" stop-opacity="0.28"/><stop offset="1" stop-color="#2E7D32" stop-opacity="0"/></linearGradient></defs>' +
                grid + labels +
                '<path class="db-line-area" d="' + areaD + '"/>' +
                '<path class="db-line-path" pathLength="1" d="' + lineD + '"/>' + dots +
                '<line class="db-cross" x1="0" x2="0" y1="' + pt + '" y2="' + (H - pb) + '"/>' +
                '<circle class="db-focus-dot" r="6" cx="0" cy="0"/>' +
              '</svg>' +
              '<div class="db-tooltip" role="status"></div>' +
            '</div>';

        var svg = area.querySelector('.db-line-svg');
        var wrap = area.querySelector('.db-line-wrap');
        var tip = area.querySelector('.db-tooltip');
        var cross = area.querySelector('.db-cross');
        var fdot = area.querySelector('.db-focus-dot');
        var idx = n - 1;

        function showPoint(i) {
            idx = Math.max(0, Math.min(n - 1, i));
            var p = pts[idx], x = xAt(idx), y = yAt(p.total);
            cross.setAttribute('x1', x); cross.setAttribute('x2', x);
            fdot.setAttribute('cx', x); fdot.setAttribute('cy', y);
            svg.classList.add('is-hover');
            tip.innerHTML = '<span>' + longDate(p.date) + '</span><b>' + p.total + ' total</b><span>+' + p.added + ' added that day</span>';
            var rect = svg.getBoundingClientRect(), scale = rect.width / W;
            var left = Math.max(80, Math.min(rect.width - 80, x * scale));
            tip.style.left = left + 'px';
            tip.style.top = (y * scale) + 'px';
            tip.classList.add('is-on');
        }
        function hidePoint() { svg.classList.remove('is-hover'); tip.classList.remove('is-on'); }

        svg.addEventListener('pointermove', function (e) {
            var rect = svg.getBoundingClientRect();
            var x = (e.clientX - rect.left) / rect.width * W;
            var best = 0, bd = Infinity;
            for (var i = 0; i < n; i++) { var d = Math.abs(xAt(i) - x); if (d < bd) { bd = d; best = i; } }
            showPoint(best);
        });
        svg.addEventListener('pointerleave', hidePoint);
        svg.addEventListener('focus', function () { showPoint(idx); });
        svg.addEventListener('blur', hidePoint);
        svg.addEventListener('keydown', function (e) {
            var k = e.key, next = null;
            if (k === 'ArrowRight') next = idx + 1;
            else if (k === 'ArrowLeft') next = idx - 1;
            else if (k === 'Home') next = 0;
            else if (k === 'End') next = n - 1;
            if (next === null) return;
            e.preventDefault();
            showPoint(next);
        });

        var drawEls = area.querySelectorAll('.db-line-path, .db-line-area, .db-dot');
        if (reduceMotion) drawEls.forEach(function (el) { el.classList.add('is-drawn'); });
        else nextFrame(function () { drawEls.forEach(function (el) { el.classList.add('is-drawn'); }); });
    }

    /* ---------- Table fallback ---------- */
    function buildTable() {
        var html;
        if (state.view === 'pie') {
            var total = status.reduce(function (s, d) { return s + d.value; }, 0);
            html = '<table><caption class="screen-reader-text" style="position:absolute;left:-9999px">Cadets by status</caption><thead><tr><th>Status</th><th class="num">Cadets</th><th class="num">Share</th><th>Detail</th></tr></thead><tbody>' +
                status.map(function (d) {
                    return '<tr><td>' + esc(d.label) + '</td><td class="num">' + d.value + '</td><td class="num">' +
                        (total ? (d.value / total * 100).toFixed(1) : '0.0') + '%</td><td>' + esc(d.hint) + '</td></tr>';
                }).join('') + '</tbody></table>';
        } else {
            var pts = filteredTimeline();
            html = '<table><caption class="screen-reader-text" style="position:absolute;left:-9999px">Cadets added over time</caption><thead><tr><th>Date</th><th class="num">Added</th><th class="num">Running total</th></tr></thead><tbody>' +
                (pts.length ? pts.slice().reverse().map(function (p) {
                    return '<tr><td>' + longDate(p.date) + '</td><td class="num">+' + p.added + '</td><td class="num">' + p.total + '</td></tr>';
                }).join('') : '<tr><td colspan="3">No data in this range.</td></tr>') + '</tbody></table>';
        }
        tableWrap.innerHTML = html;
    }

    /* ---------- Wiring ---------- */
    function render(animateSwap) {
        rangeBox.hidden = state.view !== 'line';
        if (state.view === 'line') renderLine(); else renderPie();
        if (animateSwap && !reduceMotion) {
            area.classList.remove('is-swapping');
            void area.offsetWidth;
            area.classList.add('is-swapping');
        }
        if (state.tableOpen) buildTable();
    }

    function selectView(view, focus) {
        if (view === state.view) return;
        state.view = view;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-view') === view;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on) { panel.setAttribute('aria-labelledby', t.id); if (focus) t.focus(); }
        });
        setThumb();
        render(true);
    }

    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { selectView(t.getAttribute('data-view'), false); });
        t.addEventListener('keydown', function (e) {
            var j = null;
            if (e.key === 'ArrowRight') j = (i + 1) % tabs.length;
            else if (e.key === 'ArrowLeft') j = (i - 1 + tabs.length) % tabs.length;
            if (j === null) return;
            e.preventDefault();
            selectView(tabs[j].getAttribute('data-view'), true);
        });
    });

    rangeBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            state.range = parseInt(b.getAttribute('data-range'), 10) || 0;
            rangeBtns.forEach(function (x) { x.classList.toggle('is-on', x === b); });
            render(false);
        });
    });

    tableBtn.addEventListener('click', function () {
        state.tableOpen = !state.tableOpen;
        tableBtn.setAttribute('aria-expanded', state.tableOpen ? 'true' : 'false');
        tableBtn.querySelector('span').textContent = state.tableOpen ? 'Hide data table' : 'Show data as table';
        tableWrap.hidden = !state.tableOpen;
        if (state.tableOpen) buildTable();
    });

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(setThumb, 100);
    });

    // Draw charts the first time the card scrolls into view so the animation is seen
    setThumb();
    if ('IntersectionObserver' in window && !reduceMotion) {
        var started = false;
        var cio = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting && !started) { started = true; render(false); setThumb(); cio.disconnect(); }
        }, { threshold: 0.25 });
        cio.observe(card);
    } else {
        render(false);
    }
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(setThumb);
})();