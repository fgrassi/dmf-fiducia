<?php

namespace App\Filament\Resources\Bls;

use App\Filament\Resources\Bls\Pages\CreateBl;
use App\Filament\Resources\Bls\Pages\EditBl;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Models\Bl;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;

/**
 * Tela do BL dentro da reserva (editAprovacaoBl, legado): páginas próprias de criar e editar, abertas a partir da aba
 * Aprovação BL. A lista é a própria aba; por isso não há item de menu nem página de listagem.
 */
class BlResource extends Resource
{
    protected static ?string $model = Bl::class;

    protected static ?string $modelLabel = 'BL';

    protected static ?string $recordTitleAttribute = 'bl';

    protected static bool $shouldRegisterNavigation = false;

    /** security.yml do módulo booking (legado): credencial booking. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public static function getParentResourceRegistration(): ParentResourceRegistration
    {
        return EmbarqueResource::asParent(static::class)
            ->relationship('bls')
            ->inverseRelationship('embarque')
            ->page('view');
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateBl::route('/create'),
            'edit' => EditBl::route('/{record}/edit'),
        ];
    }
}
