<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Equipamento de um BL (container, legado): o que é declarado na aprovação do BL. */
class Container extends Model
{
    /** tipo_container_id do LCL (carga solta), que o BL confere com o volume da reserva. */
    public const LCL = 20;

    /** Tipos REEFER (TemperaturaReeferNotification::TIPO_CONTAINER_REEFER_IDS, legado): exigem temperatura na exportação. */
    public const REEFER_TYPES = [8, 10, 19, 39];

    protected $table = 'container';

    protected $primaryKey = 'container_id';

    public $timestamps = false;

    protected $guarded = [];

    public static function isReefer(int|string|null $typeId): bool
    {
        return in_array((int) $typeId, self::REEFER_TYPES, true);
    }
}
