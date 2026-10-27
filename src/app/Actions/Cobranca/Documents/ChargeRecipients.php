<?php

namespace App\Actions\Cobranca\Documents;

use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Facades\DB;

/**
 * Destinatários dos e-mails de cobrança ao cliente (Cobranca::listarDestinatariosCobranca + addContatosCobrancaToMail):
 * contatos de cobrança do cliente, contatos da reserva e, se a IC tiver seleção (emails_envio_json), só os selecionados.
 * cobranca@dmflog.com.br entra por quem envia.
 */
class ChargeRecipients
{
    public const COLLECTION_EMAIL = 'cobranca@dmflog.com.br';

    private const DEMURRAGE_EXPENSES = [152, 211, 123, 428, 494, 548, 613];

    private const SOPRANO_CLIENTS = [1536, 1921, 2164, 2969, 4477, 6387, 7758];

    public function __construct(private ChargeDetails $details) {}

    /** @param  bool  $all  ignora a seleção (lista para escolher os e-mails) @return array<string, string> e-mail => nome */
    public function __invoke(FinCharges $charge, bool $all = false): array
    {
        $e = $charge->embarque;
        $list = [];
        $add = function (?string $name, ?string $email) use (&$list) {
            $email = strtolower(trim((string) $email));

            if (strlen($email) > 5 && ! isset($list[$email])) {
                $list[$email] = trim((string) $name);
            }
        };

        foreach (DB::table('fin_contato_cobranca')->where('ref_pessoa', $e->cliente_id)->pluck('email') as $email) {
            $add('Contato', $email);
        }

        $soprano = in_array((int) $e->cliente_id, self::SOPRANO_CLIENTS, true);
        $demurrage = $soprano && DB::table('fin_charges_items as i')->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where('i.ref_charge', $charge->id)->whereIn('v.ref_expense', self::DEMURRAGE_EXPENSES)->exists();

        foreach ($this->details->contacts($charge) as $contact) {
            // Soprano com demurrage: o despachante do cliente não recebe
            if (! ($demurrage && $contact['type'] === 'Despachante (Cliente)')) {
                $add($contact['name'], $contact['email']);
            }
        }

        if ($soprano) {
            $add('', 'fernandafogaca@soprano.com.br');
        }

        if ($e->tipo_embarque === 'h') {
            foreach (DB::table('contato_reserva')->where('ref_embarque', $e->embarque_id)->get(['nome', 'email']) as $c) {
                $add($c->nome, $c->email);
            }
        }

        $allow = json_decode((string) $charge->emails_envio_json, true);

        if (! $all && is_array($allow)) {
            $list = array_intersect_key($list, array_flip(array_map(fn ($m) => strtolower(trim((string) $m)), $allow)));
        }

        return $list;
    }
}
