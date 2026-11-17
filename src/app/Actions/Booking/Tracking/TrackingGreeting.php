<?php

namespace App\Actions\Booking\Tracking;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * DmfUtil::getFrasesRastreamento + Transborda::getSaudacao (legado): a saudação do e-mail de rastreamento, com uma frase
 * sorteada em nome de quem lançou e o contato do gerente do setor (importação: Nathaly, exportação: Douglas, demais: Diego).
 */
class TrackingGreeting
{
    private const PHONE = '(51) 3717-8200';

    private const IMPORT_PERMISSION = 2;

    private const EXPORT_PERMISSION = 3;

    private const IMPORT_MANAGER = 8423;

    private const EXPORT_MANAGER = 134;

    private const DEFAULT_MANAGER = 137;

    public function __invoke(int $userId): string
    {
        $user = DB::table('usuario')->where('usuario_id', $userId)->first(['nome', 'email']);
        $permissions = DB::table('permissao_usuario')->where('usuario_id', $userId)->pluck('permissao_sistema_id')->map(fn ($id) => (int) $id);
        $intro = "Olá.\nMe chamo {$user?->nome} e sou responsável pelo lançamento de informações de rastreamento de seu embarque.\nCaso tenha alguma dúvida com relação a estas informações, você pode me mandar um email. Meu endereço é {$user?->email}.\n";
        $phrases = [
            $intro,
            "Eu sou {$user?->nome} e estou fazendo o rastreamento de seu embarque. Busco estas informações em diversas fontes e efetuo o lançamento das atualizações no sistema da DMF. A gente sabe que muitas vezes a informação não é aquela que você gostaria de receber, mas desde o início da DMF (lá em 2004) assumimos um compromisso com a transparência.\nCaso tenha alguma dúvida, você pode me mandar um email. Meu endereço é {$user?->email}.\n",
        ];
        $followUp = "Eu sou {$user?->nome} e estou fazendo o follow up do seu embarque até que seja confirmado o embarque no navio do porto de origem. Busco estas informações em diversas fontes e efetuo o lançamento das atualizações no sistema da DMF. A gente sabe que muitas vezes a informação não é aquela que você gostaria de receber, mas desde o início da DMF (lá em 2004) assumimos um compromisso com a transparência.\nCaso tenha alguma dúvida, você pode me mandar um email. Meu endereço é {$user?->email}.\n";

        $managerId = match (true) {
            $permissions->contains(self::IMPORT_PERMISSION) => self::IMPORT_MANAGER,
            $permissions->contains(self::EXPORT_PERMISSION) => self::EXPORT_MANAGER,
            default => self::DEFAULT_MANAGER,
        };

        if ($managerId !== self::DEFAULT_MANAGER) {
            $phrases[] = $followUp;
        }

        $manager = DB::table('usuario')->where('usuario_id', $managerId)->first(['nome', 'email']);
        $contact = match (true) {
            $userId === $managerId || ! $manager => 'Mas se preferir, pode ligar para o nosso telefone: '.self::PHONE.".\n",
            $managerId === self::IMPORT_MANAGER => "Se quiser conversar com a nossa gerente de Importação, o nome dela é {$manager->nome}. O email dela é {$manager->email}. Mas se preferir, pode ligar para o nosso telefone: ".self::PHONE.".\n",
            default => "Se quiser conversar com o nosso gerente, o nome dele é {$manager->nome}. O email dele é {$manager->email}. Mas se preferir, pode ligar para o nosso telefone: ".self::PHONE.".\n",
        };

        return '<table cellspacing="1" bgcolor="#FFFFFF"><tr><td colspan="2" align="left"><font face="Verdana" size="2">'.nl2br(e(Arr::random($phrases).$contact)).'</font></td></tr></table>';
    }
}
