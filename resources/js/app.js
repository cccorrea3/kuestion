import './bootstrap';

document.addEventListener('keydown', (e) => {
    const tag = e.target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (document.querySelector('[role="dialog"]')) return;

    if (e.key === 'n') {
        const link = document.querySelector('a[href*="questions/create"]');
        if (link) { e.preventDefault(); link.click(); }
    }

    if (e.key === 'Escape') {
        const cancel = document.querySelector('[wire\\:click*="set(\'confirmDelete\'"]');
        if (cancel) { e.preventDefault(); cancel.click(); }
    }

    if (e.key === 'j' || e.key === 'k') {
        const links = document.querySelectorAll('a[href*="/questions/"]:not([href*="create"]):not([href*="?"])');
        const current = document.activeElement?.closest('a');
        const idx = current ? Array.from(links).indexOf(current) : -1;
        const next = e.key === 'j' ? links[idx + 1] : links[idx - 1];
        if (next) { e.preventDefault(); next.focus(); }
    }
});

document.addEventListener('livewire:navigated', () => {
    lucide.createIcons();
    document.getElementById('main-content')?.focus();
});
