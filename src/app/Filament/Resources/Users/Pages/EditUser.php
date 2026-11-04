<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SaveUser;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $id = $data['usuario_id'];

        return [
            ...$data,
            'senha' => null,
            'credentials' => $this->getRecord()->credentialList(),
            'categorias' => DB::table('usuario_frase_categoria')->where('ref_usuario', $id)->pluck('ref_categoria_frase')->all(),
            'vinculos' => DB::table('pessoa_usuario as pu')
                ->leftJoin('pessoa_usuario_tipo_usuario as putu', 'putu.pessoa_usuario_id', '=', 'pu.pessoa_usuario_id')
                ->where('pu.usuario_id', $id)
                ->orderBy('pu.pessoa_usuario_id')
                ->get(['pu.pessoa_id', 'pu.cargos_id', 'putu.tipo_usuario_id'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SaveUser::class)($data, $record);
    }
}
