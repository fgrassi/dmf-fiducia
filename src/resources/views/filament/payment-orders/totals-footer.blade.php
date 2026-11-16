{{--
    Totais do resumo da ordem, dentro da própria tabela (rodapé). O Filament já abre um <tr>, então a view fecha e abre as demais.
    $valueColumn = posição (1-based) da coluna do valor do item; o rótulo ocupa as colunas antes dela e o resto vai até as ações.
--}}
@php($trailing = count($columns) - $valueColumn + 1)
@foreach ([['Subtotal', $subtotal, false], ['Despesas Financeiras/IOF', $iof, true], ['Total', $total, false]] as [$label, $value, $editable])
    <th colspan="{{ $valueColumn - 1 }}" style="padding: 0.5rem 1rem; text-align: left; font-weight: 600">{{ $label }}</th>
    <td style="padding: 0.5rem 1rem; white-space: nowrap; {{ $label === 'Total' ? 'font-weight: 700' : '' }}">
        <div style="display: flex; align-items: center; gap: 0.25rem">
            {{ $value }}
            @if ($editable)
                <x-filament::icon-button
                    icon="heroicon-o-pencil-square"
                    size="sm"
                    label="Alterar IOF"
                    tooltip="Alterar Despesas Financeiras/IOF"
                    wire:click="mountAction('alterarIof')"
                />
            @endif
        </div>
    </td>
    <td colspan="{{ $trailing }}"></td>
    @if (! $loop->last)
        </tr><tr>
    @endif
@endforeach
