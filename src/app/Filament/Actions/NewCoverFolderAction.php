<?php

namespace App\Filament\Actions;

use App\Actions\AccountingCovers\CreateCoverFolder;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** "Criar nova Pasta" (novaPasta, legado): usada na lista de capas e na lista de pastas. */
class NewCoverFolderAction
{
    public static function make(): Action
    {
        return Action::make('newFolder')
            ->label('Nova caixa')
            ->icon(Heroicon::OutlinedFolderPlus)
            ->requiresConfirmation()
            ->modalHeading('Tem certeza que deseja criar uma Nova Caixa?')
            ->action(function (CreateCoverFolder $create, Action $action) {
                try {
                    $folder = $create();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title("Caixa {$folder->nome} criada.")->success()->send();
            });
    }
}
