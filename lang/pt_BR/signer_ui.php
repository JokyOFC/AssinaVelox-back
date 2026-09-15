<?php

/*
|--------------------------------------------------------------------------
| Rótulos que o servidor envia à página pública (Fase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
|
| Referência: os mesmos textos dos enums (AcceptanceAction, RecipientRole) e
| do SignerPageProps. Só são consultados com a flag `multilingual` ligada e
| idioma diferente do PT-BR; em PT-BR a página recebe os textos de sempre.
|
*/

return [
    'action' => [
        'label' => [
            'sign' => 'Aceite eletrônico',
            'witness' => 'Aceite eletrônico como testemunha',
            'approve' => 'Aprovação eletrônica',
            'view' => 'Cópia para acompanhamento',
        ],
        'button' => [
            'sign' => 'Assinar documento',
            'witness' => 'Assinar como testemunha',
            'approve' => 'Aprovar documento',
        ],
    ],
    'role' => [
        'signer' => 'Signatário',
        'witness' => 'Testemunha',
        'approver' => 'Aprovador',
        'viewer' => 'Visualizador',
    ],
    'locale' => [
        'invalid' => 'Escolha um dos idiomas disponíveis.',
        'draft_only' => 'O idioma do participante só pode ser alterado antes do envio.',
        'saved' => 'Idioma do participante salvo.',
    ],
];
