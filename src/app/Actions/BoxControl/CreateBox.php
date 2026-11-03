<?php

namespace App\Actions\BoxControl;

use App\Models\ControleCaixa;

/**
 * novaCaixa (legado): cria a caixa aberta.
 *
 * Diferença do legado: tipo e nome são obrigatórios (o legado aceitava caixa sem nome, que a lista mostrava como "Não determinado.").
 */
class CreateBox
{
    public function __invoke(string $type, string $name): ControleCaixa
    {
        return ControleCaixa::create(['tipo_caixa' => $type, 'nome_caixa' => $name, 'status' => ControleCaixa::OPEN]);
    }
}
