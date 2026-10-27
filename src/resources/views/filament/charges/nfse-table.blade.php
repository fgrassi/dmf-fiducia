{{-- Histórico de NFS-e da cobrança, com download do XML/DANFSe e atualização de status por nota. --}}
@php($nfses = $getState() ?? [])
@php($colors = ['Autorizada' => 'success', 'Rejeitada' => 'danger', 'Erro' => 'danger', 'Cancelada' => 'gray', 'Substituída' => 'info'])
@if (count($nfses))
    <div style="overflow-x: auto">
        <table class="charge-items">
            <thead>
                <tr>
                    <th>Id externo</th>
                    <!-- <th>Chave de acesso</th> -->
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($nfses as $nfse)
                    <tr>
                        <td style="overflow-wrap: anywhere;">
                            <div>{{ $nfse['external_id'] }}</div>

                            <div title="Chave de acesso">
                                <small>
                                {{ $nfse['key'] ?? '—' }}
                                </small>
                            </div>
                        </td>
                        <td class="num">{{ $nfse['value'] }}</td>
                        <td class="center">
                            <x-filament::badge :color="$colors[$nfse['status']] ?? 'warning'">{{ $nfse['status'] }}</x-filament::badge>
                        </td>
                        <td class="center">{{ $nfse['date'] }}</td>
                        <td class="center" style="white-space: nowrap">
                            <div style="display: inline-flex; align-items: center; gap: 6px;">

                                @if ($nfse['danfse'])
                                    <x-filament::icon-button tag="a" :href="$nfse['danfse']" target="_blank" icon="heroicon-o-globe-alt" size="sm" label="Consultar / DANFSe" />
                                @endif
                                @if ($nfse['can_download'])
                                    <x-filament::icon-button icon="heroicon-o-document-arrow-down" size="sm" label="Baixar PDF do DANFSe"
                                        wire:click="mountAction('baixarPdfNfse', { id: {{ $nfse['id'] }} })" />
                                    <x-filament::icon-button icon="heroicon-o-code-bracket" size="sm" label="Baixar XML"
                                        wire:click="mountAction('baixarXmlNfse', { id: {{ $nfse['id'] }} })" />
                                @endif
                                @if ($nfse['can_cancel'])
                                    <x-filament::icon-button icon="heroicon-o-x-circle" color="danger" size="sm" label="Cancelar NFS-e"
                                        wire:click="mountAction('cancelarNfse', { id: {{ $nfse['id'] }} })" />
                                @endif
                                @if ($nfse['can_substitute'])
                                    <x-filament::icon-button icon="heroicon-o-arrows-right-left" color="warning" size="sm" label="Substituir NFS-e"
                                        wire:click="mountAction('substituirNfse', { id: {{ $nfse['id'] }} })" />
                                @endif
                                @if ($nfse['can_reprocess'])
                                    <x-filament::icon-button icon="heroicon-o-arrow-uturn-right" color="warning" size="sm" label="Reprocessar NFS-e"
                                        wire:click="mountAction('reprocessarNfse', { id: {{ $nfse['id'] }} })" />
                                @endif
                                @if ($nfse['can_refresh'])
                                    <x-filament::icon-button icon="heroicon-o-arrow-path" size="sm" label="Atualizar status"
                                        wire:click="mountAction('atualizarStatusNfse', { id: {{ $nfse['id'] }} })" />
                                @endif
                            </div>
                            </td>
                    </tr>
                    @if (count($nfse['notes']))
                        <tr>
                            <td colspan="6" style="color: rgb(var(--danger-600)); font-size: 0.8rem">{!! nl2br(e(implode("\n", $nfse['notes']))) !!}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p style="font-size: 0.875rem; opacity: 0.7">Nenhuma NFSe emitida para esta cobrança.</p>
@endif
