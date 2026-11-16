<?php

namespace App\Filament\Support;

use App\Enums\ContainerTime;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\DB;

/** Campos do container no controle de demurrage/detention (FinDemurrageForm / FinDetentionForm, legado). */
class ContainerTimeForm
{
    /**
     * @param  Closure(): int  $bookingId  reserva a que o container pertence (define os tipos de equipamento)
     * @return array<int, Component>
     */
    public static function components(ContainerTime $type, Closure $bookingId): array
    {
        $demurrage = $type === ContainerTime::Demurrage;

        return [
            TextInput::make('container')
                ->label('Container')
                ->required()
                ->maxLength(100),
            Select::make('ref_tipo_container')
                ->label('Tipo Container')
                ->required()
                ->options(fn () => DB::table('tipo_container')
                    ->whereIn('tipo_container_id', DB::table('mercadoria')->where('embarque_id', $bookingId())->select('tipo_container_id'))
                    ->orderBy('nome')
                    ->pluck('nome', 'tipo_container_id')
                    ->all()),
            TextInput::make('free_time')
                ->label('Free Time')
                ->numeric()
                ->minValue(0)
                ->visible($demurrage),
            DatePicker::make('data_retirada')
                ->label('Data retirada')
                ->visible(! $demurrage),
            DatePicker::make('data_devolucao')
                ->label('Data devolução')
                ->required($demurrage)
                ->maxDate($demurrage ? today() : null),
            FileUpload::make('file')
                ->label('Arquivo')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->rules(['mimes:pdf,jpg,jpeg,png'])
                ->storeFiles(false)
                ->visible($demurrage),
        ];
    }
}
