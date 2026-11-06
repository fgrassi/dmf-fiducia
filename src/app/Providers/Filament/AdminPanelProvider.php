<?php

namespace App\Providers\Filament;

use Filament\Enums\UserMenuPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Red,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                'Cadastros',
                'Booking',
                'Config IT',
                'Configurações',
                'Financeiro',
            ])
            ->navigationItems([
                NavigationItem::make('Cadastros')
                    ->group('Financeiro')
                    ->icon('heroicon-o-folder')
                    ->sort(10),
                NavigationItem::make('Lançamentos')
                    ->group('Financeiro')
                    ->icon('heroicon-o-pencil-square')
                    ->sort(15)
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('financial')),
                NavigationItem::make('Consultas')
                    ->group('Financeiro')
                    ->icon('heroicon-o-magnifying-glass')
                    ->sort(16)
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential(['financial', 'manager'])),
                NavigationItem::make('Despesas')
                    ->group('Financeiro')
                    ->icon('heroicon-o-banknotes')
                    ->sort(51)
                    ->visible(fn () => (bool) (auth()->user()?->canManageCompanyExpenses() || auth()->user()?->hasLegacyCredential('financial'))),
                NavigationItem::make('Relatórios')
                    ->group('Financeiro')
                    ->icon('heroicon-o-document-chart-bar')
                    ->sort(80)
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('financial')),
                NavigationItem::make('Configurações Usuários')
                    ->group('Configurações')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->sort(30)
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential(['usuario', 'auxiliares'])),
                NavigationItem::make('Ordem de Pagamento')
                    ->group('Financeiro')
                    ->icon('heroicon-o-credit-card')
                    ->sort(90)
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('financial')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
