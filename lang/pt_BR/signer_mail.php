<?php

/*
|--------------------------------------------------------------------------
| E-mails ao participante (Fase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
|
| Referência: exatamente os textos de sempre das notificações ao participante
| (convite, lembrete, código, cancelamento, prazo acabando). Os valores dos
| marcadores já chegam escapados para Markdown (App\Support\MailText) no
| corpo; o assunto é texto puro.
|
*/

return [
    'greeting' => 'Olá!',
    'greeting_named' => 'Olá, :name!',
    'first_name_fallback' => 'tudo bem?',
    'sender_fallback' => 'Um usuário',
    'salutation' => 'Atenciosamente, AssinaVelox',
    'sender_message' => 'Mensagem de quem enviou: ":message"',
    'code_notice' => 'Para confirmar que é você, vamos enviar um código de 6 dígitos para este mesmo e-mail.',
    'personal_link' => 'Este link é pessoal e foi criado só para você — não encaminhe este e-mail.',

    'invitation' => [
        'signer' => [
            'subject' => ':organization enviou um documento para você assinar',
            'reminder_subject' => 'Lembrete: :title aguarda sua assinatura',
            'intro' => ':sender, de **:organization**, enviou o documento **:title** (:code) para você assinar eletronicamente.',
            'reminder_line' => 'Este é um lembrete: o documento **:title** (:code) ainda aguarda a sua assinatura.',
            'action' => 'Revisar e assinar',
            'reminder_action' => 'Abrir e assinar',
            'deadline' => 'O prazo para assinar termina em :deadline.',
        ],
        'witness' => [
            'subject' => ':organization pediu que você assine um documento como testemunha',
            'reminder_subject' => 'Lembrete: :title aguarda sua assinatura como testemunha',
            'intro' => ':sender, de **:organization**, pediu que você assine eletronicamente o documento **:title** (:code) como testemunha.',
            'reminder_line' => 'Este é um lembrete: o documento **:title** (:code) ainda aguarda a sua assinatura como testemunha.',
            'action' => 'Revisar e assinar como testemunha',
            'reminder_action' => 'Revisar e assinar como testemunha',
            'deadline' => 'O prazo para assinar termina em :deadline.',
        ],
        'approver' => [
            'subject' => ':organization enviou um documento para a sua aprovação',
            'reminder_subject' => 'Lembrete: :title aguarda a sua aprovação',
            'intro' => ':sender, de **:organization**, enviou o documento **:title** (:code) para você revisar e aprovar eletronicamente.',
            'reminder_line' => 'Este é um lembrete: o documento **:title** (:code) ainda aguarda a sua aprovação.',
            'action' => 'Revisar e aprovar',
            'reminder_action' => 'Revisar e aprovar',
            'deadline' => 'O prazo para aprovar termina em :deadline.',
        ],
        'viewer' => [
            'subject' => ':organization compartilhou um documento com você',
            'reminder_subject' => ':organization compartilhou um documento com você',
            'intro' => ':sender, de **:organization**, incluiu você para acompanhar o documento **:title** (:code). Você não precisa assinar nada; quando o documento for concluído, você receberá a cópia final.',
            'reminder_line' => ':sender, de **:organization**, incluiu você para acompanhar o documento **:title** (:code).',
            'action' => 'Acompanhar o documento',
            'reminder_action' => 'Acompanhar o documento',
            'deadline' => '',
        ],
    ],

    'reminder' => [
        'subject' => 'Lembrete: :title aguarda sua assinatura',
        'line' => 'Este é um lembrete automático: o documento **:title** (:code), enviado por **:organization**, ainda aguarda a sua assinatura eletrônica.',
        'action' => 'Abrir e assinar',
        'deadline' => 'O prazo para assinar termina em :deadline.',
        'link_notice' => 'Este link substitui os enviados antes — use sempre o e-mail mais recente. Ele é pessoal: não encaminhe esta mensagem.',
    ],

    'otp' => [
        'subject' => 'Seu código para assinar :title',
        'line' => 'Use o código abaixo para confirmar sua identidade e assinar o documento **:title** (:code), enviado por **:organization**.',
        'ttl' => 'O código vale por :minutes minutos e só pode ser usado uma vez.',
        'ignore' => 'Se você não pediu este código, ignore esta mensagem: sem ele, nada é assinado.',
        'salutation' => 'Equipe :app',
    ],

    'canceled' => [
        'subject' => 'Documento encerrado: :title',
        'refused_line' => 'A solicitação de assinatura do documento **:title** (:code) foi encerrada porque um dos signatários recusou assinar.',
        'canceled_line' => '**:organization** cancelou a solicitação de assinatura do documento **:title** (:code).',
        'reason' => 'Motivo informado: ":reason"',
        'no_action' => 'O link que você recebeu não é mais válido e nenhuma ação é necessária da sua parte.',
        'contact' => 'Em caso de dúvida, fale com :organization.',
    ],

    'expiring' => [
        'subject' => 'Seu prazo para assinar :title está acabando',
        'line' => 'O documento **:title** (:code) ainda aguarda a sua assinatura.',
        'deadline' => 'O prazo termina em :deadline. Depois disso o link deixa de funcionar e a solicitação precisa ser reenviada.',
        'deadline_unknown' => 'O prazo está próximo do fim. Depois disso o link deixa de funcionar.',
        'action' => 'Assinar agora',
        'personal_link' => 'Este link é pessoal — não encaminhe este e-mail.',
    ],

    // SMS/WhatsApp ao participante (App\Services\Signing\Channels\ChannelMessages). Texto puro,
    // uma linha. Em PT-BR o serviço usa o texto de sempre; esta entrada mantém a paridade.
    'channel' => [
        'otp' => ':app: seu código de confirmação para o documento ":title" é :code. Vale :minutes min. Não compartilhe este código.',
        'invitation' => ':organization enviou o documento ":title" para você pelo :app. Acesse: :url',
        'reminder' => 'Lembrete: :organization aguarda você no documento ":title" pelo :app. Acesse: :url',
    ],
];
