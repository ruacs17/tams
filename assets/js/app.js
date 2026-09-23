// TAMS Local JavaScript Helpers
document.addEventListener('DOMContentLoaded', () => {
    // Auto-dismiss alerts after 6 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('[class*="border-l-4"]');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 6000);
});

// Modal toggle helper
function toggleModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    if (modal.classList.contains('hidden')) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    } else {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
