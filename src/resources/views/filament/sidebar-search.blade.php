{{-- Filtra o menu lateral pelo texto. Grupos com resultado abrem durante a busca; o estado de colapso original volta ao limpar/sair. --}}
<div
    x-data="{
        q: '',
        saved: null,
        filter() {
            const nav = this.$root.closest('nav')
            const store = Alpine.store('sidebar')
            const term = this.q.trim().toLowerCase()
            const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            const needle = norm(term)

            if (term && this.saved === null) this.saved = [...store.collapsedGroups]

            nav.querySelectorAll('li.fi-sidebar-item').forEach((li) => {
                const text = norm(li.querySelector(':scope > a')?.textContent ?? '')
                li.style.display = ! needle || text.includes(needle) ? '' : 'none'
            })

            const open = []
            nav.querySelectorAll('li.fi-sidebar-group').forEach((group) => {
                const hasMatch = [...group.querySelectorAll('li.fi-sidebar-item')].some((li) => li.style.display !== 'none')
                group.style.display = ! needle || hasMatch ? '' : 'none'
                if (needle && hasMatch) open.push(group.dataset.groupLabel)
            })

            if (needle) {
                store.collapsedGroups = this.saved.filter((g) => ! open.includes(g))
            } else {
                this.reset()
            }
        },
        reset() {
            if (this.saved !== null) Alpine.store('sidebar').collapsedGroups = this.saved
            this.saved = null
        },
    }"
    x-show="$store.sidebar.isOpen"
    x-on:pagehide.window="reset()"
    class="px-4 pb-2"
>
    <x-filament::input.wrapper inline-prefix prefix-icon="heroicon-m-magnifying-glass">
        <x-filament::input
            type="search"
            placeholder="Pesquisar no menu..."
            autocomplete="off"
            x-model="q"
            x-on:input="filter()"
            x-on:keydown.escape="q = ''; filter()"
        />
    </x-filament::input.wrapper>
</div>
