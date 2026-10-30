<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use App\Models\CapaProcessoManual;
use Illuminate\Support\Facades\DB;

/**
 * cron/manutencao (legado): grava o resumo_resultado das capas, normais e manuais, já arquivadas que ainda não têm (até 500
 * de cada tipo por execução) e avisa quem favoritou a reserva das normais.
 *
 * Diferença do legado: o `resultado` é sempre gravado (R$ + taxa); o legado deixa metade dos resumos de 2026 com ele nulo
 * por um motivo que não está neste repositório.
 */
class GenerateCoverSummaries
{
    private const LIMIT = 500;

    private const FAVORITES_FAMILY = 5;

    private const SYSTEM_USER = 1;

    /** @return int quantidade de resumos gerados */
    public function __invoke(): int
    {
        $covers = CapaProcesso::query()
            ->whereNotNull('capa_processo_arquivo_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('resumo_resultado as r')->whereColumn('r.capa_processo_id', 'capa_processo.capa_process_id'))
            ->with('folder')
            ->orderBy('capa_process_id')
            ->limit(self::LIMIT)
            ->get();

        $generated = $this->manualCovers();

        foreach ($covers as $cover) {
            $booking = DB::table('embarque')->where('embarque_id', $cover->embarque_id)->first();

            if (! $booking) {
                continue;
            }

            DB::transaction(function () use ($cover, $booking) {
                DB::table('resumo_resultado')->insert($this->row($cover, $booking));
                $this->notifyFavorites($booking);
            });

            $generated++;
        }

        return $generated;
    }

    /** Capas manuais arquivadas sem resumo: sem reserva, o "booking" do resumo é o número da capa. */
    private function manualCovers(): int
    {
        $covers = CapaProcessoManual::query()
            ->whereNotNull('capa_processo_arquivo_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('resumo_resultado as r')->whereColumn('r.capa_processo_manual_id', 'capa_processo_manual.capa_processo_manual_id'))
            ->with('folder')
            ->orderBy('capa_processo_manual_id')
            ->limit(self::LIMIT)
            ->get();

        foreach ($covers as $cover) {
            $result = $cover->result();

            DB::table('resumo_resultado')->insert([
                'capa_processo_manual_id' => $cover->getKey(),
                'numero_capa' => $cover->folder->nome,
                'booking' => $cover->numero,
                'cliente' => (string) DB::table('pessoa')->where('pessoa_id', $cover->cliente_id)->value('nome'),
                'cliente_id' => $cover->cliente_id,
                'data_pagamento' => $cover->payment_date?->toDateString(),
                'usd' => $cover->moeda_usd_eur == 2 ? $cover->valor_usd_eur : 0,
                'euro' => $cover->moeda_usd_eur == 3 ? $cover->valor_usd_eur : 0,
                'rs' => $result,
                'resultado' => $result + $cover->rate(),
            ]);
        }

        return $covers->count();
    }

    /** @return array<string, mixed> */
    private function row(CapaProcesso $cover, object $booking): array
    {
        $origin = $this->port($booking->porto_origem_id);
        $destination = $this->port($booking->porto_destino_id);
        $carrier = $booking->armador_id ? DB::table('armador')->where('armador_id', $booking->armador_id)->first() : null;
        $airline = $booking->ref_cia_aerea ? DB::table('cia_aerea')->where('id', $booking->ref_cia_aerea)->first() : null;
        $seller = $booking->vendedor_id ? DB::table('vendedor as v')->leftJoin('sedes as s', 's.id', '=', 'v.sede_id')->where('v.vendedor_id', $booking->vendedor_id)->first(['v.nome', 's.id as office_id', 's.nome as office']) : null;
        $result = $cover->result();

        return [
            'capa_processo_id' => $cover->getKey(),
            'numero_capa' => $cover->folder->nome,
            'embarque_id' => $booking->embarque_id,
            'booking' => $booking->booking,
            'house_bl' => $booking->house_bl,
            'cliente' => (string) DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('nome'),
            'cliente_id' => $booking->cliente_id,
            'tipo_embarque' => $booking->tipo_embarque,
            'impo_expo' => $booking->impor_expor,
            'mercadoria' => DB::table('mercadoria')->where('embarque_id', $booking->embarque_id)->orderBy('mercadoria_id')->pluck('nome')->unique()->implode(' ,'),
            'pais_origem' => $origin?->country,
            'pais_origem_id' => $origin?->country_id,
            'porto_origem' => $origin?->name,
            'porto_origem_id' => $origin?->id,
            'pais_destino' => $destination?->country,
            'pais_destino_id' => $destination?->country_id,
            'porto_destino' => $destination?->name,
            'porto_destino_id' => $destination?->id,
            'data_pagamento' => $cover->payment_date?->toDateString(),
            'armador' => $carrier?->nome,
            'armador_id' => $carrier ? $booking->armador_id : null,
            'cia_aerea' => $airline?->name,
            'cia_aerea_id' => $airline?->id,
            'vendedor' => $seller?->nome,
            'vendedor_id' => $seller ? $booking->vendedor_id : null,
            'centro_custo' => $seller?->office,
            'centro_custo_id' => $seller?->office_id,
            'usd' => $cover->moeda_id == 2 ? $cover->receitas : 0,
            'euro' => $cover->moeda_id == 3 ? $cover->receitas : 0,
            'rs' => $result,
            'resultado' => $result + $cover->rate(),
        ];
    }

    private function port(mixed $portId): ?object
    {
        return $portId
            ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $portId)
                ->first(['p.porto_id as id', 'p.nome as name', 'c.paises_id as country_id', 'c.nome as country'])
            : null;
    }

    /** Uma mensagem para cada usuário que favoritou a reserva (família "favoritos", autor sistema). */
    private function notifyFavorites(object $booking): void
    {
        foreach (DB::table('embarque_favorito')->where('embarque_id', $booking->embarque_id)->pluck('usuario_id') as $userId) {
            $messageId = DB::table('cmn_messages')->insertGetId([
                'ref_family' => self::FAVORITES_FAMILY,
                'author' => self::SYSTEM_USER,
                'created_at' => now(),
                'subject' => 'Embarque Favorito: Atualização de Resultado!',
                'message' => "O embarque {$booking->booking} teve seu resultado atualizado!",
            ]);

            DB::table('cmn_messages_recipients')->insert(['ref_message' => $messageId, 'recipient' => $userId, 'status' => 1]);
        }
    }
}
