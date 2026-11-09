<?php

namespace App\Actions\AgentNotes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * resumoNotasAgentes/enviaEmail (legado): grava os e-mails financeiros do agente e manda o extrato em Excel (AgentPendingStatement)
 * para o financeiro da DMF e para esses e-mails. O texto com os dados bancários da DMF é o do legado.
 */
class SendAgentPendingStatement
{
    private const FINANCE = 'financeiro@dmflog.com.br';

    private const MESSAGE = <<<'HTML'
        <p>Dears,<br><br>Please find attached our updated statement of account.<br>Please check and advise payment schedule.</p>
        <span style="background-color: yellow">
            Our banking information has changed.<br>
            Please find below our updated bank details:<br>
            Beneficiary’s Name: DMF LOGISTICS DO BRASIL LTDA<br>
            Beneficiary’s Address: RUA BORGES DE MEDEIROS 274 2º ANDAR<br>
            Beneficiary’s Intermediary Bank: BANK OF AMERICA, N.A. - NEW YORK<br>
            SWIFT: BOFAUS3N<br>
            ACCOUNT NR: 6550921296<br>
            Bank: BANCO BRADESCO SA - Porto Alegre<br>
            Beneficiary’s Bank Address: Pça. Osvaldo Cruz 10 4º andar - Centro - Porto Alegre - RS - Brasil<br>
            SWIFT: BBDEBRSPSPO<br>
            AGENCY: 1587<br>
            ACCOUNT: 200526-3<br>
            IBAN NUMBER: BR83 6074 6948 0732 7000 0005 266c 1<br>
            We kindly ask that all new transfers follow our new details.<br>
        </span>
        <p>Thank you &amp; kind regards.</p>
        HTML;

    public function __construct(private AgentPendingStatement $statement) {}

    /** Separa os e-mails por vírgula, sem espaços nem repetidos. @return list<string> */
    public static function parseContacts(?string $contacts): array
    {
        return collect(explode(',', (string) $contacts))->map(fn ($contact) => trim($contact))->filter()->unique()->values()->all();
    }

    public function __invoke(int $agentId, string $type, ?string $contacts): void
    {
        $emails = self::parseContacts($contacts);
        $name = (string) DB::table('pessoa')->where('pessoa_id', $agentId)->value('nome');
        $file = $this->statement->fileName();
        $content = ($this->statement)($agentId, $type);

        DB::table('pessoa')->where('pessoa_id', $agentId)->update(['contato_agente_financeiro' => $contacts]);

        Mail::html(self::MESSAGE, function ($mail) use ($emails, $name, $file, $content) {
            $mail->from(self::FINANCE)
                ->to(array_values(array_unique([self::FINANCE, ...$emails])))
                ->subject("[DMF Logistics] Statement of Account: {$name} - ".now()->format('d/m/Y'))
                ->attachData($content, $file, ['mime' => 'application/vnd.ms-excel']);
        });
    }
}
