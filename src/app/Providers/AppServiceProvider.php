<?php

namespace App\Providers;

use App\Auth\LegacyUsuarioProvider;
use App\Filament\Resources\AccountingCovers\AccountingCoverResource;
use App\Filament\Resources\AccountingCovers\Pages\ListAccountingCovers;
use App\Filament\Resources\ManualAccountingCovers\ManualAccountingCoverResource;
use App\Filament\Resources\ManualAccountingCovers\Pages\ListManualAccountingCovers;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('legacy-usuario', fn ($app, array $config) => new LegacyUsuarioProvider($app['hash'], $config['model']));

        // abas "Capas normais" / "Capas manuais" no topo das duas listas (são dois resources, em tabelas diferentes)
        foreach (['normal' => ListAccountingCovers::class, 'manual' => ListManualAccountingCovers::class] as $active => $page) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE,
                fn () => view('filament.covers.tabs', ['active' => $active, 'normalUrl' => AccountingCoverResource::getUrl(), 'manualUrl' => ManualAccountingCoverResource::getUrl()]),
                scopes: $page,
            );
        }
    }
}
