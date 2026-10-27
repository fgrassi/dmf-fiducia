{{-- NFS-e autorizada(s): mesmo HTML do Cobranca::enviarEmailNfseAutorizadasLote legado (com ou sem a Instrução de Cobrança). --}}
@php($s = $sections)
@php($notesHtml = collect($notes)->map(fn ($n) => '<br/>Referência DMF: <strong>'.e($n['reference']).'</strong><br/>'
    .(filled($n['key']) ? 'Chave de acesso: <strong>'.e($n['key']).'</strong><br/>' : '')
    .'Link de validação: <a href="'.e($n['url']).'">'.e($n['url']).'</a><br/>'
    .($n['value'] !== null && $n['value'] !== '' ? 'Valor: <strong>R$ '.number_format((float) $n['value'], 2, ',', '.').'</strong><br/>' : ''))->implode(''))
@if ($withInstruction)
<table width="960px" border="0">
    <tr bgcolor="#7e0001"><td>&nbsp;</td></tr>
    @if ($s['mode'] !== 'exception')
        <tr><td><font face="Arial"><strong>Prezado Cliente,</strong></font></td></tr>
    @endif
    <tr><td><font face="Arial" color="black"><h3>Instrução de Cobrança e NFS-e Autorizada{{ $plural ? 's' : '' }}</h3></font></td></tr>
    @include('mail.cobranca.partials.instruction-sections')
    <tr><td><hr/></td></tr>
    <tr><td><font face="Arial" color="black"><h3>NFS-e Autorizada{{ $plural ? 's' : '' }}</h3></font></td></tr>
    <tr><td><font face="Arial">
        @if ($plural)
            Em anexo também encontram-se as NFS-e autorizadas (XML e PDF DANFSe) referentes ao processo abaixo.<br/>
        @else
            Em anexo também encontra-se a NFS-e autorizada (XML e PDF DANFSe) referente ao processo abaixo.<br/>
        @endif
        @if ($hasIc)
            A Instrução de Cobrança (IC) segue em anexo juntamente com a(s) nota(s).<br/>
        @endif
        {!! $notesHtml !!}
        <br/><br/>Atenciosamente,<br/>Departamento Financeiro<br/><br/>
    </font></td></tr>
    <tr bgcolor="#7e0001"><td><font face="Arial" color="white"><center>DMF Logistics do Brasil - www.dmflog.com.br - +55 51 3717-8200 </center></font></td></tr>
</table>
@else
<table width="960px" border="0">
    <tr bgcolor="#7e0001"><td></td></tr>
    <tr><td><font face="Arial" color="black"><h3>NFS-e Autorizada{{ $plural ? 's' : '' }}</h3></font></td></tr>
    <tr><td>
        <font face="Arial"><strong>Cliente</strong><br/>
            @if ($plural)
                <br/>Seguem as NFS-e autorizadas referentes ao pagamento do processo abaixo.<br/>
            @else
                <br/>Segue a NFS-e autorizada referente ao pagamento do processo abaixo.<br/>
            @endif
            {!! $notesHtml !!}
            @if ($plural)
                <br/>Os XMLs e os PDFs DANFSe das notas seguem em anexo.<br/>
            @else
                <br/>O XML e o PDF DANFSe da nota seguem em anexo.<br/>
            @endif
            <br/><br/>Atenciosamente,<br/> Equipe da DMF Logistics <br/><br/>
        </font>
    </td></tr>
</table>
@endif
