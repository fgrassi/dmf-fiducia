<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'login' => $data['login'],
            'password' => $data['password'],
        ];
    }

    /** Sem "lembrar de mim": a tabela legada `usuario` não guarda token. */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Login')
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('Login incorreto.'),
        ]);
    }

    /** Logo grande só no login; sidebar usa brandLogo. */
    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        $logo = e(asset('images/dmf-logo.png'));
        $alt = e(filament()->getBrandName());

        return new HtmlString(
            <<<HTML
            <img src="{$logo}" alt="{$alt}" style="display: inline-block; height: 8rem; width: auto; max-width: 100%;" />
            HTML
        );
    }
}
