
function loadReminders() {
    fetch('fetch_reminders.php')
        .then(response => response.text())
        .then(html => {
            document.getElementById('reminder-list').innerHTML = html;
        })
        .catch(err => console.error('Error loading reminders:', err));
}

// Load reminders every 30 seconds
setInterval(loadReminders, 30000);

// Load immediately on page load
loadReminders();

// Post helper
async function postReminder(op, id) {
    const form = new FormData();
    form.append('op', op);
    form.append('id', id);
    const res = await fetch('reminders_update.php', {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
    });
    const json = await res.json();
    if (!res.ok || json.ok !== true) throw new Error(json.error || 'Update failed');
}

// One click handler for the whole reminder list
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('reminder-list');

    // Guard if element not found
    if (!container) return;

    container.addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-op]');
        if (!btn) return;

        const op = btn.getAttribute('data-op');
        const id = btn.getAttribute('data-id');
        const card = btn.closest('.notification');

        // optimistic UI
        if (op === 'mark_read' && card) card.style.opacity = '0.6';
        if ((op === 'flag' || op === 'unflag') && card) {
            const willFlag = op === 'flag';
            card.classList.toggle('flagged', willFlag);
            // change button label quickly
            btn.textContent = willFlag ? 'Unflag' : 'Flag';
            btn.setAttribute('data-op', willFlag ? 'unflag' : 'flag');
        }

        try {
            await postReminder(op, id);
            if (op === 'mark_read') {
                card?.remove();
                if (!container.querySelector('.notification')) {
                    container.innerHTML = '<div class="reminder-empty">No new reminders 🎉</div>';
                }
            } else {
                // Re-fetch to ensure flagged items jump to top in correct order
                loadReminders();
            }
        } catch (err) {
            console.error(err);
            // revert optimistic changes on failure
            if (op === 'mark_read' && card) card.style.opacity = '1';
            if ((op === 'flag' || op === 'unflag') && card) {
                const revertFlag = op === 'flag' ? false : true;
                card.classList.toggle('flagged', revertFlag);
                btn.textContent = revertFlag ? 'Unflag' : 'Flag';
                btn.setAttribute('data-op', revertFlag ? 'unflag' : 'flag');
            }
            alert('Failed to update reminder.');
        }
    });
});
