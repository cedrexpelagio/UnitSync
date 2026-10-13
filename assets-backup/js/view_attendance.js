// UnitSync: S1 View Attendance JS (Stage 2 Step C & Stage 3)
document.addEventListener('DOMContentLoaded', function () {
    const filterForm    = document.getElementById('attendance-filter-form');
    const companySelect = document.getElementById('company_id');
    const platoonSelect = document.getElementById('platoon_id');
    const programSelect = document.getElementById('program_id');
    const sessionSelect = document.getElementById('session_id');
    const statusSelect  = document.getElementById('status');
    const formulaBtn    = document.getElementById('matrix-formula-info');
    const exportBtn     = document.getElementById('export-csv-btn');

    // 1. Dependent Platoon Dropdown
    if (companySelect && platoonSelect) {
        // Cache original platoon options
        const originalOptions = Array.from(platoonSelect.options);

        function updatePlatoons() {
            const selectedCompany = companySelect.value;
            const currentPlatoonVal = platoonSelect.value;

            // Clear options
            platoonSelect.innerHTML = '';

            originalOptions.forEach(opt => {
                const optCompany = opt.getAttribute('data-company');
                const isSpecial = opt.value === '' || opt.value === 'unassigned';

                if (selectedCompany === '' || isSpecial || optCompany === selectedCompany) {
                    platoonSelect.appendChild(opt.cloneNode(true));
                }
            });

            // Restore selection if still valid
            const exists = Array.from(platoonSelect.options).some(o => o.value === currentPlatoonVal);
            if (exists) {
                platoonSelect.value = currentPlatoonVal;
            } else {
                platoonSelect.value = '';
            }
        }

        // Run on initial load and on change
        updatePlatoons();
        companySelect.addEventListener('change', function () {
            updatePlatoons();
            if (filterForm) filterForm.submit();
        });
    }

    // 2. Auto-submit filters on change
    [platoonSelect, programSelect, sessionSelect, statusSelect].forEach(sel => {
        if (sel && filterForm) {
            sel.addEventListener('change', function () {
                filterForm.submit();
            });
        }
    });

    // 3. Formula Info Button popover/alert
    if (formulaBtn) {
        formulaBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const formulaNotice = document.querySelector('.formula-banner');
            if (formulaNotice) {
                formulaNotice.scrollIntoView({ behavior: 'smooth', block: 'center' });
                formulaNotice.style.transition = 'background-color 0.3s ease';
                formulaNotice.style.backgroundColor = 'var(--gold-100)';
                setTimeout(() => {
                    formulaNotice.style.backgroundColor = '';
                }, 1500);
            }
        });
    }

    // 4. Export CSV button feedback
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            if (typeof showToast === 'function') {
                showToast('Preparing CSV export download...', 'info');
            }
        });
    }
});
