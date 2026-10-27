{{-- Containers do embarque (ou do BL da cobrança). --}}
@php($containers = $getState() ?? [])
@if (count($containers))
    <div style="overflow-x: auto">
        <table class="charge-items">
            <thead>
                <tr>
                    <th>Equipamento</th>
                    <th>Número</th>
                    <th>Quantidade</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($containers as $container)
                    <tr>
                        <td>{{ $container['type'] }}</td>
                        <td>{{ $container['number'] }}</td>
                        <td class="num">{{ $container['factor'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p style="font-size: 0.875rem; opacity: 0.7">Nenhum container.</p>
@endif
