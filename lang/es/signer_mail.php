<?php

/*
|--------------------------------------------------------------------------
| Correos al participante — español (Fase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
|
| Mismas claves y marcadores que lang/pt_BR/signer_mail.php. Los valores de
| los marcadores ya llegan escapados para Markdown en el cuerpo; el asunto es
| texto plano.
|
*/

return [
    'greeting' => '¡Hola!',
    'greeting_named' => '¡Hola, :name!',
    'first_name_fallback' => '¿qué tal?',
    'sender_fallback' => 'Un usuario',
    'salutation' => 'Atentamente, AssinaVelox',
    'sender_message' => 'Mensaje de quien envió: ":message"',
    'code_notice' => 'Para confirmar que es usted, enviaremos un código de 6 dígitos a este mismo correo electrónico.',
    'personal_link' => 'Este enlace es personal y fue creado solo para usted — no reenvíe este correo.',

    'invitation' => [
        'signer' => [
            'subject' => ':organization le envió un documento para firmar',
            'reminder_subject' => 'Recordatorio: :title espera su firma',
            'intro' => ':sender, de **:organization**, le envió el documento **:title** (:code) para que lo firme electrónicamente.',
            'reminder_line' => 'Este es un recordatorio: el documento **:title** (:code) todavía espera su firma.',
            'action' => 'Revisar y firmar',
            'reminder_action' => 'Abrir y firmar',
            'deadline' => 'El plazo para firmar termina el :deadline.',
        ],
        'witness' => [
            'subject' => ':organization le pidió que firme un documento como testigo',
            'reminder_subject' => 'Recordatorio: :title espera su firma como testigo',
            'intro' => ':sender, de **:organization**, le pidió que firme electrónicamente el documento **:title** (:code) como testigo.',
            'reminder_line' => 'Este es un recordatorio: el documento **:title** (:code) todavía espera su firma como testigo.',
            'action' => 'Revisar y firmar como testigo',
            'reminder_action' => 'Revisar y firmar como testigo',
            'deadline' => 'El plazo para firmar termina el :deadline.',
        ],
        'approver' => [
            'subject' => ':organization le envió un documento para su aprobación',
            'reminder_subject' => 'Recordatorio: :title espera su aprobación',
            'intro' => ':sender, de **:organization**, le envió el documento **:title** (:code) para que lo revise y lo apruebe electrónicamente.',
            'reminder_line' => 'Este es un recordatorio: el documento **:title** (:code) todavía espera su aprobación.',
            'action' => 'Revisar y aprobar',
            'reminder_action' => 'Revisar y aprobar',
            'deadline' => 'El plazo para aprobar termina el :deadline.',
        ],
        'viewer' => [
            'subject' => ':organization compartió un documento con usted',
            'reminder_subject' => ':organization compartió un documento con usted',
            'intro' => ':sender, de **:organization**, lo incluyó para dar seguimiento al documento **:title** (:code). No necesita firmar nada; cuando el documento se concluya, recibirá la copia final.',
            'reminder_line' => ':sender, de **:organization**, lo incluyó para dar seguimiento al documento **:title** (:code).',
            'action' => 'Seguir el documento',
            'reminder_action' => 'Seguir el documento',
            'deadline' => '',
        ],
    ],

    'reminder' => [
        'subject' => 'Recordatorio: :title espera su firma',
        'line' => 'Este es un recordatorio automático: el documento **:title** (:code), enviado por **:organization**, todavía espera su firma electrónica.',
        'action' => 'Abrir y firmar',
        'deadline' => 'El plazo para firmar termina el :deadline.',
        'link_notice' => 'Este enlace reemplaza a los enviados antes — use siempre el correo más reciente. Es personal: no reenvíe este mensaje.',
    ],

    'otp' => [
        'subject' => 'Su código para firmar :title',
        'line' => 'Use el código de abajo para confirmar que es usted y firmar el documento **:title** (:code), enviado por **:organization**.',
        'ttl' => 'El código es válido por :minutes minutos y solo puede usarse una vez.',
        'ignore' => 'Si usted no pidió este código, ignore este mensaje: sin él, no se firma nada.',
        'salutation' => 'Equipo :app',
    ],

    'canceled' => [
        'subject' => 'Documento cerrado: :title',
        'refused_line' => 'La solicitud de firma del documento **:title** (:code) se cerró porque uno de los firmantes rechazó firmar.',
        'canceled_line' => '**:organization** canceló la solicitud de firma del documento **:title** (:code).',
        'reason' => 'Motivo informado: ":reason"',
        'no_action' => 'El enlace que recibió ya no es válido y no necesita hacer nada.',
        'contact' => 'Si tiene dudas, hable con :organization.',
    ],

    'expiring' => [
        'subject' => 'Su plazo para firmar :title está terminando',
        'line' => 'El documento **:title** (:code) todavía espera su firma.',
        'deadline' => 'El plazo termina el :deadline. Después de eso el enlace deja de funcionar y la solicitud tiene que reenviarse.',
        'deadline_unknown' => 'El plazo está por terminar. Después de eso el enlace deja de funcionar.',
        'action' => 'Firmar ahora',
        'personal_link' => 'Este enlace es personal — no reenvíe este correo.',
    ],

    // SMS/WhatsApp al participante (App\Services\Signing\Channels\ChannelMessages). Texto plano, una línea.
    'channel' => [
        'otp' => ':app: su código de confirmación para el documento ":title" es :code. Vale :minutes min. No comparta este código.',
        'invitation' => ':organization le envió el documento ":title" a través de :app. Acceda: :url',
        'reminder' => 'Recordatorio: :organization le espera en el documento ":title" a través de :app. Acceda: :url',
    ],
];
