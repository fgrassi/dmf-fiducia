<?php

namespace App\Services\Cobranca;

use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;

/**
 * Dados de apresentação da página da cobrança (showSuccess + executeShow do legado). Só leitura.
 * Devolve arrays prontos para os componentes do Filament.
 */
class ChargeDetails
{
    private const LOG_ACTIONS = [
        'criacao' => 'Criação da IC', 'recalculo' => 'Recálculo', 'baixa_bancaria' => 'Baixa bancária', 'baixa_boleto' => 'Baixa por boleto',
        'baixa_manual' => 'Baixa manual', 'remocao_baixa' => 'Remoção de baixa', 'desconto' => 'Desconto', 'edit_iof' => 'Alteração de IOF',
        'edit_due_date' => 'Alteração de vencimento', 'associacao_nf' => 'Associação de NF', 'exclusao' => 'Exclusão da IC',
        'alteracao' => 'Alteração', 'substituicao_nfse' => 'Substituição de NFS-e', 'exclusao_despesa' => 'Exclusão de despesa(s)',
    ];

    private const LOG_STATUS = [0 => 'Em aberto', 1 => 'Baixa bancária', 2 => 'Baixa por boleto', 3 => 'Baixa manual'];

    private const NFSE_LABELS = [
        'autorizada' => 'Autorizada', 'enfileirada' => 'Enfileirada', 'processando' => 'Processando', 'rejeitada' => 'Rejeitada',
        'erro_http' => 'Erro', 'erro' => 'Erro', 'cancelada' => 'Cancelada', 'substituida' => 'Substituída', 'rascunho' => 'Rascunho',
    ];

    private const FIELD_LABELS = [
        'due_date' => 'Vencimento', 'obs' => 'Observação', 'ref_type' => 'Tipo de Cobrança',
        'nome_exportador' => 'Dados do Exportador', 'nome_importador' => 'Dados do Importador',
    ];

    /** FinCharges::recalcular(): aéreo/seguro sempre; demais conforme o armador. */
    public function allowsRecalculation(FinCharges $charge): bool
    {
        $embarque = $charge->embarque;

        if (in_array($embarque->tipo_embarque, ['a', 's'], true)) {
            return true;
        }

        return (bool) DB::table('armador')->where('armador_id', $embarque->armador_id)->value('recalcular');
    }

    public function heading(FinCharges $charge): string
    {
        return $this->person($charge->embarque->cliente_id).' » '.$charge->embarque->booking." (Id: {$charge->id})";
    }

    /**
     * Linhas de "Informações financeiras", em trechos para intercalar os campos editáveis
     * (Dados do Exportador/Importador e Escritório) que a página renderiza à parte.
     *
     * @return array<'head'|'importer'|'agent'|'tail', list<array{label:string, value:?string, url:?string}>>
     */
    public function booking(FinCharges $charge): array
    {
        $e = $charge->embarque;
        $date = fn ($effective, $planned) => ($d = $effective ?: $planned) ? Carbon::parse($d)->format('d/m/Y') : null;
        $name = fn (string $table, string $key, $id) => $id ? DB::table($table)->where($key, $id)->value('nome') : '';

        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;
        $lcl = $bl ? $this->blLclFactor($bl) : null;

        $tail = [
            ['BLs (numeros)', $e->house_bl],
            ['BLs (qtd.)', (string) $e->qtd_bl],
            ['Ton. / Met.', $lcl ? number_format($lcl, 4, ',', '.') : null],
            ['Reserva', $e->booking, $this->trackingUrl("booking/show?id={$e->embarque_id}")],
        ];

        if ($bl) {
            $tail[] = ['BL', $bl->bl];
        }

        $tail[] = ['Fatura', $bl?->dmf_ref ?: $e->invoice];

        $segments = [
            'head' => [
                ['Navio Origem', $name('navio', 'navio_id', $e->navio_id)],
                ['Navio Destino', $name('navio', 'navio_id', $e->navio_id_destino)],
                ['Data de Saída', $date($e->saida_efetiva, $e->saida_prevista)],
                ['Data de Chegada', $date($e->chegada_efetiva, $e->chegada_prevista)],
                ['Free Time Demurrage', number_format((float) $e->free_time, 0).' dias'],
                ['Origem', $this->port($e->porto_origem_id)],
                ['Destino', $this->port($e->porto_destino_id)],
                ['Cliente', $this->person($e->cliente_id)],
                ['Exportador', $this->person($e->pessoa_id_cliente)],
            ],
            'importer' => [
                ['Importador', $this->person($e->pessoa_id_importador)],
            ],
            'agent' => [
                ['Armador', $name('armador', 'armador_id', $e->armador_id)],
                ['Agente', $this->person($e->pessoa_id_representante)],
            ],
            'tail' => $tail,
        ];

        return array_map(fn ($rows) => array_map(fn ($r) => ['label' => $r[0], 'value' => filled($r[1]) ? (string) $r[1] : null, 'url' => $r[2] ?? null], $rows), $segments);
    }

    /** @return list<array{type:?string, number:?string, factor:string}> */
    public function containers(FinCharges $charge): array
    {
        return DB::table('container as c')->join('bl', 'bl.bl_id', '=', 'c.bl_id')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
            ->where('bl.embarque_id', $charge->ref_booking)
            ->when($charge->ref_bl, fn ($q, $bl) => $q->where('c.bl_id', $bl))
            ->select('c.*', 't.nome as tipo')->get()
            ->map(fn ($c) => ['type' => $c->tipo, 'number' => $c->numero, 'factor' => number_format($this->containerFactor($c), 4, ',', '.')])->all();
    }

    /** Cotações negociadas (listaCustom), mais recentes primeiro. */
    public function customRates(FinCharges $charge): array
    {
        return DB::table('fin_custom_currency_rate as r')
            ->leftJoin('cmn_currencies as c', 'c.id', '=', 'r.ref_currency')
            ->leftJoin('usuario as u', 'u.usuario_id', '=', 'r.created_by')
            ->where('r.ref_charge', $charge->id)->orderByDesc('r.id')
            ->select('r.*', 'c.name as currency', 'u.nome as user_name')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'currency' => $r->currency,
                'rate' => number_format((float) $r->custom_rate, 4, ',', '.'),
                'user' => $r->user_name,
                'date' => $r->created_at ? Carbon::parse($r->created_at)->format('d/m/Y') : null,
                'note' => $r->note,
            ])->all();
    }

    /** Itens da cobrança (Despesas): id, rótulo, cotação, valor unitário, índice, valor e isenção de IOF. */
    public function items(FinCharges $charge): array
    {
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;

        return DB::table('fin_charges_items as i')
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->leftJoin('cmn_currencies as c', 'c.id', '=', 'i.ref_currency')
            ->where('i.ref_charge', $charge->id)->orderBy('i.id')
            ->select('i.*', 'v.ref_due_to', 'v.ref_equipment', 'v.ref_bl as v_bl', 'v.ref_container', 'v.sr_min', 'v.ref_expense as expense_value_expense', 'e.name as expense_name', 'e.ref_unity', 'u.acronym as unity_name', 'c.symbol')
            ->get()->map(function (stdClass $i) use ($charge, $bl) {
                $index = $this->itemIndex($i, $charge, $bl);
                $unit = (float) $i->unitary_value;
                $shown = (float) $i->sr_min > 0 ? max((float) $i->sr_min, $unit * $index) : $unit;

                return [
                    'id' => (int) $i->id,
                    'expense' => $this->expenseLabel($i),
                    'rate' => number_format((float) $i->current_rate_value, 4, ',', '.'),
                    'unit' => trim(($i->symbol ?? '').' '.number_format($shown, 2, ',', '.')),
                    'index' => number_format($index, 4, ',', '.'),
                    'value' => 'R$ '.number_format((float) $i->item_value, 2, ',', '.'),
                    'exempt_iof' => $this->exemptFromIof($i) ? 'Sim' : 'Não',
                    // valores crus para a Nota de Débito (xls) e o PDF do recibo
                    'raw' => ['rate' => (float) $i->current_rate_value, 'unit' => $unit, 'index' => $index, 'value' => (float) $i->item_value,
                        'symbol' => (string) $i->symbol, 'service' => (int) $i->ref_due_to === 7, 'name' => (string) $i->expense_name],
                ];
            })->all();
    }

    /** Contatos da cobrança (Embarque::getContatosCobranca sem tipo). @return list<array{name:?string, email:?string, type:string}> */
    public function contacts(FinCharges $charge): array
    {
        $e = $charge->embarque;
        $users = fn (string $table) => DB::table("{$table} as c")->join('usuario as u', 'u.usuario_id', '=', 'c.usuario_id')
            ->where('c.embarque_id', $e->embarque_id)->where('u.receber_cobranca', 's')->where('u.status', 1)->select('u.nome', 'u.email')->get();
        $entry = fn ($rows, string $type) => collect($rows)->map(fn ($r) => ['name' => $r->nome ?? $r->name, 'email' => $r->email, 'type' => $type]);

        $contacts = $entry($users('embarque_contato_cliente'), 'Cliente');
        $contacts = $contacts->merge($e->impor_expor === 'i'
            ? $entry($users('embarque_contato_importador'), 'Consignee')
            : ($e->impor_expor === 'e' ? $entry($users('embarque_contato'), 'Shipper') : []));

        if ($e->despachante_id) {
            $contacts = $contacts->merge($entry(
                DB::table('pessoa_contato_despachante as q')->join('despachante_contato as c', 'c.id', '=', 'q.despachante_contato_id')
                    ->where('q.pessoa_id', $e->cliente_id)->where('q.despachante_id', $e->despachante_id)->where('c.status', 1)
                    ->whereIn('q.tipo', ['f', 'a', 't'])->select('c.name', 'c.email')->get(), 'Despachante (Cliente)'));
        }

        foreach ([['trading', 'trading_id', 'Trading'], ['assessoria', 'assessoria_id', 'Assessoria']] as [$kind, $column, $label]) {
            if ($e->{$column}) {
                $contacts = $contacts->merge($entry(
                    DB::table("pessoa_contato_{$kind} as q")->join("{$kind}_contato as c", "c.{$kind}_contato_id", '=', "q.{$kind}_contato_id")
                        ->where('q.pessoa_id', $e->cliente_id)->where("q.{$column}", $e->{$column})->where('c.status', 1)
                        ->whereIn('q.tipo', ['f', 'a'])->select('c.nome', 'c.email')->get(), $label));
            }
        }

        return $contacts->all();
    }

    /** Histórico da IC: log + alterações de campo (FinChargesLogService::listarParaShow), mais recente primeiro. */
    public function history(FinCharges $charge): array
    {
        $items = DB::table('fin_charges_log as l')->leftJoin('usuario as u', 'u.usuario_id', '=', 'l.created_by')
            ->where('l.ref_charge', $charge->id)->select('l.*', 'u.nome as user_name')->get()
            ->map(function ($l) {
                $status = ($l->status_anterior !== null || $l->status_novo !== null)
                    ? $this->logStatus($l->status_anterior).' → '.$this->logStatus($l->status_novo) : '—';

                return [
                    'at' => $l->created_at, 'source' => 'log', 'action' => $l->acao,
                    'row' => ['date' => $this->dateTime($l->created_at), 'event' => self::LOG_ACTIONS[$l->acao] ?? $l->acao,
                        'user' => $l->user_name ?: ($l->created_by ? "#{$l->created_by}" : 'Sistema'), 'status' => $status, 'detail' => (string) $l->historico],
                ];
            })->all();

        $changes = DB::table('rastreabilidade_alteracao as r')->leftJoin('usuario as u', 'u.usuario_id', '=', 'r.created_by')
            ->where('r.tabela', 'fin_charges')->where('r.tabela_id', $charge->id)->orderByDesc('r.rastreabilidade_alteracao_id')
            ->select('r.*', 'u.nome as user_name')->get();

        foreach ($changes as $c) {
            $old = filled($c->valor_antigo) ? (string) $c->valor_antigo : '—';
            $new = filled($c->valor_novo) ? (string) $c->valor_novo : '—';
            $action = $c->campo === 'due_date' ? 'edit_due_date' : 'alteracao';

            // não duplica o que o log já registrou (mesma ação, texto contendo antigo e novo)
            if (collect($items)->contains(fn ($i) => $i['source'] === 'log' && $i['action'] === $action
                && $i['row']['detail'] !== '' && str_contains($i['row']['detail'], $old) && str_contains($i['row']['detail'], $new))) {
                continue;
            }

            $items[] = [
                'at' => $c->created_at, 'source' => 'rastreabilidade', 'action' => $action,
                'row' => ['date' => $this->dateTime($c->created_at), 'event' => self::LOG_ACTIONS[$action],
                    'user' => $c->user_name ?: ($c->created_by ? "#{$c->created_by}" : 'Sistema'), 'status' => '—',
                    'detail' => (self::FIELD_LABELS[$c->campo] ?? $c->campo)." alterado de {$old} para {$new}"],
            ];
        }

        usort($items, fn ($a, $b) => strtotime((string) $b['at']) <=> strtotime((string) $a['at']));

        return array_column($items, 'row');
    }

    /** Painel "Baixa Bancária" (status Pago). @return list<array{label:string, value:string}> */
    public function bankSettlement(FinCharges $charge): array
    {
        $bank = $charge->ref_bank ? DB::table('cmn_banks')->where('id', $charge->ref_bank)->first() : null;
        $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');

        $rows = [
            ['Banco', $bank && filled($bank->name) ? "{$bank->name} ({$bank->bank_number})" : ''],
            ['Pagamento', $charge->payment_date?->format('d/m/Y')],
            ['Valor Doc.', $brl($charge->total_value)],
            ['Valor Pago', $brl($charge->received_value)],
            ['Juros', $brl($charge->interest)],
            ['Multa', $brl($charge->fine)],
        ];

        if ((int) $charge->discount_status === 1) {
            $rows[] = ['Desconto', $brl($charge->discount)];
        }

        return array_map(fn ($r) => ['label' => $r[0], 'value' => (string) $r[1]], $rows);
    }

    /** Painel "Baixa Por Boleto": o vencimento é o payment_confirmation. */
    public function boletoSettlement(FinCharges $charge): array
    {
        return [
            ['label' => 'Vencimento', 'value' => $charge->payment_confirmation ? Carbon::parse($charge->payment_confirmation)->format('d/m/Y') : ''],
            ['label' => 'Valor Doc.', 'value' => 'R$ '.number_format((float) $charge->total_value, 2, ',', '.')],
        ];
    }

    /** Recibos de pagamento (mais recente primeiro). */
    public function receipts(FinCharges $charge): array
    {
        return DB::table('fin_payment_receipt as r')->leftJoin('cmn_files as f', 'f.id', '=', 'r.ref_file')
            ->where('r.ref_charge', $charge->id)->orderByDesc('r.id')->select('r.id', 'r.valor', 'f.name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'file' => $r->name ? "{$r->name}.pdf" : null, 'url' => $this->upload('recibo', $r->name),
                'value' => $r->valor !== null && $r->valor !== '' ? 'R$ '.number_format((float) $r->valor, 2, ',', '.') : null])->all();
    }

    /** Declaração do boleto (a mais recente). @return list<array{file:?string, due:?string}> */
    public function declarations(FinCharges $charge): array
    {
        $b = DB::table('fin_payment_boleto as b')->leftJoin('cmn_files as f', 'f.id', '=', 'b.ref_file')
            ->where('b.ref_charge', $charge->id)->orderByDesc('b.id')->select('f.name', 'b.due_to')->first();

        return $b ? [['file' => $b->name ? "{$b->name}.pdf" : null, 'url' => $this->upload('declaracao', $b->name), 'due' => $b->due_to ? Carbon::parse($b->due_to)->format('d/m/Y') : null]] : [];
    }

    /** @return list<array{file:string}> */
    public function boletoReceipts(FinCharges $charge): array
    {
        return DB::table('recibo_boleto as r')->join('cmn_files as f', 'f.id', '=', 'r.arquivo_id')
            ->where('r.cobranca_id', $charge->id)->pluck('f.name')->map(fn ($n) => ['file' => "{$n}.pdf", 'url' => $this->upload('recibo', $n)])->all();
    }

    /** Documentos enviados (PDF da instrução etc.), mais recente primeiro. */
    public function sentDocuments(FinCharges $charge): array
    {
        return DB::table('fin_charges_files as x')->join('cmn_files as f', 'f.id', '=', 'x.ref_file')
            ->where('x.ref_charge', $charge->id)->orderByDesc('x.id')->select('f.name', 'f.created_at')->get()
            ->map(fn ($f) => ['file' => "{$f->name}.pdf", 'url' => $this->upload('cobranca', $f->name), 'date' => $f->created_at ? Carbon::parse($f->created_at)->format('d/m/Y') : null])->all();
    }

    /** Histórico de NFS-e da cobrança (mais recente primeiro), com o que cada nota permite fazer. */
    public function nfses(FinCharges $charge): array
    {
        return FinChargesNfse::where('ref_charge', $charge->id)->orderByDesc('fin_charges_nfse_id')->get()
            ->map(function (FinChargesNfse $n) {
                $notes = $n->readableErrors();

                if ($n->blockedByCancelLimit()) {
                    array_unshift($notes, 'Cancelamento indisponível: notas com valor igual ou superior a R$ 50.000,00 não podem ser canceladas.');
                }

                if ($n->hasPendingCancellation()) {
                    array_unshift($notes, 'Cancelamento em andamento.');
                }

                if ($n->hasPendingSubstitution()) {
                    array_unshift($notes, 'Substituição em andamento.');
                }

                return [
                    'id' => $n->getKey(),
                    'external_id' => $n->id_externo.($n->nfse_substituida_id ? " — substitui #{$n->nfse_substituida_id}" : ''),
                    'key' => $n->chave_acesso,
                    'value' => $n->valor_servicos !== null && $n->valor_servicos !== '' ? 'R$ '.number_format((float) $n->valor_servicos, 2, ',', '.') : '—',
                    'status' => self::NFSE_LABELS[$n->status] ?? ($n->status !== '' ? ucfirst((string) $n->status) : 'Enviada'),
                    'date' => $this->dateTime($n->created_at),
                    'danfse' => $n->status !== 'autorizada' ? null
                        : (filled($n->danfse_url) ? $n->danfse_url : $n->publicConsultationUrl()),
                    'notes' => $notes,
                    'can_download' => $n->canDownload(),
                    'can_refresh' => $n->canRefreshStatus(),
                    'can_cancel' => $n->canCancel(),
                    'can_substitute' => $n->canSubstitute(),
                    'can_reprocess' => $n->canReprocess(),
                ];
            })->all();
    }

    // ---------------------------------------------------------------- helpers

    /** Tela do tracking (legado) ainda não portada; null sem TRACKING_URL. */
    private function trackingUrl(string $path): ?string
    {
        return filled($base = config('services.tracking.url')) ? "{$base}/gerenciador.php/{$path}" : null;
    }

    /** Arquivo em web/uploads do tracking; null sem TRACKING_URL. */
    private function upload(string $folder, ?string $name, string $extension = '.pdf'): ?string
    {
        return filled($name) && filled(config('services.tracking.url'))
            ? Storage::disk('tracking_uploads')->url("{$folder}/".rawurlencode($name).$extension)
            : null;
    }

    private function person(?int $id): string
    {
        return $id ? (string) DB::table('pessoa')->where('pessoa_id', $id)->value('nome') : '';
    }

    public function port(?int $id): string
    {
        $port = $id ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $id)->select('p.nome', 'c.nome as pais')->first() : null;

        return $port ? "{$port->nome} ({$port->pais})" : '';
    }

    private function logStatus(mixed $status): string
    {
        return $status === null || $status === '' ? '—' : (self::LOG_STATUS[(int) $status] ?? (string) $status);
    }

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y H:i:s') : '';
    }

    /** FinExpensesValues::__toString: nome/unidade (equipamento) (BL) container. */
    public function expenseLabel(stdClass $i): string
    {
        $label = $i->expense_name.'/'.$i->unity_name;
        $equipment = $i->ref_equipment ? DB::table('tipo_container')->where('tipo_container_id', $i->ref_equipment)->value('nome') : '';
        $label .= filled($equipment) ? " ({$equipment})" : '';

        if ($i->v_bl) {
            $label .= ' ('.(float) DB::table('bl')->where('bl_id', $i->v_bl)->value('bl').')'; // legado: +$bl (numérico)
        }

        if ($i->ref_container) {
            $c = DB::table('container as c')->join('bl', 'bl.bl_id', '=', 'c.bl_id')->where('c.container_id', $i->ref_container)->select('c.numero', 'bl.bl')->first();
            $label .= $c && $c->numero ? " {$c->numero} ({$c->bl})" : ' ';
        }

        return $label;
    }

    /** FinChargesItems::getIsentaIof. */
    private function exemptFromIof(stdClass $i): bool
    {
        return (int) $i->ref_currency === 1
            || DB::table('regra_despesa_cobranca_item')->where('despesa_id', $i->expense_value_expense)->where('regra_despesa_cobranca_id', 10)->exists();
    }

    /** FinChargesItems::getIndice. */
    private function itemIndex(stdClass $i, FinCharges $charge, ?stdClass $bl): float
    {
        return match ((int) $i->ref_unity) {
            1 => $charge->ref_bl ? 1.0 : (float) $charge->embarque->qtd_bl,
            2 => match (true) {
                filled($i->ref_equipment) => $bl ? $this->blFactorByType($bl, (int) $i->ref_equipment) : 0.0,
                (bool) $i->ref_container => $this->containerFactor(DB::table('container')->where('container_id', $i->ref_container)->first()),
                default => $bl ? $this->blFactor($bl) : 0.0,
            },
            3 => $i->ref_container
                ? $this->containerLclFactor(DB::table('container')->where('container_id', $i->ref_container)->first())
                : ($bl ? $this->blLclFactor($bl) : 0.0),
            5 => 1 / max(1, DB::table('bl')->where('embarque_id', $charge->ref_booking)->whereNotNull('bl')->where('bl', '<>', '')->count()),
            default => 1.0,
        };
    }

    private function num(mixed $value): float
    {
        return (float) str_replace(',', '.', (string) $value);
    }

    // ------------------------------------------- fatores de BL/container (também usados no recálculo)

    /** Bl::getFatorLcl. */
    public function blLclFactor(stdClass $bl): float
    {
        $weight = (float) str_replace(',', '.', str_replace('.', '', (string) $bl->gross_weight));
        $volume = (float) str_replace(',', '.', str_replace('.', '', (string) $bl->volume));

        return ($weight / 1000) > $volume ? $weight / 1000 : $volume;
    }

    /** Container::getFatorLclCtn. */
    public function containerLclFactor(?stdClass $c): float
    {
        if (! $c) {
            return 0.0;
        }

        return $this->num($c->volume) > $this->num($c->gross_weight) / 1000 ? $this->num($c->volume) : $this->num($c->gross_weight) / 1000;
    }

    /** Container::getFatorCtn — fração do volume do container sobre o total do mesmo container físico. */
    public function containerFactor(?stdClass $container): float
    {
        if (! $container) {
            return 0.0;
        }

        $bl = DB::table('bl')->where('bl_id', $container->bl_id)->first();
        $embarque = DB::table('embarque')->where('embarque_id', $bl->embarque_id)->first();

        $total = DB::table('container as q')->join('bl', 'bl.bl_id', '=', 'q.bl_id')->where('q.numero', $container->numero)
            ->when($embarque->impor_expor === 'i',
                fn ($q) => $q->where('bl.mbl_number', $bl->mbl_number),
                fn ($q) => $q->where('bl.embarque_id', $bl->embarque_id))
            ->pluck('q.volume')->sum(fn ($v) => $this->num($v));

        return $total > 0 ? $this->num($container->volume) / $total : 0.0;
    }

    /** Bl::getFatorCtn. */
    public function blFactor(stdClass $bl): float
    {
        $embarque = DB::table('embarque')->where('embarque_id', $bl->embarque_id)->first();
        $sum = 0.0;

        foreach (DB::table('container')->where('bl_id', $bl->bl_id)->get() as $container) {
            $total = DB::table('container as q')->join('bl', 'bl.bl_id', '=', 'q.bl_id')->where('q.numero', $container->numero)
                ->when($embarque->impor_expor === 'e',
                    fn ($q) => $q->where('bl.embarque_id', $bl->embarque_id),
                    fn ($q) => $q->join('embarque as e', 'e.embarque_id', '=', 'bl.embarque_id')->where('e.navio_id', $embarque->navio_id))
                ->pluck('q.volume')->sum(fn ($v) => $this->num($v));

            $sum += $total > 0 ? $this->num($container->volume) / $total : 0.0;
        }

        return $sum;
    }

    /** Bl::getFatorCtnTipo. */
    public function blFactorByType(stdClass $bl, int $type): float
    {
        return DB::table('container')->where('bl_id', $bl->bl_id)->where('tipo_container_id', $type)->get()
            ->sum(fn ($c) => $this->containerFactor($c));
    }
}
