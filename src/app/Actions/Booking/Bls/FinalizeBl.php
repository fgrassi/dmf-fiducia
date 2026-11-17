<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use App\Models\CmnFile;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * geraPdfBl e finalizarBl (legado): o PDF do BL fica guardado como documento da reserva (tipo 1); finalizar aprova o BL
 * e manda a cópia aos contatos que recebem documentos. Na importação LCL, avisa a Importação se o fator do BL
 * não bate com o da reserva.
 */
class FinalizeBl
{
    private const FILE_TYPE = 1;

    private const FROM = 'documentacao@dmflog.com.br';

    public function __construct(private BlPdf $pdf) {}

    public function __invoke(Embarque $booking, Bl $bl): void
    {
        $bl->forceFill(['status' => Bl::APPROVED])->save();

        $this->sendCopy($booking, $bl, $this->store($booking, $bl));
        $this->alertLclFactor($booking, $bl);
    }

    /** geraPdfBl: grava o PDF em docs_emb e o registra na reserva. Devolve o nome do arquivo. */
    public function store(Embarque $booking, Bl $bl): string
    {
        $name = 'dmf_hbl'.$bl->bl.'_'.date('dmyGis').'.pdf';

        DB::transaction(function () use ($booking, $name) {
            $file = CmnFile::forceCreate(['name' => $name, 'created_at' => now()->toDateString(), 'uid' => sha1((string) time()), 'subject' => 'Documento Anexo a Reserva']);

            EmbEmbarqueFile::forceCreate(['ref_embarque' => $booking->getKey(), 'ref_file' => $file->getKey(), 'ref_file_type' => self::FILE_TYPE]);
        });

        Storage::disk('tracking_uploads')->put("docs_emb/{$name}", $this->pdf->render($bl)->Output('', 'S'));

        return $name;
    }

    private function sendCopy(Embarque $booking, Bl $bl, string $name): void
    {
        $port = function (?int $id): string {
            $port = DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $id)->first(['p.nome', 'c.nome as country']);

            return $port ? "{$port->nome} ({$port->country})" : '';
        };
        $date = fn (?string $value): string => date('d/m/Y', strtotime((string) $value));
        $shipper = (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome');

        $message = '<font face="Arial">Em anexo segue cópia do BL referente embarque da reserva '.e($booking->booking)
            .' <br/>Porto de Embarque: '.e($port($booking->porto_origem_id))
            .' <br/>Data do Embarque: '.$date($booking->saida_efetiva)
            .' <br/>Destino: '.e($port($booking->porto_destino_id))
            .' <br/>Previsão de Chegada no destino: '.$date($booking->chegada_prevista)
            .'<br/><br/>Atenciosamente, <br/><br/>Equipe DMF</font>';

        $html = "<table width='960px' border=0><tr bgcolor='#7e0001'><td> </td></tr>"
            ."<tr><td><font face='Arial' color='black'><h3>CÓPIA DE BL </h3></font></td></tr>"
            ."<tr><td>{$message}</td></tr>"
            ."<tr bgcolor='#7e0001'><td><font face='Arial' color='white'><center>DMF Logistics do Brasil - www.dmflog.com.br - +55 51 3717-8200 </center></font></td></tr></table>";

        $content = Storage::disk('tracking_uploads')->get("docs_emb/{$name}");

        Mail::html($html, fn ($mail) => $mail
            ->from(self::FROM)
            ->to($this->recipients($booking))
            ->subject("DMF Logistics: CÓPIA DE BL - Shipper: {$shipper} / {$booking->invoice} / {$booking->booking}")
            ->attachData($content, $name, ['mime' => 'application/pdf']));
    }

    /** Usuários da reserva que recebem documentos (cliente e importador), despachante e contatos da reserva, mais a Documentação. */
    private function recipients(Embarque $booking): array
    {
        $users = fn (string $table, bool $onlyDocuments) => DB::table("{$table} as c")
            ->join('usuario as u', 'u.usuario_id', '=', 'c.usuario_id')
            ->where('c.embarque_id', $booking->getKey())
            ->when($onlyDocuments, fn ($query) => $query->where('u.receber_documentos', 's'))
            ->pluck('u.email');

        $broker = $booking->despachante_id
            ? DB::table('despachante_contato')->where('ref_despachante', $booking->despachante_id)->where('tipo', '<>', 'f')->where('status', 1)->pluck('email')
            : collect();

        return $users('embarque_contato_cliente', true)
            ->merge($users('embarque_contato_importador', true))
            ->merge($broker)
            ->merge($users('embarque_contato', false))
            ->filter(fn ($email) => strlen((string) $email) > 5)
            ->push(self::FROM)
            ->unique()
            ->values()
            ->all();
    }

    /** Importação LCL: o fator do BL (peso/volume) tem que ser o da reserva, senão a Importação atualiza as despesas. */
    private function alertLclFactor(Embarque $booking, Bl $bl): void
    {
        if ($booking->impor_expor !== 'i' || ! DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', 20)->exists()) {
            return;
        }

        if ($bl->lclFactor() == $booking->importFactor()) {
            return;
        }

        Mail::html('Favor atualizar os valores conforme os dados atualizados da carga', fn ($mail) => $mail->to('importacao@dmflog.com.br')->subject('Verificar despesas - '.$booking->booking));
    }
}
