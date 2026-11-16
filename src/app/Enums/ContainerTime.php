<?php

namespace App\Enums;

use App\Models\FinDemurrage;
use App\Models\FinDetention;
use Illuminate\Database\Eloquent\Model;

/** Controle de equipamento por container: demurrage (importação, na chegada) ou detention (exportação, na saída). */
enum ContainerTime: string
{
    case Demurrage = 'demurrage';
    case Detention = 'detention';

    /** @return class-string<FinDemurrage|FinDetention> */
    public function model(): string
    {
        return $this === self::Demurrage ? FinDemurrage::class : FinDetention::class;
    }

    public function label(): string
    {
        return $this === self::Demurrage ? 'Demurrage' : 'Detention';
    }

    /** Operação da reserva a que a aba se aplica (_show_tab do legado). */
    public function operation(): string
    {
        return $this === self::Demurrage ? 'i' : 'e';
    }

    /** Coluna da reserva com os dias de free time do tipo. */
    public function bookingFreeTimeColumn(): string
    {
        return $this === self::Demurrage ? 'free_time' : 'free_time_detention';
    }

    /** Coluna do porto da reserva: o de descarga no demurrage, o de origem no detention. */
    public function portColumn(): string
    {
        return $this === self::Demurrage ? 'porto_destino_id' : 'porto_origem_id';
    }

    public function newRecord(): Model
    {
        return new ($this->model());
    }
}
