{{-- Seções do e-mail da Instrução de Cobrança (Cobranca::montarSecoesEmailInstrucaoCobranca): instrução, aviso de boleto e Late Payment Fee. --}}
@php($s = $sections)
    <tr><td>
        @if ($s['mode'] === 'exception')
            A reserva {{ $s['booking'] }} é de um cliente que possui taxa negociada e precisa ser recalculada manualmente.
        @elseif ($s['mode'] === 'import')
            <font face="Arial">
                Gentileza notar em anexo o arquivo com os valores a serem pagos referentes ao BL <strong>{{ $s['bl'] }}</strong>, reserva <strong>{{ $s['booking'] }}</strong>
                <ul>
                    <li style="color:red">INFORMAMOS QUE PARA A SOLICITAÇÃO DE DESBLOQUEIO DO SISTEMA MERCANTE (RETENÇÃO DE FRETE) SE FAZ NECESSARIA A ENTREGA DE UMA VIA DO BL ORIGINAL JUNTO AO REPRESENTANTE DA DMF NO PORTO DE DESCARGA.</li>
                    <li style="color:red">CASO OS DOCUMENTOS, PARA A LIBERAÇÃO, NÃO SEJAM ENTREGUES NA DATA DO PAGAMENTO, PODERÁ OCASIONAR COBRANÇA DE DIFERENÇA CAMBIAL.</li>
                    <li style="color:red">A TAXA DE CÂMBIO PODERÁ SOFRER ALTERAÇÕES DURANTE O DIA</li>
                    <li>Caso o pagamento não seja efetuado no dia, gentileza confirmar a taxa na data do pagamento.</li><br/>
                    @if ($s['special_hours'])
                        <li>Pedimos que seus pagamentos sejam efetuados até as 17:00 hrs e os comprovantes sejam enviados até as 17:15 hrs do mesmo dia da solicitação dos valores. Desta forma as pendências serão retiradas até as 11:00 hrs do dia útil seguinte.</li><br/>
                    @else
                        <li>Gentileza enviar comprovante de depósito, até às 12:00 hrs, para que possamos identificar o pagamento e iniciar o processo de liberação da carga. Comprovantes enviados após este horário estarão sujeitos a variação cambial. Importante salientar que a liberação poderá levar de 24 a 48hrs úteis, de acordo com cada armador.</li><br/>
                    @endif
                    <li>Lembramos que para iniciarmos o processo de liberação do embarque é necessário a entrega do termo único de demurrage, que será enviado em seguida, devidamente rubricado em todas as páginas, assinado, e entregue ao representante da DMF no porto de destino juntamente com procuração. Cobrança de demurrage cessará no momento da devolução da unidade vazia no terminal especificado pelo armador conforme política de demurrage no termo enviado. </li><br/>
                    <li>Caso o termo de demurrage não seja entregue na data de pagamento, poderá ocasionar cobrança de diferença cambial</li><br/>
                    <li>Pagamentos efetuados com cheque ou DOC terão que aguardar a compensação para a liberação dos documentos e estarão sujeitos a cobrança de variação cambial. A liberação estará sujeita ao pagamento da diferença cambial.</li><br/>
                </ul>
            </font>
        @else
            <font face="Arial">
                Gentileza notar em anexo o arquivo com os valores a serem pagos referentes a reserva <strong>{{ $s['booking'] }}</strong>
                <ul>
                    <li>Obtemos a taxa do dia geralmente após às 10:30 hrs, gentileza notar que as cobranças serão encaminhadas somente após a confirmação da mesma. Caso o pagamento não seja efetuado no dia, gentileza confirmar a taxa na data do pagamento.</li><br/>
                    <li>Gentileza enviar comprovante de depósito, até às 13:30 hrs, para que possamos identificar o pagamento bem como fazer o envio dos documentos.</li><br/>
                    <li>Pagamentos efetuados com cheque terão que aguardar a compensação para a liberação dos documentos.</li><br/>
                    <li>Comprovantes de depósito enviados após às 13:30 hrs, terão a liberação dos documentos no dia útil seguinte e estarão sujeitos a variação cambial.</li><br/>
                    <li>Favor sempre confirmar endereço para entrega de BLs Originais.</li><br/>
                    <li style="color:red">A TAXA DE CÂMBIO PODERÁ SOFRER ALTERAÇÕES DURANTE O DIA</li><br/>
                </ul>
            </font>
        @endif
    </td></tr>
    @if ($s['boleto'])
        <tr><td><hr/></td></tr>
        <tr><td><font face="Arial" color="black"><h3>Informações Adicionais da Confirmação de Valores</h3></font></td></tr>
        <tr><td>
            <font face="Arial">
                @if ($s['import'])
                    Gentileza notar que nosso sistema acaba de lhe encaminhar automaticamente numerário referente ao BL <strong>{{ $s['bl'] }}</strong>, reserva <strong>{{ $s['booking'] }}</strong>.<br/>
                @else
                    Gentileza notar que nosso sistema acaba de lhe encaminhar automaticamente numerário referente a reserva <strong>{{ $s['booking'] }}</strong>.<br/>
                @endif
                <br/> Solicitamos que, por gentileza, nos envie confirmação dos valores para que possamos providenciar boleto para pagamento com nosso prazo especial acordado.<br/>
                <br/> Após a confirmação, encaminharemos boleto no próximo dia útil.<br/>
                <br/> Ficamos à disposição para maiores esclarecimentos.<br/>
            </font>
        </td></tr>
    @endif
    @if ($s['late_payment'])
        <tr><td><hr/></td></tr>
        <tr><td><font face="Arial" color="black"><h3>Informativo “Late Payment Fee”</h3></font></td></tr>
        <tr><td>
            <p><u>NOTAR ABAIXO OS VALORES REFERENTE A TAXA A SER APLICADA PARA ATRASO NOS PAGAMENTOS DE IMPORTAÇÃO / EXPORTAÇÃO:</u></p>
            <p>{{ $s['late_payment']['carrier'] }}</p>
            {!! $s['late_payment']['html'] !!}
            <p>Estamos ao seu dispor caso necessite maiores detalhes e agradecemos pelo entendimento e suporte.</p>
        </td></tr>
    @endif
