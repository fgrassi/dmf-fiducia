@php
    $fmt = fn ($value) => filled($value) ? $value : '-';
    $number = fn ($value) => filled($value) && (float) $value != 0 ? number_format((float) $value, 3, ',', '.') : '-';
    $field = fn (string $label, $value) => '<div class="mb-2"><div class="text-xs font-semibold uppercase text-gray-500">'.e($label).'</div><div class="whitespace-pre-wrap break-words">'.e($fmt($value)).'</div></div>';
@endphp

<details class="mt-1 rounded-lg border border-gray-200 p-2 text-sm dark:border-white/10">
    <summary class="cursor-pointer font-medium text-primary-600">Visualizar</summary>

    <div class="mt-3 space-y-4">
        <div class="grid gap-x-6 sm:grid-cols-2">
            <div>
                <div class="mb-1 border-t pt-1 text-xs font-bold uppercase text-gray-600">Identificação</div>
                {!! $field('Protocolo', $draft['protocolo']) !!}
                {!! $field('Booking', $draft['booking']) !!}
                {!! $field('Data de Envio', $draft['send_at']) !!}
            </div>
            <div>
                {!! $field('Navio / Viagem', $draft['vessel']) !!}
                {!! $field('Porto de Embarque', $draft['port_lading']) !!}
                {!! $field('Porto de Descarga', $draft['port_disc']) !!}
                {!! $field('Local de Entrega', $draft['place_delivery']) !!}
                {!! $field('Destino Final', $draft['final_destination']) !!}
            </div>
        </div>

        <div class="grid gap-x-6 sm:grid-cols-2">
            <div>
                <div class="mb-1 border-t pt-1 text-xs font-bold uppercase text-gray-600">Partes</div>
                {!! $field('Shipper', $draft['shipper']) !!}
                {!! $field('Notify', $draft['notify']) !!}
            </div>
            <div>
                {!! $field('Consignee', $draft['consignee']) !!}
                {!! $field('Also Notify', $draft['also_notify']) !!}
            </div>
        </div>

        <div class="grid gap-x-6 sm:grid-cols-2">
            <div>
                <div class="mb-1 border-t pt-1 text-xs font-bold uppercase text-gray-600">Carga</div>
                {!! $field('Marcas', $draft['mark_nos']) !!}
            </div>
            <div>
                <div class="mb-1 pt-1 text-xs">&nbsp;</div>
                {!! $field('Descrição', $draft['desc_pkgs']) !!}
            </div>
        </div>

        <div class="grid gap-x-6 sm:grid-cols-2">
            <div>
                <div class="mb-1 border-t pt-1 text-xs font-bold uppercase text-gray-600">Condições</div>
                {!! $field('Frete', $draft['freight_at']) !!}
                {!! $field('Frete as per agreement', $draft['per_agreement']) !!}
            </div>
            <div>
                <div class="mb-1 pt-1 text-xs">&nbsp;</div>
                {!! $field('Tipo de Instrução', $draft['instruction_type']) !!}
                {!! $field('Embalagem de Madeira', $draft['tipo_madeira']) !!}
            </div>
        </div>

        <div>
            <div class="mb-1 border-t pt-1 text-xs font-bold uppercase text-gray-600">Observações</div>
            {!! $field('', $draft['observacao']) !!}
        </div>

        <div>
            <div class="mb-1 border-t pt-1 text-xs font-bold uppercase text-gray-600">Equipamentos</div>

            @if ($equipments)
                <div class="overflow-x-auto rounded border border-gray-200 dark:border-white/10">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-100 text-left dark:bg-gray-800">
                            <tr>
                                @foreach (['Tipo', 'Container', 'Tara', 'Lacres', 'Qtd', 'GW', 'NW', 'M³', 'VGM Nº', 'Peso VGM', 'Método'] as $heading)
                                    <th class="whitespace-nowrap px-2 py-1">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($equipments as $e)
                                <tr>
                                    <td class="px-2 py-1">{{ $fmt($e['type']) }}</td>
                                    <td class="px-2 py-1">{{ $fmt($e['number']) }}</td>
                                    <td class="px-2 py-1">{{ $fmt($e['tare']) }}</td>
                                    <td class="px-2 py-1">{{ $fmt($e['seals']) }}</td>
                                    <td class="px-2 py-1 text-right">{{ $e['quantity'] ?: '-' }}</td>
                                    <td class="px-2 py-1 text-right">{{ $number($e['gross_weight']) }}</td>
                                    <td class="px-2 py-1 text-right">{{ $number($e['net_weight']) }}</td>
                                    <td class="px-2 py-1 text-right">{{ $number($e['cubic_meters']) }}</td>
                                    <td class="px-2 py-1">{{ $fmt($e['vgm_number']) }}</td>
                                    <td class="px-2 py-1 text-right">{{ $number($e['vgm_weight']) }}</td>
                                    <td class="px-2 py-1">{{ $fmt($e['method']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-gray-50 font-semibold dark:bg-gray-800">
                                <td colspan="4" class="px-2 py-1 text-right">Totais</td>
                                <td class="px-2 py-1 text-right">{{ $totals['quantity'] }}</td>
                                <td class="px-2 py-1 text-right">{{ $number($totals['gross_weight']) }}</td>
                                <td class="px-2 py-1 text-right">{{ $number($totals['net_weight']) }}</td>
                                <td class="px-2 py-1 text-right">{{ $number($totals['cubic_meters']) }}</td>
                                <td></td>
                                <td class="px-2 py-1 text-right">{{ $number($totals['vgm_weight']) }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-1 text-xs text-gray-500"><strong>Método 1</strong> – Container pesado após a estufagem | <strong>Método 2</strong> – Peso Bruto da Carga + Tara do container</p>
            @else
                <p>Nenhum equipamento encontrado.</p>
            @endif
        </div>

        <p class="text-xs text-gray-500">Criado em: {{ $fmt($draft['created_at']) }}</p>
    </div>
</details>
