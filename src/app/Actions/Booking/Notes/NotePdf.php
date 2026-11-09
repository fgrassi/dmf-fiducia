<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use TCPDF;

/**
 * geraPdfDebitNote / geraPdfCreditNote (legado): PDF A4 da nota da reserva para o agente, em milímetros como o legado.
 * O débito não define a altura de linha (`$ln` indefinido: altura automática) até o rodapé; o crédito usa 7. O crédito
 * criado pela importação sai como "INVOICE". A versão "sem valores" (crédito) esconde a descrição e as parcelas da DMF.
 */
class NotePdf
{
    /** Cipelog SAS: o PDF leva o NIT depois do nome. */
    private const CIPELOG = 11512;

    private const SPACE = 4;

    public function __construct(private RecalculateNote $recalculate) {}

    public function render(NoteType $type, int $noteId, bool $hideValues = false): TCPDF
    {
        $note = DB::table($type->table())->find($noteId);
        $booking = DB::table('embarque')->where('embarque_id', $note->ref_embarque)->first();
        $agent = DB::table('pessoa as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.paises_id')->where('p.pessoa_id', $note->ref_agente)->first(['p.nome', 'p.cnpj', 'p.endereco', 'c.nome as country']) ?? (object) ['nome' => '', 'cnpj' => '', 'endereco' => '', 'country' => ''];
        $symbol = (string) DB::table('cmn_currencies')->where('id', $note->moeda_id)->value('symbol');
        $debit = $type === NoteType::Debit;
        $export = $booking->impor_expor !== 'i';
        $hasHandling = $this->hasHandling($type, $noteId);
        $lineHeight = $debit ? 0 : 7;
        $title = $type->label();

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle("DMF Logistics - {$title}");
        $pdf->SetSubject($title);
        $pdf->SetKeywords($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->setMargins(20, 10);
        $pdf->SetAutoPageBreak(true, 7);

        $font = fn (string $style, float $size) => $pdf->SetFont('Helvetica', $style, $size);
        $cell = fn (float $w, string $text, float $h = 0, int|string $border = 0, float|string $x = '', float|string $y = '') => $pdf->MultiCell($w, $h, $text, $border, 'L', false, 0, $x, $y, true);
        $space = fn (int $times = 1) => $pdf->Ln(self::SPACE * $times);
        $money = fn (float $value) => $symbol.' '.number_format($value, 2, ',', '.');
        $line = function (string $label, string $value, float $fs, bool $wide = false) use ($font, $cell, $lineHeight, $space) {
            $font('B', $fs);
            $cell($wide ? 110 : 100, $label, $lineHeight);
            $cell($wide ? 15 : 25, ' ', $lineHeight);
            $font('', $fs);
            $cell(25, $value, $lineHeight);
            $space();
        };

        $fs = 9;
        $pdf->Ln(20);
        $pdf->Image(resource_path('images/logo.png'), 15, 10, 30);
        $font('B', 10);
        $cell(100, 'DMF LOGISTICS DO BRASIL LTDA', $lineHeight, 0, 60, 10);

        $dueDate = $this->dueDate($debit ? $note->data_vencimento : null, $booking->tipo_embarque);
        $etd = Carbon::parse($booking->saida_efetiva ?: $booking->saida_prevista ?: now())->format('d/m/Y');
        $created = Carbon::parse($note->created_at)->format('d/m/Y');
        $vessel = (string) ($booking->navio_id ? DB::table('navio')->where('navio_id', $booking->navio_id)->value('nome') : '');
        $person = fn ($id) => (string) DB::table('pessoa')->where('pessoa_id', $id)->value('nome');
        $seller = (string) DB::table('vendedor')->where('vendedor_id', $booking->vendedor_id)->value('nome');
        $rate = $symbol.' '.number_format((float) $note->dolar_rate, 4, ',', '.');
        $to = (int) $note->ref_agente === self::CIPELOG ? "{$agent->nome}, NIT:{$agent->cnpj}" : (string) $agent->nome;
        $containers = $this->containers($booking->embarque_id, $debit);

        if ($debit) {
            $font('B', 7);
            $cell(30, 'DUE DATE: '.$dueDate, 0, 1, 150, 20);
            $pdf->Ln(5);
            $font('B', $fs);
            $cell(25, 'Date:');
            $font('', $fs);
            $cell(45, $created);
            $space();

            $height = max($this->height($pdf, 45, (string) $booking->house_bl), $this->height($pdf, 30, (string) $booking->invoice));
            $y = $pdf->GetY();
            $font('B', $fs);
            $cell(25, 'HBL:', $height);
            $font('', $fs);
            $cell(45, (string) $booking->house_bl, $height);
            $font('B', $fs);
            $cell(30, 'Customer Invoice:', $height);
            $font('', $fs);
            $cell(25, (string) $booking->invoice, $height);
            $font('B', 12);
            $cell(45, "DEBIT NOTE\n{$note->name}", 0, 1, 150, $y);

            $pdf->SetY($y + $height + 5);
            $font('B', $fs);
            $cell(60, 'Booking: '.$booking->booking);
            $space();
            $cell(15, 'Vessel:');
            $font('', $fs);
            $cell(50, $vessel);
            $pdf->Ln();

            $font('B', $fs);
            $cell(10, 'ETD:');
            $font('', $fs);
            $cell(25, $etd);
            $font('B', $fs);
            $cell(25, 'Tx. Currency:');
            $font('', $fs);
            $cell(25, $rate);
            $space();
        } else {
            $pdf->Ln(15);
            $font('B', $fs);
            $cell(25, 'Date:', 7);
            $font('', $fs);
            $cell(45, $created, 7);
            $space();
            $font('B', $fs);
            $cell(25, 'HBL:', 7);
            $font('', $fs);
            $cell(45, (string) $booking->house_bl, 7);
            $font('B', 12);
            $cell(45, ($export ? 'CREDIT NOTE' : 'INVOICE')."\n{$note->name}", 7, 1, 150);
            $space(3);
            $font('B', $fs);
            $cell(40, 'Customer Invoice:', 7);
            $space(2);
            $font('', $fs);
            $cell(100, (string) $booking->invoice, 7);
            $space();
            $font('B', $fs);
            $cell(60, 'Booking: '.$booking->booking, 7);
            $space();
            $cell(15, 'Vessel:', 7);
            $font('', $fs);
            $cell(40, $vessel, 7);
            $font('B', $fs);
            $cell(10, 'ETD:', 7);
            $font('', $fs);
            $cell(25, $etd, 7);
            $font('B', $fs);
            $cell(25, 'Tx. Currency:', 7);
            $font('', $fs);
            $cell(25, $rate, 7);
            $space(2);
        }

        $font('B', $fs);
        $cell(15, 'Shipper:', $lineHeight);
        $font('', $fs);
        $cell(120, $person($booking->pessoa_id_cliente), $lineHeight);
        $space();
        $font('B', $fs);
        $cell(15, 'Cnee:', $lineHeight);
        $font('', $fs);
        $cell(120, $person($booking->pessoa_id_importador), $lineHeight);
        $space();

        $font('B', $fs);

        if ($debit) {
            $cell(15, 'Cnts:');
            $font('', $fs);
            $cell(120, $containers['list']);
            $pdf->Ln($containers['space']);
            $font('B', $fs);
            $cell(15, 'TO:');
        } else {
            $containerHeight = $containers['list'] === '' ? 0 : $this->height($pdf, 120, $containers['list']);
            $cell(15, 'Cnts:', $containerHeight);
            $font('', $fs);
            $cell(120, $containers['list'], $containerHeight);
            $pdf->Ln();
            $font('B', 8);
            $cell(15, 'TO:', 7);
        }

        $cell(100, $to, $lineHeight);
        $font('B', $fs);
        $cell(45, $seller, $lineHeight, 1, 150);
        $space();
        $font('', $fs);
        $this->address($pdf, $agent, $lineHeight);
        $space();
        $font('B', $fs);
        $cell(15, 'Attn::', $lineHeight);
        $font('', $fs);
        $cell(45, 'FINANCIAL DEPARTMENT', $lineHeight);

        if ($debit) {
            $font('B', 8);
            $space();
            $font('B', 1);
            $space();
            $pdf->MultiCell(175, 1, ' ', 1, 'L', false, 0, '', '', true);
        } else {
            $space(3);
            $font('B', 2);
            $pdf->MultiCell(175, 1, ' ', 1, 'L', false, 0, '', '', true);
            $space(2);
            $fs = 8;
        }

        $items = $this->items($type, $noteId);
        $showDetails = ! $hideValues;
        $descriptionColumns = $debit ? [10, 70, 20] : [10, 80, 10];

        if (! $hasHandling && $showDetails) {
            if ($debit) {
                $space();
                $font('B', $fs);
                $fs = 8;
            }

            $font('B', $fs);
            $cell(100, 'DESCRIPTION', $lineHeight);
            $cell(25, 'DEBIT', $lineHeight);
            $cell(25, 'CREDIT', $lineHeight);
            $space();
            $cell(45, 'M B/L', $lineHeight);
            $space();

            $purchase = [];
            $sale = [];

            foreach ($items as $item) {
                $amount = $item->buying_rate * $item->factor;
                $font('B', $fs);
                $cell($descriptionColumns[0], ' ', $lineHeight);
                $cell($descriptionColumns[1], (string) $item->name, $lineHeight);
                $font('', $fs);
                $cell($descriptionColumns[2], $this->billing($item->billing), $lineHeight);
                $cell(25, $item->buying_symbol.' '.number_format($amount, 2, ',', '.'), $lineHeight);
                $space();
                $purchase[$item->buying_symbol] = ($purchase[$item->buying_symbol] ?? 0) + $amount;
            }

            $font('B', $fs);
            $cell(45, 'H B/L', $lineHeight);
            $space();

            foreach ($items as $item) {
                $amount = $item->selling_rate * $item->factor;
                $font('B', $fs);
                $cell($descriptionColumns[0], ' ', $lineHeight);
                $cell($descriptionColumns[1], (string) $item->name, $lineHeight);
                $font('', $fs);
                $cell($descriptionColumns[2], $this->billing($item->billing), $lineHeight);
                $cell(25, ' ', $lineHeight);
                $cell(25, $item->selling_symbol.' '.number_format($amount, 2, ',', '.'), $lineHeight);
                $space();

                if ($pdf->GetY() > 280) {
                    $pdf->AddPage();
                    $pdf->setMargins(20, 10);
                }

                $sale[$item->selling_symbol] = ($sale[$item->selling_symbol] ?? 0) + $amount;
            }

            $font('B', $fs);
            $cell(100, 'TOTAL', $lineHeight);

            if ($debit) {
                $y = $pdf->GetY();

                foreach ($purchase as $currency => $value) {
                    $cell(25, $currency.' '.number_format($value, 2, ',', '.'), $lineHeight, 0, 120);
                    $pdf->Ln();
                }

                $pdf->SetY($y);

                foreach ($sale as $currency => $value) {
                    $cell(25, $currency.' '.number_format($value, 2, ',', '.'), $lineHeight, 0, 145);
                    $pdf->Ln();
                }
            } else {
                $cell(25, $money(array_sum($purchase)), $lineHeight);
                $cell(25, $money(array_sum($sale)), $lineHeight);
            }

            $space();
        }

        $space();

        $profit = (float) $note->gross_profit;
        $exw = (float) $note->exw_expenses;
        $dueToDmf = (float) $note->due_to_dmf;
        $dueToAgent = (float) $note->due_to_dmf_agent;
        $dueToCnee = (float) $note->due_to_cnee_agent;
        $dueToShipper = (float) $note->due_to_shipper_agent;
        $handling = $debit ? (float) $note->handling : 0.0;
        $originCommission = $debit ? (float) $note->origin_comission : 0.0;
        $commission = $debit ? $this->commission((int) $booking->embarque_id) : null;
        $agentName = (string) $agent->nome;

        if (! $hasHandling && $profit != 0 && $showDetails) {
            $line('TOTAL HANDLING FEE', $money($profit), $fs);
        }

        if ($debit && $handling != 0) {
            $line('HANDLING', $money($handling), $fs);
        }

        if ($exw != 0 && $showDetails) {
            $line('EXW EXPENSES AT ORIGIN:', $money($exw), $fs);
        }

        if ($dueToDmf != 0 && $showDetails) {
            $line('DUE TO DMF LOGISTICS DO BRASIL LTDA:', $money($dueToDmf), $fs);
        }

        if ($dueToAgent != 0) {
            $line("DUE TO {$agentName}: ", $money($dueToAgent), $fs, $debit);
        }

        if ($dueToCnee != 0 && $showDetails) {
            $line('DUE TO CNEE AGENT: ', $money($dueToCnee), $fs);
        }

        if ($dueToShipper != 0 && $showDetails) {
            $line('DUE TO SHIPPER AGENT: ', $money($dueToShipper), $fs);

            if (! $debit) {
                $space();
            }
        }

        if ($commission !== null) {
            $line('COMMISSION: ', $money($commission), $fs);
        }

        if ($debit && $originCommission != 0) {
            $line('ORIGIN COMMISSION: ', $money($originCommission), $fs);
            $space(2);
        }

        if ($hasHandling) {
            $space();
            $font('B', $fs);
            $cell(100, ($debit ? 'TOTAL DUE TO DMF LOGISTICS DO BRASIL LTDA: ' : "TOTAL DUE TO {$agentName}: "), $lineHeight);
            $cell(25, ' ', $lineHeight);
            $font('', $fs);
            $total = $debit
                ? $profit + $handling + $exw + $dueToCnee + $dueToShipper + (float) $commission + $originCommission
                : $this->handlingSales($type, $noteId);
            $cell(25, $money($total), $lineHeight);
        } elseif ($debit && $export) {
            $space();
            $font('B', $fs);
            $cell(100, 'TOTAL DUE TO DMF LOGISTICS DO BRASIL LTDA: ', $lineHeight);
            $cell(25, ' ', $lineHeight);
            $font('', $fs);
            $cell(25, $money($dueToDmf + $handling + $originCommission + $dueToShipper + $exw), $lineHeight);
        } else {
            if ($debit) {
                $label = "TOTAL DUE TO {$agentName}: ";
                $value = $dueToAgent;
            } elseif ($export) {
                $space();
                $label = "TOTAL DUE TO {$agentName}: ";
                $value = $dueToAgent + $dueToCnee;
            } else {
                $label = 'TOTAL DUE TO DMF LOGISTICS: ';
                $value = $dueToDmf;
            }

            $font('B', $fs);
            $cell(100, $label, $lineHeight);
            $cell(25, ' ', $lineHeight);
            $font('', $fs);
            $cell(25, $money($value), $lineHeight);
        }

        $space();
        $cell(100, 'DUE DATE: '.($debit ? $dueDate : $this->dueDate(null, $booking->tipo_embarque)), $lineHeight);
        $space(3);
        $font('B', $debit ? 1 : 2);
        $pdf->MultiCell(175, 1, ' ', 1, 'L', false, 0, '', '', true);
        $pdf->Ln(5);
        $font('', 7);
        $cell(160, (string) $note->obs, $lineHeight);
        $pdf->Ln(10);

        $y = $pdf->GetY() + $pdf->getLastH();
        $pdf->SetY($y);

        if ($y + 40 > 280) {
            $pdf->AddPage();
            $pdf->setMargins(20, 10);
        }

        $this->footer($pdf, $debit);

        return $pdf;
    }

    /** Nome do arquivo (sem extensão), como o legado: DN/CN + id com 4 dígitos + ano da nota + reserva. */
    public function fileName(NoteType $type, int $noteId): string
    {
        $note = DB::table($type->table())->find($noteId);
        $booking = (string) DB::table('embarque')->where('embarque_id', $note->ref_embarque)->value('booking');
        $prefix = $type->abbreviation().str_pad((string) $noteId, 4, '0', STR_PAD_LEFT).'-'.Carbon::parse($note->created_at)->format('Y').' - ';

        // removeAcentos (só o débito): sem acento, espaço vira "_" e o resto fora de [A-Za-z0-9_] some. No crédito o legado usava a reserva crua.
        return $prefix.($type === NoteType::Debit
            ? preg_replace('/[^A-Za-z0-9_]/', '', strtr(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $booking) ?: $booking, ' ', '_'))
            : str_replace(['/', '\\'], '_', $booking));
    }

    /** NoteItems::getFator por item; no débito as despesas EXW ficam de fora (vão em "EXW EXPENSES"). */
    private function items(NoteType $type, int $noteId): Collection
    {
        return DB::table("{$type->itemsTable()} as i")
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->leftJoin('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('cmn_currencies as b', 'b.id', '=', 'v.ref_currency_br')
            ->leftJoin('cmn_currencies as s', 's.id', '=', 'v.ref_currency_sr')
            ->where("i.{$type->itemColumn()}", $noteId)
            ->when($type === NoteType::Debit, fn ($q) => $q->where('v.flag_exw', 0))
            ->orderBy('i.id')
            ->get(['v.*', 'e.name', 'e.ref_unity as unity', 'b.symbol as buying_symbol', 's.symbol as selling_symbol'])
            ->map(function (object $v) {
                $v->factor = $this->recalculate->factor($v);
                $v->buying_symbol ??= '';
                $v->selling_symbol ??= '';

                return $v;
            });
    }

    private function hasHandling(NoteType $type, int $noteId): bool
    {
        return DB::table("{$type->itemsTable()} as i")
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where("i.{$type->itemColumn()}", $noteId)
            ->whereIn('v.ref_expense', [1612, 1613])
            ->exists();
    }

    /** getValorDespesasHandlingDMF: soma a venda das despesas de handling da nota (sem fator). */
    private function handlingSales(NoteType $type, int $noteId): float
    {
        return (float) DB::table("{$type->itemsTable()} as i")
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where("i.{$type->itemColumn()}", $noteId)
            ->whereIn('v.ref_expense', [1612, 1613])
            ->sum('v.selling_rate');
    }

    /** Compra da comissão (despesa 239) da reserva vezes o fator dela; null quando a reserva não tem. */
    private function commission(int $bookingId): ?float
    {
        $expense = DB::table('fin_expenses_values as v')
            ->leftJoin('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->where('v.ref_embarque', $bookingId)
            ->where('v.ref_expense', 239)
            ->orderBy('v.id')
            ->first(['v.*', 'e.ref_unity as unity']);

        return $expense ? $expense->buying_rate * $this->recalculate->factor($expense) : null;
    }

    /** @return array{list: string, space: float} containers do BL, agrupados por tipo ("2 x 40HC"); no crédito, o LCL lista um a um com peso e volume */
    private function containers(int $bookingId, bool $debit): array
    {
        $rows = DB::table('container as c')
            ->join('bl as b', 'b.bl_id', '=', 'c.bl_id')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
            ->where('b.embarque_id', $bookingId)
            ->orderBy('b.bl_id')
            ->orderBy('c.container_id')
            ->get(['t.tipo_container_id as type_id', 't.nome as name', 'c.gross_weight', 'c.volume']);
        $hasBl = DB::table('bl')->where('embarque_id', $bookingId)->exists();

        if (! $debit && $rows->contains('type_id', 20)) {
            return [
                'list' => $rows->map(fn (object $c) => '1 x '.$c->name.' ('.number_format((float) $c->gross_weight, 2, ',', '.').'kg / '.number_format((float) $c->volume, 2, ',', '.')."m³)\n")->implode(''),
                'space' => 0,
            ];
        }

        return [
            'list' => $rows->countBy('name')->map(fn (int $count, string $name) => "{$count} x {$name} \n")->implode(''),
            'space' => $hasBl ? $rows->count() * 3.5 : 2.5,
        ];
    }

    /** imprimeEnderecoPaisAgenteNota: endereço do agente (se houver) e o país, abaixo do nome. */
    private function address(TCPDF $pdf, object $agent, float $lineHeight): void
    {
        $address = trim((string) $agent->endereco);

        if ($address !== '') {
            $height = max($this->height($pdf, 100, $address), $lineHeight);
            $pdf->MultiCell(15, $height, '', 0, 'L', false, 0, '', '', true);
            $pdf->MultiCell(100, $height, $address, 0, 'L', false, 0, '', '', true);
            $pdf->Ln();
        }

        $pdf->MultiCell(15, $lineHeight, '', 0, 'L', false, 0, '', '', true);
        $pdf->MultiCell(100, $lineHeight, (string) $agent->country, 0, 'L', false, 0, '', '', true);
    }

    /** DMFUtil::getStringHeight: altura que o texto ocupa numa célula de largura $w, medida numa cópia do PDF. */
    private function height(TCPDF $pdf, float $w, string $text): float
    {
        $copy = clone $pdf;
        $copy->AddPage();
        $copy->SetY(0);
        $copy->MultiCell($w, 3, $text);

        return ceil($copy->GetY());
    }

    private function dueDate(?string $date, ?string $bookingType): string
    {
        return filled($date)
            ? Carbon::parse($date)->format('d/m/Y')
            : now()->addDays($bookingType === 'm' ? 30 : 7)->format('d/m/Y');
    }

    /** FinExpensesValues::getBillingName. */
    private function billing(?string $billing): string
    {
        return ['a' => 'PA', 'c' => 'CC', 'p' => 'PP', 's' => 'SEG', 'f' => 'FI'][$billing] ?? '';
    }

    private function footer(TCPDF $pdf, bool $debit): void
    {
        $leftWidth = $debit ? 100 : 120;
        $swift = $debit ? 'Swift' : 'SWIFT';
        $y = $pdf->GetY();
        $pdf->SetFont('Helvetica', '', 7);

        foreach (['DMF LOGISTICS DO BRASIL LTDA', 'ADDRESS: RUA BORGES DE MEDEIROS 274 2º ANDAR', 'SANTA CRUZ DO SUL - RS - BRAZIL - ZIP: 96810-034', 'PHONE:  +55(51) 3717-8200  ', 'TAX ID  07.052.088/0001-34 '] as $i => $text) {
            $pdf->MultiCell($leftWidth, 4, $text, 0, 'L', false, 0, '', $i === 0 ? $y : '', true);
            $pdf->Ln();
        }

        $bank = [
            'OUR BANK DETAILS:',
            'Beneficiary’s Name: DMF LOGISTICS DO BRASIL LTDA ',
            'Beneficiary’s Address: RUA BORGES DE MEDEIROS 274 2º ANDAR ',
            'Intermediary Bank: BANK OF AMERICA, N.A. - New York ',
            "{$swift}: BOFAUS3N ",
            'ACCOUNT NR: 6550921296 ',
            'Bank: BANCO BRADESCO SA - Porto Alegre ',
            'Address: Pça. Osvaldo Cruz 10 4º andar - Centro - Porto Alegre - RS - Brasil ',
            "{$swift}: BBDEBRSPSPO ",
            'AGENCY: 1587 ',
            'ACCOUNT: 200526-3 ',
            'IBAN NUMBER: BR83 6074 6948 0732 7000 0005 266c 1 ',
        ];

        foreach ($bank as $i => $text) {
            $pdf->MultiCell($leftWidth, 4, $text, 0, 'L', false, 0, 105, $i === 0 ? $y : '', true);

            if ($i < count($bank) - 1 || ! $debit) {
                $pdf->Ln();
            }
        }
    }
}
