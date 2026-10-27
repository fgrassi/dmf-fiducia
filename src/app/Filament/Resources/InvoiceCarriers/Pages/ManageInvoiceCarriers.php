<?php

namespace App\Filament\Resources\InvoiceCarriers\Pages;

use App\Filament\Resources\InvoiceCarriers\InvoiceCarrierResource;
use App\Models\Armador;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;

class ManageInvoiceCarriers extends ManageRecords
{
    protected static string $resource = InvoiceCarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('incluir')
                ->label('Incluir armador')
                ->icon('heroicon-o-plus')
                ->modalHeading('Incluir armador')
                ->modalSubmitActionLabel('Incluir')
                ->schema([
                    Select::make('armador_id')
                        ->label('Armador')
                        ->required()
                        ->searchable()
                        ->options(fn () => Armador::query()
                            ->whereNot(fn ($q) => InvoiceCarrierResource::enabled($q))
                            ->orderBy('nome')
                            ->pluck('nome', 'armador_id')),
                    InvoiceCarrierResource::operationsField(),
                ])
                ->action(function (array $data, Action $action) {
                    Armador::findOrFail($data['armador_id'])
                        ->forceFill(InvoiceCarrierResource::operations($data['operacoes']))
                        ->save();
                    $action->success();
                })
                ->successNotificationTitle('Armador incluído no controle de invoice.'),
        ];
    }
}
