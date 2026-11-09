<div class="overflow-x-auto">
    <table class="w-full text-sm text-left">
        <thead>
            <tr class="border-b font-semibold">
                <th class="p-2">Cliente</th>
                <th class="p-2">Agente</th>
                <th class="p-2">Reserva</th>
                <th class="p-2">Escritório</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-b">
                    <td class="p-2">{{ $row->cliente }}</td>
                    <td class="p-2">{{ $row->agente }}</td>
                    <td class="p-2">{{ $row->reserva }}</td>
                    <td class="p-2">{{ $row->escritorio }}</td>
                </tr>
            @empty
                <tr>
                    <td class="p-2" colspan="4">Nenhum pagamento encontrado.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
