<?php

namespace App\Filament\Resources\Couriers\Schemas;

use App\Models\ControleCourier;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/** showSuccess do legado: número, nota, data, tipo, conta e arquivo do courier. */
class CourierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Courrier')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('numero')
                            ->label('Número'),
                        TextEntry::make('nota')
                            ->label('Nota')
                            ->placeholder('N/D'),
                        TextEntry::make('data_recebimento')
                            ->label('Data de Recebimento')
                            ->date('d/m/Y'),
                        TextEntry::make('type.nome')
                            ->label('Tipo Courrier'),
                        TextEntry::make('conta_utilizada')
                            ->label('Conta')
                            ->formatStateUsing(fn (?string $state) => ControleCourier::ACCOUNTS[$state] ?? ''),
                        TextEntry::make('arquivo')
                            ->label('Arquivo')
                            ->placeholder('Nenhum arquivo cadastrado')
                            ->formatStateUsing(fn (string $state) => new HtmlString('<a href="'.e(Storage::disk('tracking_uploads')->url('courrier/'.rawurlencode($state))).'" target="_blank" class="underline">'.e($state).'</a>')),
                    ]),
            ]);
    }
}
