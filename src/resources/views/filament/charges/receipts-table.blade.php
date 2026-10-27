{{-- Recibos (cobrança paga): PDF e valor editável por recibo (editValorRecibo do legado). --}}
@php($receipts = $getState() ?? [])
@if (count($receipts))
    <div style="overflow-x: auto">
        <table class="charge-items">
            <thead>
                <tr>
                    <th>Arquivo</th>
                    <th>Ações</th>
                    <th>Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($receipts as $receipt)
                    <tr>
                        <td>
                            @if ($receipt['url'])
                                <x-filament::link :href="$receipt['url']" target="_blank" icon="heroicon-o-document-arrow-down">{{ $receipt['file'] }}</x-filament::link>
                            @else
                                {{ $receipt['file'] ?? '—' }}
                            @endif
                        </td>
                        <td class="center" style="white-space: nowrap">
                            <div style="display: inline-flex; align-items: center; gap: 4px;">
                                <x-filament::icon-button
                                    icon="heroicon-o-arrow-path"
                                    size="sm"
                                    label="Substituir recibo"
                                    wire:click="mountAction('substituirRecibo', { id: {{ $receipt['id'] }} })"
                                />

                                <x-filament::icon-button
                                    icon="heroicon-o-envelope"
                                    size="sm"
                                    label="Reenviar recibo"
                                    wire:click="mountAction('reenviarRecibo', { id: {{ $receipt['id'] }} })"
                                />

                                <x-filament::icon-button
                                    icon="heroicon-o-document-text"
                                    size="sm"
                                    label="Recibo em PDF sem fundo"
                                    wire:click="mountAction('reciboSemFundo', { id: {{ $receipt['id'] }} })"
                                />

                                <x-filament::icon-button
                                    icon="heroicon-o-table-cells"
                                    size="sm"
                                    label="Nota de Débito"
                                    wire:click="mountAction('notaDebitoRecibo', { id: {{ $receipt['id'] }} })"
                                />
                            </div>
                        </td>
                        <td class="num">
                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                <span>{{ $receipt['value'] ?? 'N/D' }}</span>

                                <x-filament::icon-button
                                    icon="heroicon-o-pencil-square"
                                    size="sm"
                                    label="Alterar valor do recibo"
                                    wire:click="mountAction('editarValorRecibo', { id: {{ $receipt['id'] }} })"
                                />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p style="font-size: 0.875rem; opacity: 0.7">Nenhum recibo.</p>
@endif
