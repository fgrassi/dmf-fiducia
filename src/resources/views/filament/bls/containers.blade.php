@php
    $containers = $this->data['containers'] ?? [];
    $types = \Illuminate\Support\Facades\DB::table('tipo_container')->whereIn('tipo_container_id', collect($containers)->pluck('tipo_container_id')->filter())->pluck('nome', 'tipo_container_id');
    $number = fn ($value) => \App\Actions\Booking\Bls\BlContainerRules::number($value);
@endphp

<div class="overflow-y-auto rounded-lg border border-gray-200 dark:border-white/10" style="max-height: 20rem">
    <table class="w-full divide-y divide-gray-200 text-center text-sm dark:divide-white/10">
        <thead class="sticky top-0 bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:bg-gray-800 dark:text-gray-300">
            <tr>
                <th class="px-3 py-2">Type/Size</th>
                <th class="px-3 py-2">No.</th>
                <th class="px-3 py-2">Tara</th>
                <th class="px-3 py-2">Seals</th>
                <th class="px-3 py-2">Qty.</th>
                <th class="px-3 py-2">Gross</th>
                <th class="px-3 py-2">Net</th>
                <th class="px-3 py-2">Volume</th>
                @if ($export)
                    <th class="px-3 py-2">Temperatura</th>
                @endif
                <th class="px-3 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($containers as $key => $container)
                <tr wire:key="container-{{ $key }}" @class(['bg-primary-50 dark:bg-primary-500/10' => ($this->data['container_editing'] ?? null) === (string) $key])>
                    <td class="px-3 py-2">{{ $types[$container['tipo_container_id'] ?? null] ?? '' }}</td>
                    <td class="px-3 py-2">{{ $container['numero'] ?? '' }}</td>
                    <td class="px-3 py-2">{{ $container['tara'] ?? '' }}</td>
                    <td class="px-3 py-2">{{ collect([$container['seals_n1'] ?? null, $container['seals_n2'] ?? null, $container['seals_n3'] ?? null])->filter()->implode(' | ') }}</td>
                    <td class="px-3 py-2">{{ $container['qty_pkgs'] ?? '' }}</td>
                    <td class="px-3 py-2">{{ $container['gross_weight'] ?? '' }}</td>
                    <td class="px-3 py-2">{{ $container['net_weight'] ?? '' }}</td>
                    <td class="px-3 py-2">{{ $container['volume'] ?? '' }}</td>
                    @if ($export)
                        <td class="px-3 py-2">{{ \App\Models\Container::isReefer($container['tipo_container_id'] ?? null) ? ($container['temperatura'] ?? '') : '-' }}</td>
                    @endif
                    <td class="whitespace-nowrap px-3 py-2">
                        <x-filament::icon-button icon="heroicon-o-pencil-square" size="sm" label="Editar" wire:click="editContainer('{{ $key }}')" />
                        <x-filament::icon-button icon="heroicon-o-square-2-stack" size="sm" color="gray" label="Copiar" wire:click="copyContainer('{{ $key }}')" />
                        <x-filament::icon-button icon="heroicon-o-trash" size="sm" color="danger" label="Excluir" wire:click="removeContainer('{{ $key }}')" wire:confirm="Tem certeza de que deseja remover este equipamento?" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $export ? 10 : 9 }}" class="px-3 py-4 text-gray-500 dark:text-gray-400">Nenhum container lançado</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
