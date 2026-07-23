document.addEventListener('livewire:init', () => {
    const lastErrors = new Map();

    Livewire.hook('commit', ({ component, succeed }) => {
        succeed(({ snapshot }) => {
            // The commit's `succeed` callback receives the raw response, where
            // `snapshot` is still a JSON-encoded string (unlike the parsed
            // object Livewire's own internals use elsewhere) — must decode it
            // ourselves before reading `memo.errors`.
            const parsed = typeof snapshot === 'string' ? JSON.parse(snapshot) : snapshot;
            const errors = parsed?.memo?.errors ?? {};
            const serialized = JSON.stringify(errors);
            const isEmpty = serialized === '{}' || serialized === '[]';
            const isNew = !isEmpty && serialized !== lastErrors.get(component.id);
            lastErrors.set(component.id, serialized);

            if (isNew) {
                Object.values(errors).flat().forEach((message) => {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type: 'error' } }));
                });
            }
        });
    });
});
