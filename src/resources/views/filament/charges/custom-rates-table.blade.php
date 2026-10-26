{{-- Cotações Negociadas (listaCustom do legado), com remoção por linha. --}}
@php($rates = $getState() ?? [])
@if (count($rates))
    <div style="overflow-x: auto">
        <table class="charge-items">
            <thead>
                <tr>
                    <th>Moeda</th>
                    <th>Valor</th>
                    <th>Registrado por</th>
                    <th>Data</th>
                    <th>Observação</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rates as $rate)
                    <tr>
                        <td>{{ $rate['currency'] }}</td>
                        <td class="num">{{ $rate['rate'] }}</td>
                        <td>{{ $rate['user'] }}</td>
                        <td class="center">{{ $rate['date'] }}</td>
                        <td>{{ $rate['note'] }}</td>
                        <td class="center">
                            <x-filament::icon-button
                                icon="heroicon-o-trash"
                                color="danger"
                                size="sm"
                                label="Remover cotação negociada"
                                wire:click="mountAction('removerCotacao', { id: {{ $rate['id'] }} })"
                            />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p style="font-size: 0.875rem; opacity: 0.7">Nenhuma cotação negociada.</p>
@endif
