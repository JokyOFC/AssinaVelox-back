<?php

/*
|--------------------------------------------------------------------------
| Emails to the participant — English (Phase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
|
| Same keys and placeholders as lang/pt_BR/signer_mail.php. Placeholder values
| already arrive escaped for Markdown in the body; the subject is plain text.
|
*/

return [
    'greeting' => 'Hello!',
    'greeting_named' => 'Hello, :name!',
    'first_name_fallback' => 'there',
    'sender_fallback' => 'A user',
    'salutation' => 'Kind regards, AssinaVelox',
    'sender_message' => 'Message from the sender: ":message"',
    'code_notice' => 'To confirm it is you, we will send a 6-digit code to this same email address.',
    'personal_link' => 'This link is personal and was created just for you — do not forward this email.',

    'invitation' => [
        'signer' => [
            'subject' => ':organization sent you a document to sign',
            'reminder_subject' => 'Reminder: :title is waiting for your signature',
            'intro' => ':sender, from **:organization**, sent you the document **:title** (:code) to sign electronically.',
            'reminder_line' => 'This is a reminder: the document **:title** (:code) is still waiting for your signature.',
            'action' => 'Review and sign',
            'reminder_action' => 'Open and sign',
            'deadline' => 'The deadline to sign is :deadline.',
        ],
        'witness' => [
            'subject' => ':organization asked you to sign a document as a witness',
            'reminder_subject' => 'Reminder: :title is waiting for your signature as a witness',
            'intro' => ':sender, from **:organization**, asked you to sign the document **:title** (:code) electronically as a witness.',
            'reminder_line' => 'This is a reminder: the document **:title** (:code) is still waiting for your signature as a witness.',
            'action' => 'Review and sign as a witness',
            'reminder_action' => 'Review and sign as a witness',
            'deadline' => 'The deadline to sign is :deadline.',
        ],
        'approver' => [
            'subject' => ':organization sent you a document for your approval',
            'reminder_subject' => 'Reminder: :title is waiting for your approval',
            'intro' => ':sender, from **:organization**, sent you the document **:title** (:code) to review and approve electronically.',
            'reminder_line' => 'This is a reminder: the document **:title** (:code) is still waiting for your approval.',
            'action' => 'Review and approve',
            'reminder_action' => 'Review and approve',
            'deadline' => 'The deadline to approve is :deadline.',
        ],
        'viewer' => [
            'subject' => ':organization shared a document with you',
            'reminder_subject' => ':organization shared a document with you',
            'intro' => ':sender, from **:organization**, added you to follow the document **:title** (:code). You do not need to sign anything; when the document is completed, you will receive the final copy.',
            'reminder_line' => ':sender, from **:organization**, added you to follow the document **:title** (:code).',
            'action' => 'Follow the document',
            'reminder_action' => 'Follow the document',
            'deadline' => '',
        ],
    ],

    'reminder' => [
        'subject' => 'Reminder: :title is waiting for your signature',
        'line' => 'This is an automatic reminder: the document **:title** (:code), sent by **:organization**, is still waiting for your electronic signature.',
        'action' => 'Open and sign',
        'deadline' => 'The deadline to sign is :deadline.',
        'link_notice' => 'This link replaces the ones sent before — always use the most recent email. It is personal: do not forward this message.',
    ],

    'otp' => [
        'subject' => 'Your code to sign :title',
        'line' => 'Use the code below to confirm it is you and sign the document **:title** (:code), sent by **:organization**.',
        'ttl' => 'The code is valid for :minutes minutes and can only be used once.',
        'ignore' => 'If you did not request this code, ignore this message: without it, nothing is signed.',
        'salutation' => 'The :app team',
    ],

    'canceled' => [
        'subject' => 'Document closed: :title',
        'refused_line' => 'The signature request for the document **:title** (:code) was closed because one of the signers declined to sign.',
        'canceled_line' => '**:organization** canceled the signature request for the document **:title** (:code).',
        'reason' => 'Reason given: ":reason"',
        'no_action' => 'The link you received is no longer valid and no action is needed on your part.',
        'contact' => 'If you have any questions, contact :organization.',
    ],

    'expiring' => [
        'subject' => 'Your deadline to sign :title is ending',
        'line' => 'The document **:title** (:code) is still waiting for your signature.',
        'deadline' => 'The deadline is :deadline. After that the link stops working and the request needs to be sent again.',
        'deadline_unknown' => 'The deadline is about to end. After that the link stops working.',
        'action' => 'Sign now',
        'personal_link' => 'This link is personal — do not forward this email.',
    ],

    // SMS/WhatsApp to the participant (App\Services\Signing\Channels\ChannelMessages). Plain text, one line.
    'channel' => [
        'otp' => ':app: your confirmation code for the document ":title" is :code. Valid for :minutes min. Do not share this code.',
        'invitation' => ':organization sent you the document ":title" through :app. Open: :url',
        'reminder' => 'Reminder: :organization is waiting for you on the document ":title" through :app. Open: :url',
    ],
];
