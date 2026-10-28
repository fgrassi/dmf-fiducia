<?php

namespace App\Actions\Companies;

use App\Models\Pessoa;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * pessoa/addContato (legado): grava o contato (usuario) e o vínculo com a empresa, os tipos (exportação/importação) e as
 * categorias de frases do tracking. Com justificativa (e-mail fora do domínio da empresa) o contato fica aguardando
 * aprovação e o aprovador recebe uma mensagem. Editar um contato existente nunca muda o status por si só (um pendente
 * ou reprovado não vira OK fora do fluxo de aprovação).
 *
 * ponytail: sem o "Criar acesso à Customer" (API de provisionamento da Intranet) nem o e-mail da aprovação; aprovar segue no legado.
 */
class SaveCompanyContact
{
    /** Destinatário da mensagem de contato a aprovar (hardcoded no legado). */
    private const APPROVER_ID = 2325;

    /** @param  array<string, mixed>  $data  colunas de usuario + tipos (tipo_usuario_id 3/7), categorias e justificativa */
    public function __invoke(Pessoa $company, array $data, int $userId, ?User $contact = null): User
    {
        $types = $data['tipos'] ?? [];
        $categories = $data['categorias'] ?? [];
        $justification = $data['justificativa'] ?? null;
        unset($data['tipos'], $data['categorias'], $data['justificativa']);

        return DB::transaction(function () use ($company, $data, $userId, $contact, $types, $categories, $justification) {
            $contact ??= new User;
            $status = filled($justification) ? 0 : ($contact->exists ? $contact->status : 1);
            $contact->forceFill($data + ['status' => $status])->save();

            $linkId = DB::table('pessoa_usuario')->where('pessoa_id', $company->getKey())->where('usuario_id', $contact->getKey())->value('pessoa_usuario_id')
                ?? DB::table('pessoa_usuario')->insertGetId(['pessoa_id' => $company->getKey(), 'usuario_id' => $contact->getKey()]);

            DB::table('pessoa_usuario_tipo_usuario')->where('pessoa_usuario_id', $linkId)->delete();
            DB::table('pessoa_usuario_tipo_usuario')->insert(array_map(fn ($type) => ['pessoa_usuario_id' => $linkId, 'tipo_usuario_id' => $type], $types));

            DB::table('usuario_frase_categoria')->where('ref_usuario', $contact->getKey())->delete();
            DB::table('usuario_frase_categoria')->insert(array_map(fn ($category) => ['ref_usuario' => $contact->getKey(), 'ref_categoria_frase' => $category], $categories));

            if (filled($justification)) {
                $this->requestApproval($linkId, $justification, $userId);
            }

            return $contact;
        });
    }

    private function requestApproval(int $linkId, string $justification, int $userId): void
    {
        DB::table('justificativa_contato_pessoa')->insert([
            'justificativa' => $justification,
            'created_by' => $userId,
            'created_at' => now(),
            'tipo_usuario' => auth()->user()?->hasLegacyCredential('import') ? 'i' : 'e',
            'pessoa_usuario_id' => $linkId,
        ]);

        $messageId = DB::table('cmn_messages')->insertGetId([
            'author' => $userId,
            'subject' => 'Um novo contato foi cadastro e precisa ser aprovado.',
            'created_at' => now(),
            'ref_family' => 1,
        ]);

        DB::table('cmn_messages_recipients')->insert(['ref_message' => $messageId, 'recipient' => self::APPROVER_ID, 'status' => 1]);
    }
}
