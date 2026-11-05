<?php

namespace App\Actions\AgentNotes;

use App\Models\AgenteBanco;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * controleCreditDebitAgente/getPdf (legado): extrato em inglês para o agente com as notas baixadas (as mesmas da tela,
 * AgentNoteLists::statement): primeiro as devidas à DMF, depois as devidas ao agente (valor com "-" na frente), (A) total
 * devido ao agente, (B) total devido à DMF por moeda e o saldo líquido. Quem deve ao outro lado é decidido pela última
 * cotação de cada moeda (soma em reais). Os dados bancários são os da DMF se o saldo é a favor dela, senão os da conta
 * do agente escolhida na tela.
 *
 * Diferenças do legado: a decisão soma todas as moedas (o legado só olhava a última do laço) e a conta bancária impressa
 * segue a mesma decisão do título do saldo (o legado comparava arrays de totais); "AMMOUNT" virou "AMOUNT".
 */
class AgentNoteStatementPdf
{
    private const LINE = 3.5;

    private const WIDTHS = [17, 25, 25, 25, 25, 50, 23];

    private const PAGE_BOTTOM = 260;

    private const SENDER = [
        'SENDER: DMF LOGISTICS DO BRASIL LTDA',
        'ADDRESS: RUA BORGES DE MEDEIROS 274 2º ANDAR',
        'SANTA CRUZ DO SUL - RS - BRAZIL - ZIP: 96810-034',
        'PHONE:  +55(51) 3717-8200',
        'TAX ID  07.052.088/0001-34',
    ];

    private const DMF_BANK = [
        'OUR BANK DETAILS:',
        'Beneficiary’s Name: DMF LOGISTICS DO BRASIL LTDA',
        'Beneficiary’s Address: RUA BORGES DE MEDEIROS 274 2º ANDAR',
        'Intermediary Bank: BANK OF AMERICA, N.A. - New York',
        'Swift: BOFAUS3N',
        'ACCOUNT NR: 6550921296',
        'Bank: BANCO BRADESCO SA - Porto Alegre',
        'Address: Pça. Osvaldo Cruz 10 4º andar - Centro - Porto Alegre - RS - Brasil',
        'Swift: BBDEBRSPSPO',
        'AGENCY: 1587',
        'ACCOUNT: 200526-3',
        'IBAN NUMBER: BR83 6074 6948 0732 7000 0005 266c 1',
    ];

    private const TYPES = ['e' => 'EXPORT', 'i' => 'IMPORT'];

    public function __construct(private AgentNoteLists $lists) {}

    public function __invoke(int $agentId, ?string $from, ?string $to, int $accountId): StreamedResponse
    {
        $pdf = $this->render($agentId, $from, $to, AgenteBanco::query()->findOrFail($accountId));

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'extrato-notas-agente.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render(int $agentId, ?string $from, ?string $to, AgenteBanco $account): TCPDF
    {
        $statement = $this->lists->statement($agentId, $from, $to);
        $summary = $this->summary($statement['dmf'], $statement['agent']);
        $agentName = (string) $account->nome_conta;

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics do Brasil');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(false, 7);
        $this->page($pdf, $agentName);

        foreach ([[$statement['dmf'], ''], [$statement['agent'], '- ']] as [$rows, $sign]) {
            foreach ($rows as $row) {
                $cells = [
                    $this->date($row['created_at']),
                    (string) $row['name'],
                    (string) $row['booking'],
                    (string) $row['house_bl'],
                    $sign.$row['symbol'].' '.number_format((float) $row['valor'], 2, ',', '.'),
                    (string) $row['shipper'],
                    $row['label'].'/'.(self::TYPES[$row['tipo_embarque']] ?? ''),
                ];
                $pdf->SetFont('Helvetica', '', 7);
                $lines = max(array_map(fn (string $text, int $i) => $pdf->getNumLines($text, self::WIDTHS[$i]), $cells, array_keys($cells)));
                $height = self::LINE * $lines;

                if ($pdf->GetY() + $height > self::PAGE_BOTTOM) {
                    $this->page($pdf, $agentName);
                }

                $pdf->SetFont('Helvetica', '', 7);
                $pdf->SetTextColor(0);
                $this->row($pdf, $cells, $height, ['R', 'L', 'L', 'L', 'R', 'L', 'L'], false);
            }
        }

        if ($pdf->GetY() > 210) {
            $this->page($pdf, $agentName, false);
        }

        $pdf->Ln(6);
        $this->total($pdf, '(A) TOTAL DUE TO '.$agentName, $summary['agent'], $summary['symbols']);
        $this->total($pdf, '(B) TOTAL DUE TO DMF LOGISTICS', $summary['dmf'], $summary['symbols']);
        $this->total($pdf, '(A + B) TOTAL AMOUNT DUE TO '.($summary['dueToDmf'] ? 'DMF LOGISTICS' : $agentName), $summary['net'], $summary['symbols']);

        $this->footer($pdf, $summary['dueToDmf'] ? self::DMF_BANK : [
            'BENEFICIARY: '.$agentName,
            'IBAN: '.$account->iban,
            'ACCOUNT WITH: '.$account->nome_banco,
            'BANK ADDRESS: '.$account->endereco_banco,
            'ACCOUNT NAME:  '.$account->nome_conta,
            'ACCOUNT NR:  '.$account->numero_conta,
            'SWIFT:  '.$account->swift,
            'BENEFICIARY ADDRESS:  '.$account->endereco_agente,
        ]);

        return $pdf;
    }

    /**
     * Totais por moeda de cada lado, última cotação de cada moeda e quem deve ao outro. O saldo é sempre o lado maior
     * menos o menor, moeda a moeda.
     *
     * @param  Collection<string, array<string, mixed>>  $dmf
     * @param  Collection<string, array<string, mixed>>  $agent
     * @return array{dmf: array<int, float>, agent: array<int, float>, net: array<int, float>, symbols: array<int, string>, dueToDmf: bool}
     */
    public function summary(Collection $dmf, Collection $agent): array
    {
        $totals = fn (Collection $rows): array => $rows->groupBy('moeda')->map(fn (Collection $group) => (float) $group->sum('valor'))->all();
        $dmfTotals = $totals($dmf);
        $agentTotals = $totals($agent);
        $currencies = array_unique([...array_keys($dmfTotals), ...array_keys($agentTotals)]);
        $rates = collect($currencies)->mapWithKeys(fn (int $currency) => [$currency => $this->lastRate($currency)]);
        $inReais = fn (array $totals): float => collect($totals)->sum(fn (float $value, int $currency) => $value * $rates[$currency]);
        $dueToDmf = $inReais($dmfTotals) >= $inReais($agentTotals);

        $net = [];

        foreach ($currencies as $currency) {
            $difference = ($dmfTotals[$currency] ?? 0) - ($agentTotals[$currency] ?? 0);
            $net[$currency] = $dueToDmf ? $difference : -$difference;
        }

        return [
            'dmf' => $dmfTotals,
            'agent' => $agentTotals,
            'net' => $net,
            'symbols' => $dmf->concat($agent)->pluck('symbol', 'moeda')->all(),
            'dueToDmf' => $dueToDmf,
        ];
    }

    /** Última cotação lançada da moeda (zero se não houver, como o legado). */
    private function lastRate(int $currency): float
    {
        return (float) DB::table('fin_currencies_rates_values as v')
            ->join('fin_currencies_rates as r', 'v.ref_currency_rate', '=', 'r.id')
            ->where('r.ref_currency', $currency)
            ->orderByDesc('v.id')
            ->value('v.rate');
    }

    private function page(TCPDF $pdf, string $agentName, bool $withTable = true): void
    {
        $pdf->AddPage();
        $pdf->Image(resource_path('images/logo_head_1.png'), 40, 15, 30);
        $pdf->SetTextColor(165, 29, 33);
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->MultiCell(120, self::LINE, 'AGENT: '.$agentName, 0, 'L', false, 1, 75, 20);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell(190, 5, now()->format('d/m/Y'), 0, 'R', false, 1, 10, 10);

        if (! $withTable) {
            $pdf->SetY(40);

            return;
        }

        $pdf->SetY(40);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetDrawColor(165, 29, 33);
        $this->row($pdf, ['Date', 'Invoice', 'Booking', 'HBL', 'Value', 'Customer', 'Type'], self::LINE, array_fill(0, 7, 'C'), true);
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>  $aligns
     */
    private function row(TCPDF $pdf, array $cells, float $height, array $aligns, bool $fill): void
    {
        $x = $pdf->getMargins()['left'];
        $y = $pdf->GetY();

        foreach ($cells as $i => $text) {
            $pdf->MultiCell(self::WIDTHS[$i], $height, $text, $i === 6 ? 'B' : 'RB', $aligns[$i], $fill, 0, $x, $y, true, 0, false, true, $height);
            $x += self::WIDTHS[$i];
        }

        $pdf->SetXY($pdf->getMargins()['left'], $y + $height);
    }

    /**
     * @param  array<int, float>  $values
     * @param  array<int, string>  $symbols
     */
    private function total(TCPDF $pdf, string $title, array $values, array $symbols): void
    {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(165, 29, 33);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->MultiCell(190, self::LINE, $title, 'TBR', 'L', true, 1, 10);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(0);

        foreach ($values as $currency => $value) {
            $pdf->MultiCell(190, 5, ($symbols[$currency] ?? '').' '.number_format($value, 2, ',', '.'), 0, 'R', false, 1, 10);
        }

        $pdf->Ln(4);
    }

    /** @param  list<string>  $bank */
    private function footer(TCPDF $pdf, array $bank): void
    {
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(0);

        if ($pdf->GetY() > 200) {
            $pdf->AddPage();
        }

        $y = max($pdf->GetY() + 8, 235);
        $pdf->MultiCell(90, 0, implode("\n", self::SENDER), 0, 'L', false, 0, 10, $y);
        $pdf->MultiCell(95, 0, implode("\n", $bank), 0, 'L', false, 1, 105, $y);
    }

    private function date(?string $value): string
    {
        return $value ? date('d/m/Y', strtotime($value)) : '';
    }
}
