<?php

namespace App\Actions\Notices;

use App\Actions\Booking\Ams\SendAms;
use App\Enums\Notice;

/**
 * Texto padrão (assunto e corpo com {{variáveis}}) de cada aviso e as variáveis que a rotina preenche. Sem texto salvo na tela
 * Avisos por e-mail vale este; "html" marca as variáveis que levam HTML pronto (tabelas) e por isso não são escapadas.
 */
class NoticeTexts
{
    /** @return array{subject: string, body: string, tags: array<string, array{label: string, sample: string, html?: bool}>} */
    public static function for(Notice $notice): array
    {
        $list = self::tag('Lista (tabela) calculada pela rotina', '<table border="1"><tr><th>Reserva</th><th>Cliente</th></tr><tr><td>BKG123</td><td>Cliente Exemplo</td></tr></table>', true);
        $date = self::tag('Data de hoje', '18/11/2026');

        [$subject, $body, $tags] = match ($notice) {
            Notice::OpenCharges => [
                '[Aviso] Reserva {{reserva}} está com cobrança(s) {{prazo}} em aberto',
                '<h3>Cobrança(s) {{prazo}} em aberto:</h3><p>Prezados, bom dia!</p><p>Até a presente data não constam em nossos registros o recebimento das taxas compreendidas no(s) BL(s) mencionado(s) abaixo:</p>{{lista}}'
                    .'<p>Caso já tenha sido efetuado o pagamento do débito apontado, favor encaminhar o comprovante de pagamento para o seguinte endereço: financeiro@dmflog.com.br para que possamos regularizar a situação.</p>'
                    .'<p>Caso o pagamento não tenha sido efetuado, gentileza confirmar a taxa na data do pagamento.</p>{{taxa_atraso}}',
                ['reserva' => self::tag('Reserva', 'BKG123'), 'prazo' => self::tag('Prazo (há 7 dias / há mais de 7 dias)', 'há 7 dias'), 'lista' => self::tag('Tabela da reserva com as cobranças', '<table border="1"><tr><th>Reserva</th><th>Cliente</th></tr><tr><td>BKG123</td><td>Cliente Exemplo</td></tr></table>', true), 'taxa_atraso' => self::tag('Taxa de atraso do armador (só aparece se cadastrada)', '', true)],
            ],
            Notice::OpenImportCharges => [
                'Cobranças há 7 dias sem pagamento',
                '<h3>Aviso: Cobranças em Aberto</h3><p>As cobranças abaixo foram geradas ha 7 dias e ainda não foram pagas</p>{{lista}}',
                ['lista' => $list],
            ],
            Notice::HapagPending => [
                '[FINANCEIRO] Reservas da Hapag pendentes de cobrança - EXPO',
                '<p>As seguintes reservas estão pendentes para serem geradas as cobranças</p>{{lista}}',
                ['lista' => $list],
            ],
            Notice::OpenChargesReport => [
                '[RELATÓRIO] Cobranças em aberto 7 dias+',
                '<p>Segue abaixo a relação de Cobranças em aberto a 7 dias+</p>{{lista}}',
                ['lista' => $list],
            ],
            Notice::ReleaseList => [
                'Relação de embarques para liberação {{data}}',
                '<h4>Segue abaixo a relação de reservas autorizadas para liberação de BL.</h4>{{lista}}',
                ['data' => self::tag('Data de hoje (dd-mm-aaaa)', '18-11-2026'), 'lista' => $list],
            ],
            Notice::PendingTakers => [
                'DMF Logistics: tomadores com pendência de cadastro - {{data}}',
                '<p>Há <strong>{{total}}</strong> {{tomadores}} com pendência de cadastro entre {{de}} e {{ate}}.</p><p><a href="{{link}}">Abrir a lista de tomadores</a></p>{{lista}}',
                ['data' => $date, 'total' => self::tag('Quantidade de tomadores', '3'), 'tomadores' => self::tag('"tomador" ou "tomadores"', 'tomadores'), 'de' => self::tag('Início do período', '18/11/2026'), 'ate' => self::tag('Fim do período', '23/11/2026'), 'link' => self::tag('Endereço da tela de tomadores', 'https://exemplo.local/admin/taker-check'), 'lista' => $list],
            ],
            Notice::ExpenseDue => [
                '[Aviso] Vencimento de despesas em 3 dias ({{vencimento}})',
                '<h3>Lembrete: os vencimentos abaixo ocorrem em 3 dias ({{vencimento}})</h3>{{lista}}<br/><h3>Despesas Cadastradas/Pendentes</h3><hr/>{{detalhes}}',
                ['vencimento' => self::tag('Data do vencimento', '21/11/2026'), 'lista' => $list, 'detalhes' => self::tag('Lançamentos abertos de cada vencimento', '<h4>Fornecedor / Categoria</h4><p><em>Nenhum lançamento aberto.</em></p>', true)],
            ],
            Notice::ExpenseReviewImport, Notice::ExpenseReviewExport => [
                '[DMF] Revisão de despesas - {{data}} - '.($notice === Notice::ExpenseReviewImport ? 'IMPO' : 'EXPO'),
                '{{venda_menor}}{{sem_cotacao}}',
                ['data' => $date, 'venda_menor' => self::tag('Despesas com venda (S/R) abaixo da compra (B/R), com o texto de apresentação', '', true), 'sem_cotacao' => self::tag('Despesas de moedas diferentes sem cotação, com o texto de apresentação', '', true)],
            ],
            Notice::MblRelease => [
                '[IMPORTANT] MBL Release Information: {{reserva}} - MBL: {{mbl}} - Cnee: {{importador}}',
                '<p>Dear all</p><p>We would like to inform that DMF didn’t receive local charges payment for booking {{reserva}} . Please note that MBL release will be made by SS Line once payment is confirmed.</p>'
                    .'<ul><li>MBL: {{mbl}}</li><li>HBL: {{hbl}}</li><li>ETD: {{etd}}</li><li>POD: {{pod}}</li></ul>',
                ['reserva' => self::tag('Reserva', 'BKG123'), 'mbl' => self::tag('MBL', 'MBL123'), 'hbl' => self::tag('HBL', 'HBL123'), 'etd' => self::tag('Data prevista', '2026-11-20'), 'pod' => self::tag('Porto de destino (país)', 'Santos (Brasil)'), 'importador' => self::tag('Importador (Cnee)', 'Importador Exemplo')],
            ],
            Notice::ExchangeDeadline => [
                '[Aviso] Data de Limite para Envio de Câmbios',
                '<h3>Gentileza observar o prazo para envio de Câmbio dos Agentes abaixo nos próximos 5 dias</h3>{{lista}}',
                ['lista' => $list],
            ],
            Notice::PriorityArrivals => [
                '[Aviso] Embarques Prioritários ETA próximo (+{{dias}} dias)',
                '<h3>Os seguintes embarques prioritários chegam em {{dias}} dias: </h3>{{lista}}',
                ['dias' => self::tag('Dias até a chegada (5 ou 2)', '5'), 'lista' => $list],
            ],
            Notice::Provisioning => [
                'Provisionamento {{titulo}} - Semana {{semana}} de {{ano}}',
                '<h1>Provisionamento</h1><h2>Semana: {{periodo}}</h2>{{lista}}',
                ['titulo' => self::tag('EXPORTAÇÕES ou IMPORTAÇÕES', 'EXPORTAÇÕES'), 'semana' => self::tag('Número da semana', '47'), 'ano' => self::tag('Ano', '2026'), 'periodo' => self::tag('Período (domingo a sábado)', '29/11/2026 a 05/12/2026'), 'lista' => $list],
            ],
            Notice::OpenCommissions => [
                'Comissões em aberto - {{data}}',
                '<p>Comissões em aberto:</p>{{lista}}',
                ['data' => $date, 'lista' => $list],
            ],
            Notice::OverdueNotes => [
                '[FINANCEIRO] Relação de Notas de Debito/Crédito emitidas +30 dias',
                '{{lista}}',
                ['lista' => self::tag('Tabela de notas por agente (ou "Nenhuma nota encontrada")', '<table border=1><tr><th>Agente</th><th>Nota</th></tr></table>', true)],
            ],
            Notice::NotesMonthlyReport => [
                'Relatório Controle de Notas -  {{mes}}/{{ano}}',
                '{{lista}}',
                ['mes' => self::tag('Mês anterior, em maiúsculas', 'OUTUBRO'), 'ano' => self::tag('Ano', '2026'), 'lista' => self::tag('Texto de apresentação e tabela de reservas (ou "Nenhuma alteração realizada.")', '<p>Relatório de reservas que NÃO necessitam de notas e tiveram seu status alterado no período de 01/10/2026 a 31/10/2026.</p><table border=1><tr><th>Reserva</th><th>Data da Alteração</th></tr></table>', true)],
            ],
            Notice::ReadyToSendToAgent => self::controlePa('PREPAID ABROAD - Freight Invoices disponíveis para envio ao agente', 'As reservas abaixo possuem Freight Invoice disponíveis para envio ao agente'),
            Notice::ReadyToSendToCarrier => self::controlePa('PREPAID ABROAD - Freight Invoices disponíveis para envio ao Armador', 'As reservas abaixo possuem Freight Invoice disponíveis para envio ao Armador'),
            Notice::PendingReceipt => self::controlePa('PREPAID ABROAD - Pendências de Confirmação da Liberação', 'As reservas abaixo possuem Pendências de Confirmação da Liberação'),
            Notice::PendingPayment => self::controlePa('PREPAID ABROAD - Pendências de Pagamento de Frete', 'As reservas abaixo possuem Pendência de Pagamento de Frete ao Armador'),
            Notice::ConfirmedWithoutInvoice => self::controlePa('PREPAID ABROAD - Embarques Confirmados', 'As reservas abaixo tiveram seu embarque confirmado e não tem Freigth Invoice'),
            Notice::AmsMissingUs => self::amsMissing('[Aviso] Embarques sem AMS Enviado', 'Os seguintes embarques ainda não tem AMS enviado:'),
            Notice::AmsMissingMexico => self::amsMissing('[Aviso] Embarques sem AMS Enviado [México]', 'Os seguintes embarques ainda não tem AMS enviado [México]:'),
            Notice::AmsPendingAfrica => self::amsMissing('[Aviso] Embarques para África do Sul sem documentos enviados', 'As informações ainda não foram enviadas para as seguintes reservas:'),
            Notice::AmsPendingUae => self::amsMissing('[Aviso] Embarques para os Emirados Arabes Unidos sem documentos enviados', 'As informações ainda não foram enviadas para as seguintes reservas:'),
            Notice::AmsReminderAfrica => self::amsReminder('South Africa'),
            Notice::AmsReminderUae => self::amsReminder('United Arab Emirates'),
        };

        $tags = [...self::bookingTags(), ...$tags];

        return compact('subject', 'body', 'tags');
    }

    /**
     * Corpo padrão com as {{variáveis}} como chips do editor (nós "mergeTag"). Como texto literal o editor abre o seletor de variáveis
     * ("No merge tag results.") toda vez que o cursor fica depois de {{.
     */
    public static function editorBody(Notice $notice): string
    {
        return preg_replace('/\{\{(\w+)\}\}/', '<span data-type="mergeTag" data-id="$1"></span>', self::for($notice)['body']);
    }

    /** @return array<string, array{label: string, sample: string, html: bool}> variáveis prontas da reserva (BookingTags) */
    private static function bookingTags(): array
    {
        return array_map(fn (array $tag) => self::tag($tag[0], $tag[1]), BookingTags::TAGS);
    }

    /** @return array{label: string, sample: string, html: bool} */
    private static function tag(string $label, string $sample, bool $html = false): array
    {
        return ['label' => $label, 'sample' => $sample, 'html' => $html];
    }

    /** @return array{0: string, 1: string, 2: array<string, array{label: string, sample: string, html: bool}>} */
    private static function controlePa(string $subject, string $intro): array
    {
        return [$subject, $intro.'{{lista}}', ['lista' => self::tag('Tabela de reservas calculada pela rotina', '<table border="1"><tr><th>Reserva</th></tr><tr><td>BKG123</td></tr></table>', true)]];
    }

    /** @return array{0: string, 1: string, 2: array<string, array{label: string, sample: string, html: bool}>} */
    private static function amsMissing(string $subject, string $intro): array
    {
        return [$subject, '<h3>'.$intro.'</h3>{{lista}}', ['lista' => self::tag('Tabela de reservas calculada pela rotina', '<table border="1"><tr><th>Reserva</th><th>Cliente</th><th>ETD</th></tr><tr><td>BKG123</td><td>Cliente Exemplo</td><td>20/11/2026</td></tr></table>', true)]];
    }

    /** @return array{0: string, 1: string, 2: array<string, array{label: string, sample: string, html: bool}>} */
    private static function amsReminder(string $destination): array
    {
        return [
            "DMF Brazil - Shipment Registration to {$destination}: {{reserva}} - {{hbl}} -  [Reminder #{{dias}}]",
            SendAms::INTRO.'{{dados}}',
            ['dias' => self::tag('Dias desde o envio do registro', '3'), 'dados' => self::tag('Tabela com os dados do embarque e os containers', '<table><tr><th align="left">Booking</th><td>BKG123</td></tr></table>', true)],
        ];
    }
}
