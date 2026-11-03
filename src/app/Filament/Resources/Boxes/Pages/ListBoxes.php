<?php

namespace App\Filament\Resources\Boxes\Pages;

use App\Actions\BoxControl\CreateBox;
use App\Filament\Resources\Boxes\BoxResource;
use App\Models\ControleCaixa;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBoxes extends ListRecords
{
    protected static string $resource = BoxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nova')
                ->label('Incluir Nova Caixa')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Nova Caixa')
                ->modalWidth('md')
                ->schema([
                    Select::make('tipo_caixa')
                        ->label('Tipo')
                        ->options(ControleCaixa::TYPES)
                        ->required(),
                    TextInput::make('nome_caixa')
                        ->label('Nome Caixa')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data, CreateBox $create) {
                    $create($data['tipo_caixa'], $data['nome_caixa']);
                    Notification::make()->title('Caixa criada.')->success()->send();
                }),
        ];
    }
}
