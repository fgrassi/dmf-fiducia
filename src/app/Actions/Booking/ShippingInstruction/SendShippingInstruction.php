<?php

namespace App\Actions\Booking\ShippingInstruction;

use App\Actions\Booking\BookingLog;
use App\Actions\Notices\SendNoticeMail;
use App\Models\Embarque;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * enviaInstrucaoEmbarque (legado): manda a instrução de embarque (PDF da reserva e os arquivos marcados como instrução) aos contatos
 * autorizados do agente no porto de origem, com cópia à importação (e, no marítimo, à documentação), e registra o envio no
 * rastreamento (evento "t"). Reserva de cliente com seguro obrigatório e sem seguro gera o aviso de avaria grossa à importação.
 * Diferença do legado: o rastreamento só é gravado depois que o e-mail sai.
 */
class SendShippingInstruction
{
    public const EVENT = 't';

    private const IMPORT = 'importacao@dmflog.com.br';

    private const OCEAN_COPIES = ['diego@dmflog.com.br', 'docimp@dmflog.com.br', 'docimp2@dmflog.com.br'];

    public function __construct(
        private ShippingInstructionPdf $pdf,
        private ShippingInstructionMail $mail,
        private SendNoticeMail $send,
        private BookingLog $log,
    ) {}

    /** Contatos autorizados do agente no porto de origem, para a operação e o modal da reserva. @return list<object{name: string, email: string}> */
    public static function contacts(Embarque $booking): array
    {
        return DB::table('agente_contato')
            ->where('ref_pessoa', $booking->pessoa_id_representante)
            ->where('porto_id', $booking->porto_origem_id)
            ->where('autorizado', 1)
            ->whereIn('operacao', [$booking->impor_expor, 'a'])
            ->whereIn('modal', [$booking->tipo_embarque, 'b'])
            ->orderBy('id')
            ->get(['name', 'email'])
            ->all();
    }

    /** @throws DomainException sem contato autorizado @throws RuntimeException o e-mail não saiu */
    public function __invoke(Embarque $booking, User $user, string $notes = ''): void
    {
        $contacts = self::contacts($booking);
        throw_if($contacts === [], DomainException::class, 'Nenhum contato autorizado do agente encontrado para este porto/modal/operação.');

        $message = ($this->mail)($booking, collect($contacts)->pluck('name')->implode(', '), $notes, $user->nome, (string) $user->email);
        $copies = array_merge($booking->tipo_embarque === 'm' ? self::OCEAN_COPIES : [], [self::IMPORT]);
        $pdfPath = tempnam(sys_get_temp_dir(), 'si_');

        try {
            $this->pdf->render($booking)->Output($pdfPath, 'F');

            $attachments = [$pdfPath => "si_{$booking->booking}.pdf"];
            foreach ($this->instructionFiles($booking) as $name) {
                $attachments[Storage::disk('tracking_uploads')->path("docs_emb/{$name}")] = $name;
            }

            $sent = ($this->send)(
                $message['subject'],
                $message['html'],
                collect($contacts)->pluck('email')->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all(),
                cc: $copies,
                from: 'no-reply@dmflog.com.br',
                fromName: $user->nome,
                replyTo: filter_var($user->email, FILTER_VALIDATE_EMAIL) ? $user->email : null,
                attachments: $attachments,
            );
        } finally {
            @unlink($pdfPath);
        }

        throw_unless($sent, RuntimeException::class, 'Falha ao enviar o e-mail.');

        $this->log->track(
            $booking->getKey(),
            'INSTRUÇÃO ENVIADA AO AGENTE',
            'INSTRUÇÃO ENVIADA AO AGENTE NO DIA '.now()->format('d/m/Y').' ÀS '.now()->format('H:i:s'),
            $user->getKey(),
            self::EVENT,
        );

        $this->uninsuredWarning($booking);
    }

    /** Nomes dos arquivos da instrução (ctr_instr = 1) que existem em disco. @return list<string> */
    public static function instructionFiles(Embarque $booking): array
    {
        return DB::table('emb_embarque_files as x')->join('cmn_files as f', 'f.id', '=', 'x.ref_file')
            ->where('x.ref_embarque', $booking->getKey())->where('x.ctr_instr', 1)->orderBy('x.id')->pluck('f.name')
            ->filter(fn ($name) => filled($name) && Storage::disk('tracking_uploads')->exists("docs_emb/{$name}"))->values()->all();
    }

    /**
     * Aviso de avaria grossa: reserva com controle de seguro e cliente com seguro obrigatório. No legado a lista de destinatários
     * do cliente nunca é preenchida, então o e-mail vai só em cópia para a importação; mantido assim.
     */
    private function uninsuredWarning(Embarque $booking): void
    {
        $required = $booking->ctr_seguro
            && DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('seguro_obrigatorio') === 's';

        if (! $required) {
            return;
        }

        $image = rtrim((string) config('services.tracking.url'), '/').'/images/confirmacao/header.png';

        ($this->send)(
            "[Aviso] Reserva Sem Seguro: {$booking->booking}",
            '<table><tr><td><img src="'.$image.'"/></td></tr><tr><td>'
            .'<p>Prezado cliente</p>'
            .'<p>Favor notar que, conforme sua instrução, a reserva '.e($booking->booking).', invoice '.e($booking->invoice).' não está segurada</p>'
            .'<p>DA AVARIA GROSSA: Tem-se por Avaria Grossa todos os danos ou despesas extraordinárias decorrentes de um ato intencional (decorrente de uma decisão do capitão do navio), efetuado para a segurança do navio e suas cargas, em uma situação de perigo real e iminente, com o intuito de evitar um mal maior a expedição marítima. Sendo declarada a Avaria Grossa pelas autoridades competentes, todas as despesas geradas com o salvamento do navio e cargas serão rateadas proporcionalmente entre os proprietários das cargas embarcadas e do navio, conforme regulamentado pelo Código Comercial Brasileiro e pelas Regras de York-Antuérpia. Ficam cientes os embarcadores que não contratarem seguro, caso reconhecida a Avaria Grossa, terão que arcar com os valores definidos no rateio das despesas, além da possibilidade também da perda ou retenção de suas mercadorias. A recomendação é sempre embarcar com seguro de transporte internacional, afinal o seguro é um grande investimento para proteção dos interesses dos embarcadores a um custo insignificante diante dos valores das cargas.</p>'
            .'</td></tr><tr style="height:15px"></tr><td><hr/></td></table>',
            [],
            cc: [self::IMPORT],
        );
    }
}
