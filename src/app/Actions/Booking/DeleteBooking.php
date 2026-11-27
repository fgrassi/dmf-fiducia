<?php

namespace App\Actions\Booking;

use App\Enums\Alert;
use App\Models\Embarque;
use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * booking/delete (legado), para reservas cadastradas por engano: apaga a reserva com mercadorias, rastreamento, BLs, contatos e
 * arquivos e avisa o departamento com a justificativa.
 *
 * Diferença do legado, por correção: ele só conferia despesas, cobranças, ordens, notas, demurrage e detention, e rodava sem
 * transação. Quando outra chave estrangeira barrava o último DELETE, o resto da reserva já tinha sido apagado. Aqui todos os
 * vínculos são conferidos antes.
 */
class DeleteBooking
{
    /** rótulo => [tabela, coluna que aponta para a reserva]. Os sete primeiros são os que o legado conferia. */
    private const BOOKING_LINKS = [
        'Despesas' => ['fin_expenses_values', 'ref_embarque'],
        'Cobranças' => ['fin_charges', 'ref_booking'],
        'Ordens de pagamento' => ['fin_payment_orders', 'ref_embarque'],
        'Credit Notes' => ['fin_credit_notes', 'ref_embarque'],
        'Debit Notes' => ['fin_debit_notes', 'ref_embarque'],
        'Detention' => ['fin_detention', 'ref_embarque'],
        'Demurrage' => ['fin_demurrage', 'ref_embarque'],
        'Reserva de seguro vinculada' => ['embarque', 'reserva_vinculada_id'],
        'AMS Emirados Árabes' => ['ams_uae', 'embarque_id'],
        'BL online' => ['bl_online', 'embarque_id'],
        'Capa do processo' => ['capa_processo', 'embarque_id'],
        'Controle de Invoice' => ['controle_invoice_master', 'embarque_id'],
        'Controle PA' => ['controle_pa', 'embarque_id'],
        'Rota' => ['embarque_rota', 'embarque_id'],
        'Shipper do embarque' => ['embarque_shipper', 'embarque_id'],
        'Taxa de pagamento personalizada' => ['fin_custom_payment_tax', 'ref_embarque'],
        'Valor do House BL' => ['fin_hbl_embarque', 'ref_embarque'],
        'Ordens de pagamento consolidadas' => ['fin_payment_order_embarque', 'ref_embarque'],
        'Eventos da integração com o portal' => ['integracao_portal_evento', 'embarque_id'],
        'Ordens de pagamento de outros' => ['ordem_pagamento', 'ref_embarque'],
        'Resumo de resultado' => ['resumo_resultado', 'embarque_id'],
        'Baixa de TA' => ['ta_baixa', 'embarque_id'],
        'Containers do rastreamento' => ['trk_containers', 'ref_embarque'],
    ];

    /** O que pode apontar para um BL da reserva, mesmo sendo de outra (MBL consolidado). */
    private const BL_LINKS = [
        'Cobranças' => ['fin_charges', 'ref_bl'],
        'Despesas' => ['fin_expenses_values', 'ref_bl'],
        'Ordens de pagamento consolidadas' => ['fin_payment_order_embarque', 'ref_bl'],
        'Ordens de pagamento' => ['fin_payment_order_items', 'ref_bl'],
    ];

    /** MyISAM: não voltam num rollback, então só são apagadas depois que a exclusão é confirmada. */
    private const WITHOUT_ROLLBACK = [
        'rastreamento' => 'ref_embarque',
        'cmn_daily_booking_report' => 'ref_booking',
        'embarque_contato' => 'embarque_id',
        'embarque_contato_cliente' => 'embarque_id',
        'embarque_contato_importador' => 'embarque_id',
        'embarque_contato_notify' => 'embarque_id',
    ];

    /** Onde o legado procura o arquivo, nesta ordem. */
    private const FILE_DIRECTORIES = ['docs_emb', 'creditNotes', 'debitNotes'];

    /** Todos os tipos (o seguro, o desembaraço e o correio do legado usam a mesma exclusão), dentro da operação do usuário. */
    public static function allows(Embarque $booking, User $user): bool
    {
        return ! $user->onlyOperation() || $user->onlyOperation() === $booking->impor_expor;
    }

    /** @throws DomainException */
    public function __invoke(Embarque $booking, string $justification, User $user): void
    {
        throw_unless(self::allows($booking, $user), DomainException::class, 'Seu usuário não pode excluir reservas desta operação.');
        throw_if(blank(trim($justification)), DomainException::class, 'Por gentileza, informar uma justificativa para exclusão da reserva.');

        DB::transaction(function () use ($booking, $justification, $user) {
            $booking = Embarque::query()->lockForUpdate()->findOrFail($booking->getKey());
            $links = $this->links($booking);

            throw_if($links !== [], DomainException::class, 'Não foi possível excluir a reserva. Há registros ligados a ela: '.implode(', ', $links).'.');

            $id = $booking->getKey();
            $bls = DB::table('bl')->where('embarque_id', $id)->pluck('bl_id');

            DB::table('mercadoria')->where('embarque_id', $id)->delete();
            DB::table('transborda')->where('embarque_id', $id)->delete();
            DB::table('container')->whereIn('bl_id', $bls)->delete();
            DB::table('bl')->where('embarque_id', $id)->delete();
            $files = $this->deleteFileRecords($id);
            $booking->delete();

            $this->syncQuotation($booking->cotacao_expo_id);

            DB::afterCommit(function () use ($booking, $justification, $user, $files) {
                foreach (self::WITHOUT_ROLLBACK as $table => $column) {
                    DB::table($table)->where($column, $booking->getKey())->delete();
                }

                $this->deleteFiles($files);
                // O rastreamento vai junto com a reserva: o log guarda quem excluiu e por quê, mesmo com o alerta desligado.
                Log::info('Reserva excluída', ['embarque_id' => $booking->getKey(), 'booking' => $booking->booking, 'usuario_id' => $user->getKey(), 'justificativa' => $justification]);
                $this->notify($booking, $justification, $user);
            });
        });
    }

    /** @return list<string> o que impede a exclusão */
    private function links(Embarque $booking): array
    {
        $id = $booking->getKey();
        $bls = fn () => DB::table('bl')->where('embarque_id', $id)->select('bl_id');
        $links = [];

        foreach (self::BOOKING_LINKS as $label => [$table, $column]) {
            DB::table($table)->where($column, $id)->exists() && $links[] = $label;
        }

        foreach (self::BL_LINKS as $label => [$table, $column]) {
            DB::table($table)->whereIn($column, $bls())->exists() && $links[] = $label;
        }

        DB::table('fin_expenses_values')
            ->whereIn('ref_container', DB::table('container')->whereIn('bl_id', $bls())->select('container_id'))
            ->exists() && $links[] = 'Despesas';

        return array_values(array_unique($links));
    }

    /**
     * Desliga os arquivos da reserva e apaga os cadastros que mais ninguém usa.
     *
     * @return Collection<int, string> nomes dos arquivos que podem sair do disco
     */
    private function deleteFileRecords(int $bookingId): Collection
    {
        $ids = DB::table('emb_embarque_files')->where('ref_embarque', $bookingId)->pluck('ref_file')->unique();
        DB::table('emb_embarque_files')->where('ref_embarque', $bookingId)->delete();

        $unused = DB::table('cmn_files as f')->whereIn('f.id', $ids);

        foreach (['emb_embarque_files', 'fin_payment_boleto', 'fin_charges_boleto_sicredi', 'hd_files'] as $table) {
            $unused->whereNotExists(fn (Builder $q) => $q->from($table)->whereColumn("{$table}.ref_file", 'f.id'));
        }

        $files = $unused->pluck('f.name', 'f.id');
        DB::table('cmn_files')->whereIn('id', $files->keys())->delete();

        // Dois envios com o mesmo nome dividem o arquivo no disco.
        return $files->values()
            ->diff(DB::table('cmn_files')->whereIn('name', $files->values())->pluck('name'))
            ->values();
    }

    /** @param  Collection<int, string>  $names */
    private function deleteFiles(Collection $names): void
    {
        $disk = Storage::disk('tracking_uploads');

        foreach ($names->map(fn (?string $name) => basename((string) $name))->filter() as $name) {
            // A reserva já saiu: uma falha no disco vai para o log e não vira erro na tela.
            rescue(function () use ($disk, $name) {
                $path = collect(self::FILE_DIRECTORIES)->map(fn (string $directory) => "{$directory}/{$name}")->first(fn (string $path) => $disk->exists($path));

                $path && $disk->delete($path);
            });
        }
    }

    /** CotacaoExpo::syncStatusFromEmbarques: sem reserva nenhuma, volta a "Criada", a não ser que já esteja finalizada. */
    private function syncQuotation(?int $quotationId): void
    {
        if (! $quotationId) {
            return;
        }

        $hasBookings = DB::table('embarque')->where('cotacao_expo_id', $quotationId)->exists();

        DB::table('cotacao_expo')
            ->where('cotacao_expo_id', $quotationId)
            ->when(! $hasBookings, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '<>', 2)))
            ->update(['status' => $hasBookings ? 1 : 0]);
    }

    private function notify(Embarque $booking, string $justification, User $user): void
    {
        $alert = $booking->impor_expor === 'e' ? Alert::BookingDeletedExport : Alert::BookingDeletedImport;

        rescue(fn () => $alert->send(
            "[Aviso] Reserva Excluída - {$booking->booking}",
            '<h3>Reserva Excluída</h3>A reserva <b>'.e((string) $booking->booking).'</b> foi excluída pelo usuário '.e((string) $user->nome)
                .' no dia '.now()->format('d/m/Y').' as '.now()->format('H:i').'<p>Justificativa: '.nl2br(e($justification)).'</p>',
        ));
    }
}
