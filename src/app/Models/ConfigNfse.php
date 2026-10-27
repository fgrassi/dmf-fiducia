<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dados globais do emissor/API de NFS-e (config_nfse, registro único id 1). */
class ConfigNfse extends Model
{
    protected $table = 'config_nfse';

    protected $primaryKey = 'config_nfse_id';

    public $timestamps = false;

    protected $guarded = [];

    /** ConfigNfseTable::getAtual: cria o id 1 com os padrões do legado quando ainda não existe. */
    public static function current(): self
    {
        return static::firstOrCreate(['config_nfse_id' => 1], [
            'api_url' => 'http://localhost:8080/api/v1',
            'op_simp_nac' => '1',
            'reg_esp_trib' => '0',
            'serie_dps' => '1',
            'iss_retido' => 0,
            'validar_cep' => 1,
        ]);
    }
}
