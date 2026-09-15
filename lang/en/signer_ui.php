<?php

/*
|--------------------------------------------------------------------------
| Labels the server sends to the public page — English (Phase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
*/

return [
    'action' => [
        'label' => [
            'sign' => 'Electronic acceptance',
            'witness' => 'Electronic acceptance as a witness',
            'approve' => 'Electronic approval',
            'view' => 'Copy for follow-up',
        ],
        'button' => [
            'sign' => 'Sign document',
            'witness' => 'Sign as a witness',
            'approve' => 'Approve document',
        ],
    ],
    'role' => [
        'signer' => 'Signer',
        'witness' => 'Witness',
        'approver' => 'Approver',
        'viewer' => 'Viewer',
    ],
    'locale' => [
        'invalid' => 'Choose one of the available languages.',
        'draft_only' => 'The participant\'s language can only be changed before sending.',
        'saved' => 'Participant language saved.',
    ],
];
