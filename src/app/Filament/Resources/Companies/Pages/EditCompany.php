<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Actions\Companies\DeleteCompany;
use App\Actions\Companies\SaveCompany;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Pessoa;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Pessoa $record) => app(DeleteCompany::class)($record))
                ->failureNotificationTitle('Empresa em uso por outros registros: não pode ser excluída'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $id = $data['pessoa_id'];

        return [
            ...$data,
            'estados_id' => DB::table('cidades')->where('cidades_id', $data['cidades_id'])->value('estados_id'),
            'pessoa_sede' => DB::table('pessoa_sede')->where('pessoa_id', $id)->value('sede_id'),
            'tipo_empresa' => DB::table('pessoa_tipo_pessoa')->where('pessoa_id', $id)->where('tipo_pessoa_id', '<>', 1)->pluck('tipo_pessoa_id')->all(),
            'armadores_permitidos' => DB::table('pessoa_armador')->where('pessoa_id', $id)->pluck('armador_id')->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SaveCompany::class)($data, auth()->id(), $record);
    }
}
