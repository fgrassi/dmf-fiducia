<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Sistema legado (tracking): links para telas que ainda não foram portadas e arquivos de uploads/.
    'tracking' => [
        'url' => rtrim((string) env('TRACKING_URL'), '/'),
    ],

    // API de NFS-e: por padrão a do config_nfse (banco). Em dev/homologação, apontar aqui para não emitir em produção.
    'nfse' => [
        'url' => env('NFSE_API_URL'),
        'token' => env('NFSE_API_TOKEN'),
    ],

    // Geração automática das ICs (cobranca:gerar no agendador). Ligar só quando o cron do tracking for desligado.
    'cobranca' => [
        'automatic_generation' => env('COBRANCA_GERACAO_AUTOMATICA', false),
        // avisos e relatórios do módulo cron do legado (cobranca:aviso-*, cobranca:relatorio-aberta, cobranca:relacao-liberacao)
        'automatic_notices' => env('COBRANCA_ROTINAS_AUTOMATICAS', false),
        // token Bearer do GET /api/cron/recalculaCobranca (job UpdateCharge da intranet); vazio = endpoint recusa (503)
        'recalculation_token' => env('COBRANCA_RECALCULO_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
