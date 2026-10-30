<x-filament::tabs label="Tipo de capa">
    <x-filament::tabs.item tag="a" :active="$active === 'normal'" :href="$normalUrl">
        Capas normais
    </x-filament::tabs.item>
    <x-filament::tabs.item tag="a" :active="$active === 'manual'" :href="$manualUrl">
        Capas manuais
    </x-filament::tabs.item>
</x-filament::tabs>
