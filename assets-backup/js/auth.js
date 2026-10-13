/* ==========================================================================
   UnitSync - Auth interactions (login, register, register success)
   Self-contained: replaces register.js / ui.js on these three pages.
   ========================================================================== */
(function () {
    'use strict';

    const doc = document;
    const body = doc.body;
    const reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const $ = (s, r) => (r || doc).querySelector(s);
    const $$ = (s, r) => Array.prototype.slice.call((r || doc).querySelectorAll(s));

    /* ---------------- shared helpers ---------------- */

    function shake(field) {
        if (reduce) return;
        field.classList.remove('shake');
        void field.offsetWidth; // restart animation
        field.classList.add('shake');
        setTimeout(function () { field.classList.remove('shake'); }, 460);
    }

    function setError(field, msg, withShake) {
        field.classList.remove('is-valid');
        field.classList.add('has-error');
        const m = $('[data-msg]', field);
        if (m && msg) m.textContent = msg;
        $$('input, select', field).forEach(function (i) { i.setAttribute('aria-invalid', 'true'); });
        if (withShake) shake(field);
    }

    function clearError(field) {
        field.classList.remove('has-error', 'is-quiet');
        $$('input, select', field).forEach(function (i) { i.removeAttribute('aria-invalid'); });
    }

    function fieldIn(scope, name) { return $('[data-field="' + name + '"]', scope); }

    /* ---------------- page-to-page transitions ---------------- */

    function initNav() {
        $$('a[data-nav]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 || a.target === '_blank') return;
                e.preventDefault();
                const forward = a.dataset.nav === 'forward';
                try { sessionStorage.setItem('ua-enter', forward ? 'right' : 'left'); } catch (_) { /* ignore */ }
                body.classList.add(forward ? 'is-leaving-left' : 'is-leaving-right');
                setTimeout(function () { window.location.href = a.href; }, reduce ? 0 : 300);
            });
        });

        // Restore state when the browser brings the page back from bfcache
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;
            body.classList.remove('is-leaving-left', 'is-leaving-right', 'is-busy');
            $$('.ua-btn.is-loading').forEach(function (b) { b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); });
            $$('.ua-form.is-busy').forEach(function (f) { f.classList.remove('is-busy'); });
            $$('.ua-form input').forEach(function (i) { i.readOnly = false; });
        });
    }

    /* ---------------- password toggle + caps lock ---------------- */

    function initPasswordFields() {
        $$('.ua-eye').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const input = doc.getElementById(btn.dataset.target);
                if (!input) return;
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                input.focus();
            });
        });

        $$('input[type="password"]').forEach(function (inp) {
            const wrap = inp.closest('[data-field]');
            const note = wrap && $('[data-caps]', wrap);
            if (!note) return;
            const update = function (e) {
                if (e.getModifierState) note.hidden = !e.getModifierState('CapsLock');
            };
            inp.addEventListener('keydown', update);
            inp.addEventListener('keyup', update);
            inp.addEventListener('blur', function () { note.hidden = true; });
        });
    }

    /* ---------------- login ---------------- */

    function initLogin() {
        const form = $('#loginForm');
        if (!form) return;
        const btn = $('#loginSubmit');
        const live = $('#uaLive');
        const busyText = $('[data-busy-text]', btn);
        let busy = false;

        const rules = {
            username: function (v) { return v.trim() ? '' : 'Enter your username.'; },
            password: function (v) { return v ? '' : 'Enter your password.'; }
        };

        function check(name, withShake) {
            const input = form.elements[name];
            const field = fieldIn(form, name);
            const msg = rules[name](input.value);
            if (msg) { setError(field, msg, withShake); return false; }
            clearError(field);
            return true;
        }

        // Server told us the credentials were wrong: clear that state on the next edit
        function clearServerState() {
            $$('.is-quiet, [data-cred]', form).forEach(function (f) {
                clearError(f);
                f.removeAttribute('data-cred');
            });
        }

        ['username', 'password'].forEach(function (name) {
            const input = form.elements[name];
            const field = fieldIn(form, name);
            input.addEventListener('input', function () {
                input.dataset.touched = '1';
                clearServerState();
                if (field.classList.contains('has-error')) check(name, false);
            });
            input.addEventListener('blur', function () {
                if (input.dataset.touched) check(name, false);
            });
        });

        // Animate any server-rendered error on arrival
        setTimeout(function () {
            $$('.has-error', form).forEach(function (f) { shake(f); });
        }, 500);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (busy) return;

            const okU = check('username', true);
            const okP = check('password', true);
            if (!okU || !okP) {
                (okU ? form.elements.password : form.elements.username).focus();
                if (live) live.textContent = 'Please fix the highlighted fields.';
                return;
            }

            busy = true;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
            form.classList.add('is-busy');
            body.classList.add('is-busy');
            $$('input', form).forEach(function (i) { i.readOnly = true; });
            if (live) live.textContent = 'Signing you in.';
            setTimeout(function () { if (busyText) busyText.textContent = 'Still working…'; }, 4500);

            // Brief pause so the loading state is visible before the page request
            setTimeout(function () { form.submit(); }, reduce ? 120 : 700);
        });
    }

    /* ---------------- register (4-step wizard) ---------------- */

    function initRegister() {
        const form = $('#registerForm');
        if (!form) return;

        const TOTAL = 4;
        const panes = $$('[data-pane]', form);
        const stepItems = $$('.ua-step');
        const backBtn = $('#regBack');
        const nextBtn = $('#regNext');
        const submitBtn = $('#registerSubmit');
        const meter = $('#pwMeter');
        const meterLabel = $('#pwMeterLabel');
        const pwRules = $$('#pwRules li');
        const matchHint = $('#matchHint');
        const live = $('#uaLive');

        const STEP_FIELDS = {
            1: ['first_name', 'last_name', 'student_number', 'email'],
            2: ['password', 'confirm_password'],
            3: ['role', 'program_id', 'platoon_option'],
            4: ['consent']
        };
        const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const LEVELS = ['', 'Weak', 'Fair', 'Almost there', 'Looks good'];

        let current = parseInt(form.dataset.startStep, 10) || 1;
        let maxReached = parseInt(form.dataset.maxReach, 10) || 1;
        let moving = false;
        let submitting = false;

        const fieldEl = function (name) { return fieldIn(form, name); };
        const val = function (name) {
            const el = form.elements[name];
            if (!el) return '';
            if (el.type === 'checkbox') return el.checked ? '1' : '';
            return el.value || '';
        };

        const rules = {
            first_name: function (v) { return v.trim() ? '' : 'Enter your first name.'; },
            last_name: function (v) { return v.trim() ? '' : 'Enter your last name.'; },
            student_number: function (v) { return v.trim() ? '' : 'Enter your student number.'; },
            email: function (v) {
                if (!v.trim()) return 'Enter your email address.';
                return EMAIL_RE.test(v.trim()) ? '' : 'That email doesn’t look right. Try name@example.com.';
            },
            password: function (v) {
                if (!v) return 'Create a password.';
                if (v.length < 10) return 'Use at least 10 characters.';
                if (!/[A-Z]/.test(v) || !/[a-z]/.test(v) || !/[0-9]/.test(v)) return 'Mix uppercase, lowercase, and a number.';
                return '';
            },
            confirm_password: function (v) {
                if (!v) return 'Re-enter your password to confirm it.';
                return v === form.elements.password.value ? '' : 'Passwords don’t match yet.';
            },
            role: function (v) { return v ? '' : 'Select the role you hold in the unit.'; },
            program_id: function (v) { return v ? '' : 'Choose your program.'; },
            platoon_option: function (v) { return v ? '' : 'Choose your company and platoon.'; },
            consent: function (v) { return v ? '' : 'You need to agree to the Data Privacy Notice to continue.'; }
        };

        function relevant(name) {
            if (name === 'program_id') return val('role') === 'class_president';
            if (name === 'platoon_option') return val('role') === 'platoon_leader';
            return true;
        }

        function showsValid(field) {
            return !!($('.ua-tick', field) || $('input[type="password"]', field));
        }

        function focusName(name) {
            const el = form.elements[name];
            if (!el) return;
            const t = (window.RadioNodeList && el instanceof window.RadioNodeList) ? (el[0]) : el;
            if (t && t.focus) t.focus();
        }

        function markSteps() {
            stepItems.forEach(function (li, i) {
                li.classList.toggle('has-error', !!$('.has-error', panes[i]));
            });
        }

        // Validate one field. Returns true when OK.
        function validateField(name, withShake) {
            if (!relevant(name)) return true;
            const field = fieldEl(name);
            if (!field) return true;
            const msg = rules[name](val(name));
            if (msg) { setError(field, msg, withShake); return false; }
            clearError(field);
            if (showsValid(field) && val(name)) field.classList.add('is-valid');
            return true;
        }

        // Softer check used while typing / leaving a field
        function liveCheck(name, onBlur) {
            if (!relevant(name)) return;
            const field = fieldEl(name);
            if (!field) return;
            const msg = rules[name](val(name));
            const hadError = field.classList.contains('has-error');
            if (!msg) {
                clearError(field);
                if (showsValid(field) && val(name)) field.classList.add('is-valid');
            } else {
                field.classList.remove('is-valid');
                if (hadError || onBlur) setError(field, msg, false);
            }
        }

        function validateStep(n, withShake) {
            let first = null;
            STEP_FIELDS[n].forEach(function (name) {
                if (!validateField(name, withShake) && !first) first = name;
            });
            return first;
        }

        /* ---- password UI ---- */
        function updatePw() {
            const pw = form.elements.password.value;
            const checks = { length: pw.length >= 10, upper: /[A-Z]/.test(pw), lower: /[a-z]/.test(pw), number: /[0-9]/.test(pw) };
            let level = 0;
            pwRules.forEach(function (li) {
                const ok = !!checks[li.dataset.rule];
                li.classList.toggle('is-met', ok);
                if (ok) level++;
            });
            if (meter) meter.dataset.level = String(level);
            if (meterLabel) meterLabel.textContent = LEVELS[level];
        }

        function updateMatch() {
            if (!matchHint) return;
            const c = form.elements.confirm_password.value;
            const ok = c && c === form.elements.password.value;
            matchHint.innerHTML = ok
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>Passwords match'
                : '';
        }

        /* ---- role-specific fields ---- */
        function syncRole() {
            const r = val('role');
            $$('[data-reveal]', form).forEach(function (el) {
                const open = el.dataset.reveal === r;
                el.classList.toggle('is-open', open);
                if (open) el.removeAttribute('inert'); else el.setAttribute('inert', '');
            });
            ['program_id', 'platoon_option'].forEach(function (n) {
                if (!relevant(n)) { const f = fieldEl(n); if (f) clearError(f); }
            });
        }

        /* ---- review summary ---- */
        function fillReview() {
            const set = function (key, text, hide) {
                const dd = $('[data-review="' + key + '"]', form);
                if (!dd) return;
                dd.textContent = text;
                dd.parentNode.hidden = !!hide;
            };
            const name = [val('first_name'), val('middle_name'), val('last_name')].map(function (s) { return s.trim(); }).filter(Boolean).join(' ');
            set('name', name);
            set('student', val('student_number').trim());
            set('email', val('email').trim());
            const checked = $('input[name="role"]:checked', form);
            set('role', checked ? $('.ua-role-title', checked.closest('label')).textContent : '—');
            let assignment = '';
            if (val('role') === 'class_president') {
                const s = form.elements.program_id; assignment = s.selectedIndex > 0 ? s.options[s.selectedIndex].text.trim() : '';
            } else if (val('role') === 'platoon_leader') {
                const s = form.elements.platoon_option; assignment = s.selectedIndex > 0 ? s.options[s.selectedIndex].text.trim() : '';
            }
            set('assignment', assignment, !assignment);
        }

        /* ---- step navigation ---- */
        function paint() {
            stepItems.forEach(function (li, i) {
                const n = i + 1;
                li.classList.toggle('is-current', n === current);
                li.classList.toggle('is-done', n < current);
                const b = $('.ua-step-btn', li);
                b.disabled = n > maxReached;
                if (n === current) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
            });
            if (backBtn) backBtn.hidden = current === 1;
            form.classList.toggle('is-last', current === TOTAL);
            markSteps();
        }

        function afterShow(n) {
            if (n === TOTAL) fillReview();
            const pane = panes[n - 1];
            const first = $('input:not([type="radio"]):not([type="checkbox"]):not([type="hidden"]), select', pane);
            const target = first || $('[tabindex="-1"]', pane);
            if (target) target.focus({ preventScroll: true });
            const steps = $('.ua-steps');
            if (steps && steps.scrollIntoView) steps.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest' });
            if (live) live.textContent = 'Step ' + n + ' of ' + TOTAL;
        }

        function goTo(n) {
            if (n === current || moving || n < 1 || n > TOTAL) return;
            const dir = n > current ? 'fwd' : 'back';
            const from = panes[current - 1];
            const to = panes[n - 1];
            current = n;
            maxReached = Math.max(maxReached, n);
            moving = true;
            paint();

            const finish = function () {
                from.classList.remove('is-active', 'leave-fwd', 'leave-back');
                to.classList.add('is-active');
                if (!reduce) {
                    to.classList.add('enter-' + dir);
                    setTimeout(function () { to.classList.remove('enter-fwd', 'enter-back'); }, 480);
                }
                moving = false;
                afterShow(n);
            };

            if (reduce) { finish(); return; }
            from.classList.add('leave-' + dir);
            setTimeout(finish, 170);
        }

        function next() {
            if (current >= TOTAL || moving) return;
            const bad = validateStep(current, true);
            markSteps();
            if (bad) { focusName(bad); return; }
            goTo(current + 1);
        }

        function back() { goTo(current - 1); }

        stepItems.forEach(function (li, i) {
            const n = i + 1;
            $('.ua-step-btn', li).addEventListener('click', function () {
                if (n === current || moving) return;
                if (n < current) { goTo(n); return; }
                for (let s = current; s < n; s++) {
                    const bad = validateStep(s, true);
                    if (bad) {
                        markSteps();
                        if (s !== current) { goTo(s); setTimeout(function () { focusName(bad); }, 260); }
                        else focusName(bad);
                        return;
                    }
                }
                goTo(n);
            });
        });

        if (nextBtn) nextBtn.addEventListener('click', next);
        if (backBtn) backBtn.addEventListener('click', back);

        // Enter moves to the next step instead of submitting early
        form.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.target.tagName !== 'INPUT' || e.target.type === 'checkbox') return;
            if (current < TOTAL) { e.preventDefault(); next(); }
        });

        /* ---- live validation ---- */
        form.addEventListener('input', function (e) {
            const t = e.target;
            const n = t.name;
            if (!n || t.type === 'radio' || t.type === 'checkbox' || t.tagName === 'SELECT') return;
            t.dataset.dirty = '1';
            if (rules[n]) liveCheck(n, false);
            if (n === 'password') {
                updatePw();
                if (form.elements.confirm_password.value) liveCheck('confirm_password', false);
                updateMatch();
            }
            if (n === 'confirm_password') updateMatch();
            markSteps();
        });

        form.addEventListener('change', function (e) {
            const t = e.target;
            const n = t.name;
            if (!n || !rules[n]) return;
            if (n === 'role') syncRole();
            liveCheck(n, true);
            markSteps();
        });

        form.addEventListener('focusout', function (e) {
            const t = e.target;
            const n = t.name;
            if (!n || !rules[n] || !t.dataset.dirty) return;
            if (t.type === 'radio' || t.type === 'checkbox') return;
            liveCheck(n, true);
            markSteps();
        });

        /* ---- submit ---- */
        form.addEventListener('submit', function (e) {
            if (submitting) { e.preventDefault(); return; }
            for (let n = 1; n <= TOTAL; n++) {
                const bad = validateStep(n, true);
                if (bad) {
                    e.preventDefault();
                    markSteps();
                    if (n !== current) { goTo(n); setTimeout(function () { focusName(bad); }, 260); }
                    else focusName(bad);
                    return;
                }
            }
            e.preventDefault();
            submitting = true;
            submitBtn.classList.add('is-loading');
            submitBtn.setAttribute('aria-busy', 'true');
            form.classList.add('is-busy');
            body.classList.add('is-busy');
            if (backBtn) backBtn.disabled = true;
            if (live) live.textContent = 'Creating your account.';
            setTimeout(function () { form.submit(); }, reduce ? 150 : 900);
        });

        /* ---- init ---- */
        panes.forEach(function (p, i) { p.classList.toggle('is-active', i + 1 === current); });
        syncRole();
        updatePw();
        updateMatch();
        paint();
        if (current === TOTAL) fillReview();

        // Server-side errors: shake and focus the first one
        const firstErr = $('.has-error', panes[current - 1]);
        if (firstErr) {
            setTimeout(function () {
                $$('.has-error', panes[current - 1]).forEach(function (f) { shake(f); });
                const ctl = $('input, select', firstErr);
                if (ctl) ctl.focus({ preventScroll: true });
            }, 520);
        }
    }

    /* ---------------- register success ---------------- */

    function initSuccess() {
        const chip = $('[data-username]');
        const text = chip ? chip.textContent.trim() : '';

        // Letter-by-letter reveal of the username
        if (chip && text && !reduce) {
            chip.textContent = '';
            const sr = doc.createElement('span');
            sr.className = 'ua-sr';
            sr.textContent = text;
            chip.appendChild(sr);
            const vis = doc.createElement('span');
            vis.setAttribute('aria-hidden', 'true');
            text.split('').forEach(function (ch, i) {
                const s = doc.createElement('span');
                s.className = 'ua-char';
                s.style.setProperty('--c', String(i));
                s.textContent = ch;
                vis.appendChild(s);
            });
            chip.appendChild(vis);
        }

        // Confetti burst when the check mark lands
        const host = $('[data-confetti]');
        if (host && !reduce) {
            setTimeout(function () {
                const colors = ['#C9A227', '#3B5232', '#7AA06A', '#E6C44E', '#243321'];
                for (let i = 0; i < 28; i++) {
                    const p = doc.createElement('i');
                    const a = Math.random() * Math.PI * 2;
                    const d = 70 + Math.random() * 90;
                    p.style.setProperty('--x', (Math.cos(a) * d).toFixed(1) + 'px');
                    p.style.setProperty('--y', (Math.sin(a) * d + 40).toFixed(1) + 'px');
                    p.style.setProperty('--r', (Math.random() * 720 - 360).toFixed(0) + 'deg');
                    p.style.background = colors[i % colors.length];
                    p.style.animationDelay = Math.floor(Math.random() * 120) + 'ms';
                    if (i % 3 === 0) p.style.borderRadius = '50%';
                    host.appendChild(p);
                }
                setTimeout(function () { host.textContent = ''; }, 1900);
            }, 900);
        }

        // Copy username
        const copyBtn = $('[data-copy]');
        if (copyBtn && text) {
            const label = $('[data-copy-label]', copyBtn);
            const original = label ? label.textContent : '';
            copyBtn.addEventListener('click', function () {
                const done = function () {
                    copyBtn.classList.add('is-copied');
                    if (label) label.textContent = 'Copied!';
                    setTimeout(function () {
                        copyBtn.classList.remove('is-copied');
                        if (label) label.textContent = original;
                    }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, fallback);
                } else {
                    fallback();
                }
                function fallback() {
                    const ta = doc.createElement('textarea');
                    ta.value = text;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    body.appendChild(ta);
                    ta.select();
                    try { doc.execCommand('copy'); done(); } catch (_) { /* ignore */ }
                    body.removeChild(ta);
                }
            });
        }
    }

    /* ---------------- boot ---------------- */

    function init() {
        initNav();
        initPasswordFields();
        const page = body.getAttribute('data-page');
        if (page === 'login') initLogin();
        else if (page === 'register') initRegister();
        else if (page === 'success') initSuccess();
    }

    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
    else init();
})();