<?php

namespace App\Filament\Resources\CnpjReviews\Pages;

use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Filament\Resources\CnpjReviews\CnpjReviewResource;
use App\Models\ConsultaCnpj;
use App\Models\Pessoa;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListCnpjReviews extends ListRecords
{
    protected static string $resource = CnpjReviewResource::class;

    /** Botão Buscar do campo de CNPJ: valida e guarda o CNPJ consultado, que é o que o infolist mostra. */
    private static function searchAction(): Action
    {
        return Action::make('search')
            ->label('Buscar')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->action(function (Get $get, Set $set) {
                $valid = ServiceTaker::validCnpj((string) $get('cnpj'));

                $set('searched', $valid ? $get('cnpj') : null);

                if (! $valid) {
                    Notification::make()->title('CNPJ inválido.')->danger()->send();
                }
            });
    }

    /** Autocomplete de empresa do legado: registra a consulta de qualquer empresa, mesmo fora da lista dos 3 meses. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('lookup')
                ->label('Consultar CNPJ')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->slideOver()
                ->modalWidth(Width::FourExtraLarge)
                ->modalHeading('Consultar CNPJ na OpenCNPJ')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->schema([
                    TextInput::make('cnpj')
                        ->label('CNPJ')
                        ->placeholder('00.000.000/0000-00')
                        ->suffixAction(self::searchAction()),
                    Hidden::make('searched'),
                    Section::make()
                        ->visible(fn (Get $get) => filled($get('searched')))
                        ->schema(fn (Get $get) => CnpjReviewResource::openCnpjSchema((string) $get('searched'))),
                ]),
            Action::make('register')
                ->label('Registrar consulta')
                ->modalHeading('Muda Status')
                ->fillForm(['status' => ConsultaCnpj::OK])
                ->schema([
                    Select::make('pessoa_id')
                        ->label('Empresa')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                            ->where('nome', 'like', "%{$search}%")
                            ->orWhere('cnpj', 'like', "%{$search}%")
                            ->orderBy('nome')
                            ->limit(30)
                            ->pluck('nome', 'pessoa_id'))
                        ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                    ...CnpjReviewResource::reviewFields(),
                ])
                ->action(fn (array $data) => CnpjReviewResource::register((int) $data['pessoa_id'], $data)),
        ];
    }
}
