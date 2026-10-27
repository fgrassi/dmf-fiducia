<?php

namespace App\Actions\ClientBoletos;

use App\Models\FinClientsBoleto;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** clientBoleto/removeCliente (legado): remove o prazo negociado e avisa o financeiro com a justificativa. */
class DeleteClientBoleto
{
    public function __invoke(FinClientsBoleto $row, string $justification, User $user): void
    {
        $client = $row->client;

        Mail::html(
            'Prazo negociado de cliente removido com sucesso.<br/>'
                .'Cliente: '.e($client?->nome).'<br/>'
                .'CNPJ: '.e($client?->cnpj).'<br/>'
                .'Justificativa: '.e($justification).'<br/>'
                .'Responsável: '.e($user->getFilamentName()),
            fn ($mail) => $mail
                ->to('financeiro@dmflog.com.br', 'Financeiro')
                ->subject('Remoção de Prazo Negociado de Cliente: '.$client?->nome),
        );

        $row->delete();
    }
}
