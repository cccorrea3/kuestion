import './bootstrap';
import { createIcons } from 'lucide';

// Bloque 2 (CSP): lucide se bundlea localmente (elimina el CDN unpkg del script-src).
// Los iconos se inicializan acá (el layout ya no tiene script inline) y en cada
// navegación Livewire (wire:navigate re-renderiza el DOM sin recargar el script).
createIcons();

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
    createIcons();
    document.getElementById('main-content')?.focus();
});
