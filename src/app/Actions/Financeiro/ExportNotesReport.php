<?php

namespace App\Actions\Financeiro;

use App\Actions\Cobranca\Documents\DebitNote;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * financeiro/geraRelatorio (legado), "Relatório Notas": uma aba de Nota de Débito por cobrança paga (a aba leva o nome da reserva).
 * Grupo Faros: cobranças pagas no mês, de clientes do grupo. JR: cobranças pagas de reservas do cliente JR com saída efetiva no mês.
 * O legado também juntava os PDFs dos recibos (geraRelatorioPdf); isso fica de fora até haver uma biblioteca de PDF para mesclar.
 */
class ExportNotesReport
{
    public const FAROS = 1;

    public const JR = 2;

    /** pessoa_id da JR Comércio de Bicicletas e Acessórios (fixo no legado). */
    public const JR_CLIENT = 2516;

    private const FAROS_GROUP = 1;

    public function __construct(private DebitNote $note) {}

    /** @return ?StreamedResponse null quando não há cobranças no período (o legado respondia "-1") */
    public function __invoke(int $report, int $month, int $year): ?StreamedResponse
    {
        $charges = $this->charges($report, $month, $year);

        if ($charges->isEmpty()) {
            return null;
        }

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($charges as $charge) {
            $sheet = $book->createSheet();
            $sheet->setTitle(Str::limit(preg_replace('/[\\\\\/?*:\[\]]/', '-', (string) $charge->embarque->booking), 31, ''));
            $this->note->fill($sheet, $charge, $charge->receipts()->orderBy('id')->value('id'));
        }

        $name = sprintf('relatorio_notas_%s_%02d_%d.xls', $report === self::JR ? 'jr' : 'faros', $month, $year);

        return response()->streamDownload(fn () => (new Xls($book))->save('php://output'), $name, ['Content-Type' => 'application/vnd.ms-excel']);
    }

    /** @return Collection<int, FinCharges> */
    public function charges(int $report, int $month, int $year): Collection
    {
        $paid = FinCharges::query()->with('embarque')->where('status', ChargeStatus::Paid)->orderBy('id');

        return match ($report) {
            self::FAROS => $paid
                ->whereMonth('payment_date', $month)->whereYear('payment_date', $year)
                ->whereHas('embarque', fn ($e) => $e->whereExists(
                    fn ($m) => $m->selectRaw('1')->from('grupo_empresa_membro as gem')
                        ->whereColumn('gem.pessoa_id', 'embarque.cliente_id')->where('gem.grupo_empresa_id', self::FAROS_GROUP),
                ))->get(),
            self::JR => $paid
                ->whereHas('embarque', fn ($e) => $e->where('cliente_id', self::JR_CLIENT)->whereMonth('saida_efetiva', $month)->whereYear('saida_efetiva', $year))
                ->get(),
            default => new Collection,
        };
    }
}
