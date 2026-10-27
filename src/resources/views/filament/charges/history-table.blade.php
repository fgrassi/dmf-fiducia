{{-- Histórico da cobrança --}}
@if (!empty($getState()))
    <div style="overflow-x: auto">
        <table class="charge-items">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Evento</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Detalhe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($getState() as $record)
                    <tr>
                        <td>{{ $record['date'] ?? '-' }}</td>
                        <td>{{ $record['event'] ?? '-' }}</td>
                        <td>{{ $record['user'] ?? '-' }}</td>
                        <td>{{ $record['status'] ?? '-' }}</td>
                        <td>{{ $record['detail'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p style="font-size: 0.875rem; opacity: 0.7">
        Nenhum histórico encontrado.
    </p>
@endif