// UnitSync Registration Progressive Enhancement
// Shows / hides Program and Platoon fields depending on selected Role

document.addEventListener('DOMContentLoaded', function () {
    const roleSelect = document.getElementById('role');
    const groupProgram = document.getElementById('group_program');
    const groupPlatoon = document.getElementById('group_platoon');
    const programSelect = document.getElementById('program_id');
    const platoonSelect = document.getElementById('platoon_option');

    if (!roleSelect || !groupProgram || !groupPlatoon) {
        return;
    }

    function updateRoleFields() {
        const selectedRole = roleSelect.value;

        if (selectedRole === 'class_president') {
            groupProgram.style.display = 'block';
            if (programSelect) programSelect.required = true;

            groupPlatoon.style.display = 'none';
            if (platoonSelect) {
                platoonSelect.required = false;
                platoonSelect.value = '';
            }
        } else if (selectedRole === 'platoon_leader') {
            groupProgram.style.display = 'none';
            if (programSelect) {
                programSelect.required = false;
                programSelect.value = '';
            }

            groupPlatoon.style.display = 'block';
            if (platoonSelect) platoonSelect.required = true;
        } else {
            // battalion_s1 or brigade_s1 or empty
            groupProgram.style.display = 'none';
            if (programSelect) {
                programSelect.required = false;
                programSelect.value = '';
            }

            groupPlatoon.style.display = 'none';
            if (platoonSelect) {
                platoonSelect.required = false;
                platoonSelect.value = '';
            }
        }
    }

    // Initial state setup on load
    updateRoleFields();

    // Listen for role changes
    roleSelect.addEventListener('change', updateRoleFields);
});
