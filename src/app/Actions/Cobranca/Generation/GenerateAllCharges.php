<?php

namespace App\Actions\Cobranca\Generation;

use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * executeGeraCobranca (legado, botão "Gerar manualmente" da listagem e cron): gera as ICs de todas as reservas com
 * despesas pendentes (ctr_desp = 1), a partir das confirmações de cada modal, e as de handling.
 * A trava é a mesma do legado (configuracoes id 5), então legado e sistema novo nunca rodam juntos.
 */
class GenerateAllCharges
{
    private const LOCK_ID = 5;

    /** Importações só entram até este horário. */
    private const IMPORT_CUTOFF = '23:00';

    /** [tabela da confirmação, filtro do embarque, só confirmações com control = 1] */
    private const SOURCES = [
        'arrival_confirmation' => [['impor_expor' => 'i'], false],
        'departure_confirmation' => [['impor_expor' => 'e', 'tipo_embarque' => ['m', 'a']], false],
        'insurance_confirmation' => [['tipo_embarque' => 's'], true],
        'desembaraco_confirmation' => [['tipo_embarque' => 'd'], true],
        'correio_confirmation' => [['tipo_embarque' => 'r'], true],
    ];

    public function __construct(private GenerateCharges $generate) {}

    /** @return int ICs criadas */
    public function __invoke(?int $userId): int
    {
        if (! DB::table('configuracoes')->where('configuracoes_id', self::LOCK_ID)->where('valor', '0')->update(['valor' => '1'])) {
            throw new DomainException('Já ha um processo para gerar cobranças em andamento. Por favor, tente mais tarde!');
        }

        try {
            $created = ($this->generate)($this->sources(), null, $userId);

            $handling = Embarque::where('tipo_embarque', 'h')->where('ctr_desp', 1)->orderBy('embarque_id')->get();

            return $created + $this->generate->handling($handling, $userId);
        } finally {
            DB::table('configuracoes')->where('configuracoes_id', self::LOCK_ID)->update(['valor' => '0']);
        }
    }

    /**
     * Uma origem por confirmação, como no legado (marcar a confirmação com control = 1 quando a IC sai).
     * ponytail: o legado chamava a geração uma vez por modal (um resumo/digest por modal); aqui é uma rodada só.
     *
     * @return list<array{0:int, 1:\Closure}>
     */
    private function sources(): array
    {
        $sources = [];

        foreach (self::SOURCES as $table => [$filters, $controlled]) {
            if ($table === 'arrival_confirmation' && now()->format('H:i') > self::IMPORT_CUTOFF) {
                continue;
            }

            $rows = DB::table("{$table} as c")
                ->join('embarque as e', 'e.embarque_id', '=', 'c.ref_embarque')
                ->where('e.ctr_desp', 1)
                ->where('e.finalizado', '<>', 'c')
                ->when($controlled, fn ($q) => $q->where('c.control', 1))
                ->tap(function ($q) use ($filters) {
                    foreach ($filters as $column => $value) {
                        is_array($value) ? $q->whereIn("e.{$column}", $value) : $q->where("e.{$column}", $value);
                    }
                })
                ->orderBy('c.id')
                ->get(['c.id', 'c.ref_embarque']);

            foreach ($rows as $row) {
                $sources[] = [(int) $row->ref_embarque, fn () => DB::table($table)->where('id', $row->id)->update(['control' => 1])];
            }
        }

        return $sources;
    }
}
