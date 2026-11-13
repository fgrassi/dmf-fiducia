<?php

namespace App\Filament\Actions;

use App\Actions\Maintenance\MergeRecords;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

class MergeRecordsAction
{
    /** "Unificar" do legado (manutencao): nas linhas selecionadas, escolhe a que permanece; as demais são migradas para ela e apagadas. Só admin. */
    public static function make(string $entity, string $label = 'nome'): BulkAction
    {
        return BulkAction::make('merge')
            ->label('Unificar selecionados')
            ->icon(Heroicon::OutlinedArrowsPointingIn)
            ->color('gray')
            ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('admin'))
            ->modalHeading('Unificar cadastros')
            ->modalDescription('Escolha o cadastro que permanece. Os demais selecionados são apagados e tudo que apontava para eles passa para o escolhido. Não dá para desfazer.')
            ->modalSubmitActionLabel('Unificar')
            ->schema(fn (Collection $records) => [
                Select::make('target')
                    ->label('Destino')
                    ->options($records->mapWithKeys(fn ($record) => [$record->getKey() => "{$record->getKey()} - {$record->{$label}}"]))
                    ->required(),
            ])
            ->action(function (Collection $records, array $data) use ($entity) {
                $merged = app(MergeRecords::class)($entity, $records->modelKeys(), (int) $data['target']);

                Notification::make()
                    ->title($merged ? "{$merged} registro(s) unificado(s)" : 'Nada a unificar')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
