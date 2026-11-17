<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use App\Models\Container;
use DomainException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * uploadDraft (legado): preenche o BL com a planilha de draft (aba 1: dados do BL; aba 2: containers, até a primeira linha em branco).
 * O que o BL tinha é substituído, containers inclusive. Como no legado, peso bruto e volume do BL não vêm da planilha
 * (o legado lia o índice errado da linha e gravava vazio) e só o peso líquido é lido.
 */
class ImportBlDraft
{
    /** Posições (linha, coluna 0-based) das células do draft. */
    private const CELLS = [
        'shipper' => [3, 0], 'bl' => [3, 20], 'mbl_number' => [5, 13],
        'consignee' => [8, 0], 'special_instr' => [8, 13],
        'notify' => [14, 0], 'representation' => [14, 13],
        'dmf_ref' => [19, 0], 'port_lading' => [19, 7],
        'vessel' => [21, 0], 'port_trans' => [21, 7],
        'port_disc' => [23, 0], 'place_delivery' => [23, 7],
        'mark_nos' => [26, 0], 'num_pkgs' => [26, 6],
        'net_weight' => [32, 20], 'obs' => [59, 0], 'freight_at' => [64, 0],
    ];

    private const DESCRIPTION_CELL = [26, 8];

    private const FIRST_CONTAINER_ROW = 4;

    /** @throws DomainException */
    public function __invoke(Bl $bl, string $path): void
    {
        try {
            $book = IOFactory::load($path);
        } catch (Throwable $e) {
            throw new DomainException('Não foi possível ler o arquivo: '.$e->getMessage());
        }

        $draft = $book->getSheet(0);
        $cell = fn (array $position): ?string => $this->text($draft->rangeToArray("A{$position[0]}:Z{$position[0]}", null, true, false)[0][$position[1]] ?? null);

        $data = collect(self::CELLS)->map($cell)->all();
        $data['desc_pkgs'] = $cell(self::DESCRIPTION_CELL).BlDefaults::DESCRIPTION;
        $data['gross_weight'] = null;
        $data['volume'] = null;

        if (blank($data['bl'])) {
            unset($data['bl']);
        }

        DB::transaction(function () use ($bl, $data, $book) {
            $bl->fill($data)->save();
            Container::where('bl_id', $bl->getKey())->delete();

            $sheet = $book->getSheet(1);

            for ($row = self::FIRST_CONTAINER_ROW; strlen((string) ($line = $sheet->rangeToArray("A{$row}:J{$row}", null, true, false)[0])[0]) > 0; $row++) {
                $type = DB::table('tipo_container')->whereRaw('trim(nome) = ?', [trim((string) $line[0])])->value('tipo_container_id');

                if (! $type) {
                    throw new DomainException('Tipo de container não cadastrado: '.trim((string) $line[0]));
                }

                Container::create([
                    'bl_id' => $bl->getKey(),
                    'tipo_container_id' => $type,
                    'numero' => $this->text($line[1]),
                    'tara' => $this->text($line[2]),
                    'seals_n1' => $this->text($line[3]),
                    'seals_n2' => $this->text($line[4]),
                    'seals_n3' => $this->text($line[5]),
                    'qty_pkgs' => number_format((float) $line[6], 4, ',', ''),
                    'gross_weight' => number_format((float) $line[7], 4, ',', ''),
                    'net_weight' => number_format((float) $line[8], 4, ',', ''),
                    'volume' => number_format((float) $line[9], 4, ',', ''),
                ]);
            }
        });
    }

    private function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
