import entryGate from './entry-gate';

// Livewire が起動する Alpine へ、画面の部品を登録する（CSP によりインラインの script を置けないため。17.7）。
document.addEventListener('alpine:init', () => {
    window.Alpine.data('entryGate', entryGate);
});
