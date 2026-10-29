<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\OrdemPagamento;
use Illuminate\Support\Facades\DB;

/**
 * Partes que recebem o pagamento (recalcularForm, buscaPartes e buscaDadosParte do legado): para cada "Due To" com item ligado,
 * a cia aérea, o despachante, a seguradora ou a pessoa. A parte vem da última instrução ou, sem ela, da reserva. Transportadora
 * não tem CNPJ nem dados bancários no cadastro (o legado falha em silêncio), então só vale o texto digitado.
 */
class OtherOrderParties
{
    /** classe => [tabela, chave, coluna do nome] */
    private const CLASSES = [
        'CiaAerea' => ['cia_aerea', 'id', 'name'],
        'CiaSeguro' => ['cia_seguro', 'id', 'name'],
        'Despachante' => ['despachante', 'despachante_id', 'nome'],
        'Pessoa' => ['pessoa', 'pessoa_id', 'nome'],
    ];

    private const DUE_TO_CLASS = [2 => 'CiaAerea', 4 => 'Transportadora', 5 => 'Despachante', 8 => 'CiaSeguro'];

    private const ENABLED = 1;

    /**
     * Due To (com item ligado) => parte e dados atuais.
     *
     * @return array<int, array{label: string, class: string, id: ?int, name: ?string, cnpj: ?string, bank: ?string}>
     */
    public function forOrder(OrdemPagamento $order): array
    {
        $parties = [];
        $dueTos = DB::table('ordem_pagamento_item as i')
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->join('fin_expenses_values_due_to as d', 'd.id', '=', 'v.ref_due_to')
            ->where('i.ordem_pagamento_id', $order->getKey())
            ->where('i.status', self::ENABLED)
            ->orderBy('v.ref_due_to')
            ->get(['v.ref_due_to', 'd.name'])
            ->unique('ref_due_to');

        foreach ($dueTos as $dueTo) {
            $stored = $this->storedParty($order, (int) $dueTo->ref_due_to);
            $class = $stored->parte_class ?? self::DUE_TO_CLASS[(int) $dueTo->ref_due_to] ?? 'Pessoa';
            $id = $stored ? (int) $stored->parte_id : $this->bookingPartyId($order, (int) $dueTo->ref_due_to);
            $data = $id ? $this->lookup($class, $id) : null;

            $parties[(int) $dueTo->ref_due_to] = [
                'label' => (string) $dueTo->name,
                'class' => $class,
                'id' => $data ? $id : null,
                'name' => $data['name'] ?? null,
                'cnpj' => $data['cnpj'] ?? null,
                'bank' => $data['bank'] ?? null,
            ];
        }

        return $parties;
    }

    /** buscaPartes: até 30 partes da classe cujo nome tem o termo. @return array<int, string> */
    public function search(string $class, string $term): array
    {
        [$table, $key, $name] = self::CLASSES[$class] ?? self::CLASSES['Pessoa'];

        return DB::table($table)->where($name, 'like', "%{$term}%")->orderBy($name)->limit(30)->pluck($name, $key)->all();
    }

    /** buscaDadosParte. @return ?array{name: string, cnpj: ?string, bank: ?string} */
    public function lookup(string $class, int $id): ?array
    {
        [$table, $key, $name] = self::CLASSES[$class] ?? self::CLASSES['Pessoa'];
        $row = DB::table($table)->where($key, $id)->first([$name, 'cnpj', 'dados_bancarios']);

        return $row ? ['name' => (string) $row->{$name}, 'cnpj' => $row->cnpj, 'bank' => $row->dados_bancarios] : null;
    }

    /** atualizaCadastroParte: grava CNPJ e dados bancários digitados no cadastro da parte. */
    public function updateRegistry(string $class, int $id, ?string $cnpj, ?string $bank): void
    {
        if (! isset(self::CLASSES[$class])) {
            return;
        }

        [$table, $key] = self::CLASSES[$class];
        DB::table($table)->where($key, $id)->update(['cnpj' => $cnpj, 'dados_bancarios' => $bank]);
    }

    /** buscaInstrucaoPagamentoPorDueTo: a instrução da ordem que já tem item deste Due To. */
    public function instructionId(OrdemPagamento $order, int $dueTo): ?int
    {
        $id = DB::table('instrucao_pagamento_outros as ip')
            ->join('instrucao_pagamento_item_outros as ipi', 'ipi.instrucao_pagamento_outros_id', '=', 'ip.instrucao_pagamento_outros_id')
            ->join('ordem_pagamento_item as i', 'i.ordem_pagamento_item_id', '=', 'ipi.ordem_pagamento_item_id')
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where('ip.ordem_pagamento_id', $order->getKey())
            ->where('v.ref_due_to', $dueTo)
            ->orderBy('ip.instrucao_pagamento_outros_id')
            ->value('ip.instrucao_pagamento_outros_id');

        return $id ? (int) $id : null;
    }

    /** salvaParteInstrucao: um vínculo por instrução e classe. */
    public function linkToInstruction(int $instructionId, int $partyId, string $class): void
    {
        $link = DB::table('instrucao_pagamento_parte')->where('instrucao_pagamento_outros_id', $instructionId)->where('parte_class', $class);

        $link->exists()
            ? $link->update(['parte_id' => $partyId])
            : DB::table('instrucao_pagamento_parte')->insert(['instrucao_pagamento_outros_id' => $instructionId, 'parte_id' => $partyId, 'parte_class' => $class, 'created_at' => now()]);
    }

    private function storedParty(OrdemPagamento $order, int $dueTo): ?object
    {
        $instruction = $this->instructionId($order, $dueTo);

        return $instruction ? DB::table('instrucao_pagamento_parte')->where('instrucao_pagamento_outros_id', $instruction)->orderBy('instrucao_pagamento_parte_id')->first() : null;
    }

    /** Sem instrução: cia aérea, despachante e seguradora da própria reserva. */
    private function bookingPartyId(OrdemPagamento $order, int $dueTo): ?int
    {
        $booking = DB::table('embarque')->where('embarque_id', $order->ref_embarque)->first();

        $id = match ($dueTo) {
            2 => $booking?->ref_cia_aerea,
            5 => $booking?->despachante_id,
            8 => DB::table('insurance_confirmation')->where('ref_embarque', $order->ref_embarque)->orderBy('id')->value('ref_cia_seguro'),
            default => null,
        };

        return $id ? (int) $id : null;
    }
}
