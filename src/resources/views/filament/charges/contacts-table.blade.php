{{-- Contatos que recebem a cobrança. --}}
@php($contacts = $getState() ?? [])
@if (count($contacts))
    <div style="overflow-x: auto">
        <table class="charge-items">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Tipo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($contacts as $contact)
                    <tr>
                        <td>{{ $contact['name'] }}</td>
                        <td>{{ $contact['email'] }}</td>
                        <td>{{ $contact['type'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p style="font-size: 0.875rem; opacity: 0.7">Nenhum contato.</p>
@endif
