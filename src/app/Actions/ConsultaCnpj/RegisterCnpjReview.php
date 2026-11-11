<?php

namespace App\Actions\ConsultaCnpj;

use App\Models\ConsultaCnpj;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** consultaCnpj/editStatus (legado): desativa as consultas da empresa e grava a nova, com a validade calculada pela quinzena. */
class RegisterCnpjReview
{
    public function __invoke(int $companyId, int $status, ?string $note, int $userId): ConsultaCnpj
    {
        return DB::transaction(function () use ($companyId, $status, $note, $userId) {
            ConsultaCnpj::query()->where('pessoa_id', $companyId)->where('ativo', 1)->update(['ativo' => 0]);

            return ConsultaCnpj::create([
                'pessoa_id' => $companyId,
                'data_consulta' => $this->validUntil($status),
                'status' => $status,
                'observacao' => $note,
                'created_by' => $userId,
                'created_at' => now(),
                'ativo' => 1,
            ]);
        });
    }

    /** OK vale 90 dias, os demais 60; o prazo sempre cai no dia 15 ou no fim do mês, alternando conforme a quinzena de hoje. */
    public function validUntil(int $status): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $firstHalf = $today->day <= 15;

        if ($status === ConsultaCnpj::OK) {
            $date = $today->addDays(90);

            return $firstHalf ? $date->day(15) : $date->endOfMonth()->startOfDay();
        }

        $date = $today->addDays(60);

        return $firstHalf ? $date->endOfMonth()->startOfDay() : $date->day(15);
    }
}
