<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Embarque extends Model
{
    /** EmbarqueTable::$finalizado_nomes (legado). */
    public const STATUSES = [
        'n' => 'Aberto',
        'p' => 'Suspenso',
        's' => 'Chegou ao destino',
        'e' => 'Encerrado',
        'c' => 'Cancelado',
        'd' => 'Descartado',
    ];

    /** tipo_embarque: modal da reserva. */
    public const TYPES = [
        'm' => 'Marítimo',
        'a' => 'Aéreo',
        's' => 'Seguro',
        'd' => 'Desembaraço',
        'r' => 'Correio',
        'h' => 'Handling',
    ];

    protected $table = 'embarque';

    protected $primaryKey = 'embarque_id';

    public $timestamps = false;

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'cliente_id', 'pessoa_id');
    }

    /** Exportador (shipper): no legado fica em pessoa_id_cliente. */
    public function exportador(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id_cliente', 'pessoa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class, 'vendedor_id', 'vendedor_id');
    }

    /** Agente: pessoa_id_representante no legado. */
    public function agente(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id_representante', 'pessoa_id');
    }

    public function importador(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id_importador', 'pessoa_id');
    }

    /** Notify: pessoa_id_notify no legado. */
    public function notify(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id_notify', 'pessoa_id');
    }

    /** Contatos da reserva por parte (tabelas embarque_contato_*, ligadas a usuario). */
    public function clientContacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'embarque_contato_cliente', 'embarque_id', 'usuario_id');
    }

    public function importerContacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'embarque_contato_importador', 'embarque_id', 'usuario_id');
    }

    public function notifyContacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'embarque_contato_notify', 'embarque_id', 'usuario_id');
    }

    public function shipperContacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'embarque_contato', 'embarque_id', 'usuario_id');
    }

    public function armador(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'armador_id', 'armador_id');
    }

    /** Coloader: um armador (coloader_id). */
    public function coloader(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'coloader_id', 'armador_id');
    }

    public function navio(): BelongsTo
    {
        return $this->belongsTo(Navio::class, 'navio_id', 'navio_id');
    }

    public function navioDestino(): BelongsTo
    {
        return $this->belongsTo(Navio::class, 'navio_id_destino', 'navio_id');
    }

    public function portoOrigem(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_origem_id', 'porto_id');
    }

    public function portoDestino(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_destino_id', 'porto_id');
    }

    public function escritorio(): BelongsTo
    {
        return $this->belongsTo(Escritorio::class, 'escritorio_id', 'escritorio_id');
    }

    public function ciaAerea(): BelongsTo
    {
        return $this->belongsTo(CiaAerea::class, 'ref_cia_aerea', 'id');
    }

    /** Pagador da Freight Invoice. */
    public function pagador(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id_pagador', 'pessoa_id');
    }

    /** Quem criou a reserva (intIdUser no legado). */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'intIdUser', 'usuario_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(FinCharges::class, 'ref_booking', 'embarque_id');
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(FinPaymentOrder::class, 'ref_embarque', 'embarque_id');
    }

    public function controlePa(): HasOne
    {
        return $this->hasOne(ControlePa::class, 'embarque_id', 'embarque_id');
    }

    /** Freight Invoices da DMF geradas para a reserva (arquivos de tipo 24), a mais nova primeiro. */
    public function freightInvoices(): HasMany
    {
        return $this->hasMany(EmbEmbarqueFile::class, 'ref_embarque', 'embarque_id')
            ->where('ref_file_type', EmbEmbarqueFile::DMF_INVOICE)
            ->orderByDesc('id');
    }

    public function bls(): HasMany
    {
        return $this->hasMany(Bl::class, 'embarque_id', 'embarque_id');
    }

    public function goods(): HasMany
    {
        return $this->hasMany(Mercadoria::class, 'embarque_id', 'embarque_id')->orderBy('nome');
    }

    public function departureConfirmations(): HasMany
    {
        return $this->hasMany(DepartureConfirmation::class, 'ref_embarque', 'embarque_id');
    }

    public function arrivalConfirmations(): HasMany
    {
        return $this->hasMany(ArrivalConfirmation::class, 'ref_embarque', 'embarque_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ComissaoEmbarque::class, 'embarque_id', 'embarque_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinExpensesValues::class, 'ref_embarque', 'embarque_id');
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(FinDebitNotes::class, 'ref_embarque', 'embarque_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(FinCreditNotes::class, 'ref_embarque', 'embarque_id');
    }

    public function demurrages(): HasMany
    {
        return $this->hasMany(FinDemurrage::class, 'ref_embarque', 'embarque_id')->orderBy('id');
    }

    public function detentions(): HasMany
    {
        return $this->hasMany(FinDetention::class, 'ref_embarque', 'embarque_id')->orderBy('id');
    }

    /** Observações/lembretes da reserva, a mais nova primeiro. */
    public function remarks(): HasMany
    {
        return $this->hasMany(ObservacaoEmbarque::class, 'embarque_id', 'embarque_id')->orderByDesc('created_at');
    }

    /** Mensagens de rastreamento da reserva, a mais recente primeiro. */
    public function trackings(): HasMany
    {
        return $this->hasMany(Transborda::class, 'embarque_id', 'embarque_id')->orderByDesc('data_reg')->orderByDesc('transborda_id');
    }

    /** Alterações de rota do navio, a mais recente primeiro. */
    public function routes(): HasMany
    {
        return $this->hasMany(EmbarqueRota::class, 'embarque_id', 'embarque_id')->orderByDesc('rota_id');
    }

    /** Controle do IMO (importação): um registro por reserva. */
    public function imos(): HasMany
    {
        return $this->hasMany(ControleImoCi::class, 'embarque_id', 'embarque_id');
    }

    /** Envios pelo Correio que levam documentos desta reserva, o mais recente primeiro. */
    public function mailings(): BelongsToMany
    {
        return $this->belongsToMany(ControleCorreio::class, 'ctr_correio_embarque', 'embarque_id', 'controle_correio_id')->orderByDesc('data_envio');
    }

    /** Rastreabilidade (log) da reserva, a mais recente primeiro. */
    public function logs(): HasMany
    {
        return $this->hasMany(Rastreamento::class, 'ref_embarque', 'embarque_id')->orderByDesc('id');
    }

    /** Há pendência de liberação do embarque (`liberacao_embarque` com status 0)? */
    public function hasPendingRelease(): bool
    {
        return DB::table('liberacao_embarque')->where('embarque_id', $this->getKey())->where('status', 0)->exists();
    }

    /** Embarque::getRefArmadorParaPagamentoId (legado): o coloader, se informado, senão o armador da reserva. */
    public function paymentCarrierId(): ?int
    {
        return (int) $this->coloader_id > 0 ? (int) $this->coloader_id : ($this->armador_id ? (int) $this->armador_id : null);
    }

    /** Embarque::getFatorLclImpo (legado): o maior entre peso e volume da cotação (aéreo: volume ÷ 0,006). */
    public function importFactor(): ?float
    {
        $weight = (float) $this->peso_cotacao;
        $volume = (float) $this->volume_cotacao;

        if ($this->impor_expor !== 'i' || (! $weight && ! $volume)) {
            return null;
        }

        return max($this->tipo_embarque === 'm' ? $volume : $volume / 0.006, $weight);
    }

    /**
     * Quadro "Status do Embarque" do show de exportação do legado. A NRA só entra quando a regra do legado se aplica
     * (export para os EUA, fechamento na origem, com NRA nos docs, sem BL direto e agente da AIC).
     * "Pagamento Armador Enviado" no legado nunca marca (verificaPagamento tem um contador que nunca sobe): aqui marca
     * quando há ordens de pagamento e nenhuma pendente.
     *
     * @return array<string, bool>
     */
    public function shipmentStatus(): array
    {
        $id = $this->getKey();
        $valid = fn (?string $date) => filled($date) && ! str_starts_with($date, '0000');
        $notes = $this->debitNotes->concat($this->creditNotes);
        $received = $this->charges()->where(fn ($query) => $query->whereHas('receipts')->orWhereHas('boletoPayments'))->exists();
        $orders = $this->paymentOrders()->pluck('status');

        $status = [
            'Booking enviado' => $this->trackings()->where('ref_frase_transborda', 16)->exists(),
            'Embarque confirmado' => $this->departureConfirmations()->exists(),
            'Cobrança enviada' => $this->charges()->exists(),
            'Pagamento cliente recebido' => $received,
            'Recibo enviado' => $received,
            'Pagamento armador enviado' => $orders->isNotEmpty() && $orders->every(fn ($value) => (int) $value !== 0),
            'Pré-aviso enviado' => DB::table('emb_pre_advise')->where('ref_embarque', $id)->exists(),
            'Debit/Credit note enviado' => $valid($this->controle_credit) || $valid($this->controle_debit)
                || ($notes->isNotEmpty() && $notes->every(fn ($note) => (int) $note->status === 1)),
            'Chegou no destino' => $this->arrivalConfirmations()->exists(),
        ];

        if ($this->requiresNra()) {
            $status['NRA'] = DB::table('embarque_nra')->where('embarque_id', $id)->exists();
        }

        return $status;
    }

    /** Embarque::getFlagNra (legado) diferente de 0. */
    private function requiresNra(): bool
    {
        return $this->tipo_fechamento === 'o'
            && $this->impor_expor === 'e'
            && ! $this->bl_direto
            && in_array((int) $this->pessoa_id_representante, [1838, 3548, 10959, 10960, 10961, 11517], true)
            && (int) $this->portoDestino?->ref_pais === 4
            && EmbEmbarqueFile::where('ref_embarque', $this->getKey())->where('ref_file_type', 44)->exists();
    }
}
