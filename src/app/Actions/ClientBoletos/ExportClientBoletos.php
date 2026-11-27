<?php

namespace App\Actions\ClientBoletos;

use App\Models\FinClientsBoleto;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Botão "Exportar para Excel" da lista clientBoleto (legado): as 9 colunas da tela, por nome do cliente. */
class ExportClientBoletos
{
    private const HEADERS = ['Nome', 'CNPJ', 'OBS', 'Prazo IMPO', 'Boleto IMPO', 'Automático IMPO', 'Prazo EXPO', 'Boleto EXPO', 'Automático EXPO'];

    public function __invoke(): StreamedResponse
    {
        $rows = FinClientsBoleto::with('client')
            ->get()
            ->sortBy(fn (FinClientsBoleto $r) => $r->client?->nome)
            ->map(fn (FinClientsBoleto $r) => [
                $r->client?->nome,
                $r->client?->cnpj,
                $r->prazo,
                $r->prazo_imp ?: '-',
                $r->boleto_imp ? 'SIM' : 'NÃO',
                $r->boleto_automatico_imp ? 'SIM' : 'NÃO',
                $r->prazo_exp ?: '-',
                $r->boleto_exp ? 'SIM' : 'NÃO',
                $r->boleto_automatico_exp ? 'SIM' : 'NÃO',
            ]);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Prazos Negociados');
        $sheet->fromArray([self::HEADERS, ...$rows->values()->all()]);
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'prazos_negociados_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
