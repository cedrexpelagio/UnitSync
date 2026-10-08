// UnitSync Registration Progressive Enhancement
// - Shows the Program / Platoon field that matches the selected role card
// - Live password checklist, strength meter and "passwords match" hint
// - Submit button loading state (prevents double submits)
// - Moves focus to the error summary after a failed submit
// (Password show/hide is handled by ui.js.)

// Mark the page as "JS is running". The CSS only hides the role-specific
// fields once this class exists, so if this file ever fails to load, both
// fields simply stay visible and the form still works.
document.documentElement.classList.add('js');

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('registerForm');
    if (!form) return;

    // ---------- Role -> extra fields ----------
    var roleRadios    = form.querySelectorAll('input[name="role"]');
    var groupProgram  = document.getElementById('group_program');
    var groupPlatoon  = document.getElementById('group_platoon');
    var programSelect = document.getElementById('program_id');
    var platoonSelect = document.getElementById('platoon_option');

    function selectedRole() {
        var checked = form.querySelector('input[name="role"]:checked');
        return checked ? checked.value : '';
    }

    function toggleGroup(group, select, show) {
        if (!group) return;
        group.classList.toggle('is-visible', show);
        if (select) {
            select.required = show;
            if (!show) select.value = '';
        }
    }

    function updateRoleFields() {
        var role = selectedRole();
        toggleGroup(groupProgram, programSelect, role === 'class_president');
        toggleGroup(groupPlatoon, platoonSelect, role === 'platoon_leader');
    }

    roleRadios.forEach(function (r) {
        r.addEventListener('change', function () {
            updateRoleFields();
            // clear the red outline on the cards once a choice is made
            var cards = document.getElementById('role_group');
            if (cards) cards.classList.remove('is-invalid');
        });
    });
    updateRoleFields();

    // ---------- Password checklist + strength ----------
    var pw       = document.getElementById('password');
    var confirmPw = document.getElementById('confirm_password');
    var rules    = document.querySelectorAll('#pw_rules li');
    var meter    = document.getElementById('pw_meter');
    var meterLbl = document.getElementById('pw_meter_label');
    var matchEl  = document.getElementById('confirm_hint');

    var tests = {
        length: function (v) { return v.length >= 10; },
        upper:  function (v) { return /[A-Z]/.test(v); },
        lower:  function (v) { return /[a-z]/.test(v); },
        number: function (v) { return /[0-9]/.test(v); }
    };

    // 1 Weak, 2 Fair, 3 Good (all rules met), 4 Strong (all rules met + extra)
    function strengthLevel(v, met, total) {
        if (v.length === 0) return 0;
        if (met < total) return met <= 1 ? 1 : 2;
        var extra = v.length >= 14 || /[^A-Za-z0-9]/.test(v);
        return extra ? 4 : 3;
    }

    var levelText = {
        0: '',
        1: 'Password strength: Weak',
        2: 'Password strength: Fair \u2013 keep going',
        3: 'Password strength: Good \u2013 meets all requirements',
        4: 'Password strength: Strong'
    };

    function updatePassword() {
        if (!pw) return;
        var v = pw.value, met = 0, total = rules.length;
        rules.forEach(function (li) {
            var test = tests[li.getAttribute('data-rule')];
            var ok = test ? test(v) : false;
            li.classList.toggle('is-met', ok);
            if (ok) met++;
        });
        var level = strengthLevel(v, met, total);
        if (meter) meter.setAttribute('data-level', level);
        if (meterLbl) meterLbl.textContent = levelText[level];
        updateMatch();
    }

    function updateMatch() {
        if (!confirmPw || !matchEl || !pw) return;
        if (confirmPw.value === '') {
            matchEl.textContent = '';
            matchEl.className = 'reg-match';
        } else if (confirmPw.value === pw.value) {
            matchEl.textContent = 'Passwords match';
            matchEl.className = 'reg-match ok';
        } else {
            matchEl.textContent = 'Passwords do not match yet';
            matchEl.className = 'reg-match bad';
        }
    }

    if (pw)        pw.addEventListener('input', updatePassword);
    if (confirmPw) confirmPw.addEventListener('input', updateMatch);
    updatePassword();

    // ---------- Clear a field's error styling as soon as the user edits it ----------
    form.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('is-invalid')) {
            e.target.classList.remove('is-invalid');
        }
    });

    // ---------- Submit loading state ----------
    var submitBtn = document.getElementById('registerSubmit');
    form.addEventListener('submit', function () {
        if (!submitBtn) return;
        submitBtn.classList.add('is-loading');
        submitBtn.setAttribute('aria-busy', 'true');
        submitBtn.textContent = 'Submitting\u2026';
    });

    // Back/forward cache: never leave the button stuck on "Submitting..."
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && submitBtn) {
            submitBtn.classList.remove('is-loading');
            submitBtn.removeAttribute('aria-busy');
            submitBtn.textContent = 'Submit Registration';
        }
    });

    // ---------- After a failed submit: focus the summary ----------
    var summary = document.getElementById('regSummary');
    if (summary) {
        summary.focus();
        summary.scrollIntoView({ block: 'center' });
    }
});