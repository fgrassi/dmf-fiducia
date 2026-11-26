<?php

namespace App\Actions\ClientBoletos;

use App\Enums\Alert;
use App\Models\FinClientsBoleto;
use App\Models\User;

/** clientBoleto/removeCliente (legado): remove o prazo negociado e avisa o financeiro com a justificativa. */
class DeleteClientBoleto
{
    public function __invoke(FinClientsBoleto $row, string $justification, User $user): void
    {
        $client = $row->client;

        Alert::ClientBoletoRemoved->send(
            'Remoção de Prazo Negociado de Cliente: '.$client?->nome,
            'Prazo negociado de cliente removido com sucesso.<br/>'
                .'Cliente: '.e($client?->nome).'<br/>'
                .'CNPJ: '.e($client?->cnpj).'<br/>'
                .'Justificativa: '.e($justification).'<br/>'
                .'Responsável: '.e($user->getFilamentName()),
        );

        $row->delete();
    }
}
