<?php

/*
|--------------------------------------------------------------------------
| Etiquetas que el servidor envía a la página pública — español (Fase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
*/

return [
    'action' => [
        'label' => [
            'sign' => 'Aceptación electrónica',
            'witness' => 'Aceptación electrónica como testigo',
            'approve' => 'Aprobación electrónica',
            'view' => 'Copia para seguimiento',
        ],
        'button' => [
            'sign' => 'Firmar documento',
            'witness' => 'Firmar como testigo',
            'approve' => 'Aprobar documento',
        ],
    ],
    'role' => [
        'signer' => 'Firmante',
        'witness' => 'Testigo',
        'approver' => 'Aprobador',
        'viewer' => 'Observador',
    ],
    'locale' => [
        'invalid' => 'Elija uno de los idiomas disponibles.',
        'draft_only' => 'El idioma del participante solo puede cambiarse antes del envío.',
        'saved' => 'Idioma del participante guardado.',
    ],
];
