<?php

namespace App\Actions\Financeiro;

use App\Models\FinCompaniesRates;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * taxasEmpresas/relatorio (legado): "Relação de Clientes com Taxas Negociadas", listagem para imprimir (cliente, cotação e
 * taxa), por ordem de cliente. O legado abria uma página HTML com window.print(); aqui sai em PDF A4.
 */
class CompanyRatesPdf
{
    public function __invoke(): StreamedResponse
    {
        $pdf = $this->render($this->rows());

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'taxas-negociadas.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** @return Collection<int, FinCompaniesRates> */
    public function rows(): Collection
    {
        return FinCompaniesRates::query()
            ->select('fin_companies_rates.*')
            ->join('pessoa', 'pessoa.pessoa_id', '=', 'fin_companies_rates.ref_pessoa')
            ->with(['pessoa', 'rate.currency'])
            ->orderBy('pessoa.nome')
            ->get();
    }

    /** @param  Collection<int, FinCompaniesRates>  $rows */
    public function render(Collection $rows): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Relação de Clientes com Taxas Negociadas');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();

        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Relação de Clientes com Taxas Negociadas', 0, 1, 'L');

        $body = $rows->map(fn (FinCompaniesRates $row) => '<tr><td>'.e($row->pessoa?->nome).'</td><td>'.e($row->rate?->label).'</td><td align="right">'
            .number_format((float) $row->tax, 3, ',', ' ').'%</td></tr>')->implode('');

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->writeHTML('<table border="1" cellpadding="3"><thead><tr style="font-weight:bold"><th width="45%">Cliente</th><th width="35%">Taxa</th><th width="20%" align="right">Valor</th></tr></thead>'
            .$body.'</table>');

        return $pdf;
    }
}
