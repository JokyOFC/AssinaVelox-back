<?php

/*
|--------------------------------------------------------------------------
| Legal texts of the public page — English (Phase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
|
| COURTESY TRANSLATION. The reference version is the Brazilian Portuguese one
| (lang/pt_BR/signer_legal.php, identical to App\Services\Signing\ConsentText),
| which is the text recorded as evidence. This translation has NOT been reviewed
| by a legal professional yet (docs/fase-3/multilingue.md §7). Same keys and the
| same placeholders as the reference file.
|
*/

return [
    'heading' => [
        'sign' => 'Electronic acceptance statement',
        'witness' => 'Electronic acceptance statement as a witness',
        'approve' => 'Electronic approval statement',
    ],
    'version_line' => ':heading — version :version',
    'intro' => 'I, :name, identified in this request by the email address :email,:capacity declare that:',
    'capacity' => [
        'sign' => '',
        'witness' => ' acting as a witness,',
        'approve' => ' acting as an approver,',
    ],
    'item1' => [
        'sign' => [
            'single' => '1. I have read in full the document ":title", sent by :sender, whose content shown on this screen matches the SHA-256 digest :sha, and I agree with its content.',
            'multi' => '1. I have read in full the :count documents of the request ":title", sent by :sender, whose contents shown on this screen match the SHA-256 digests listed below, and I agree with the content of each of them.',
        ],
        'witness' => [
            'single' => '1. I had full access to the document ":title", sent by :sender, whose content shown on this screen matches the SHA-256 digest :sha, and I declare, as a witness, that I became aware of its content and of its execution through this platform. This statement does not make me a party to the document, nor does it express my personal agreement with the obligations set out in it.',
            'multi' => '1. I had full access to the :count documents of the request ":title", sent by :sender, whose contents shown on this screen match the SHA-256 digests listed below, and I declare, as a witness, that I became aware of their content and of their execution through this platform. This statement does not make me a party to the documents, nor does it express my personal agreement with the obligations set out in them.',
        ],
        'approve' => [
            'single' => '1. I have read in full the document ":title", sent by :sender, whose content shown on this screen matches the SHA-256 digest :sha, and I approve the content of the document.',
            'multi' => '1. I have read in full the :count documents of the request ":title", sent by :sender, whose contents shown on this screen match the SHA-256 digests listed below, and I approve the content of each of them.',
        ],
    ],
    'document_line' => '   1.:index. ":document" — SHA-256 :sha',
    'item2' => [
        'sign' => '2. The fields I filled in on this screen and the signature image I provided (drawn, typed or uploaded) are my own. I acknowledge that this image is a visual representation and that my expression of will is this acceptance.',
        'witness' => '2. The fields I filled in on this screen and the signature image I provided (drawn, typed or uploaded) are my own. I acknowledge that this image is a visual representation and that my expression is this electronic acceptance as a witness.',
        'approve' => '2. This approval contains no visual representation of a signature and does not make me a signatory of the document. Any fields I filled in on this screen are my own.',
    ],
    'item3' => '3. I am aware that AssinaVelox will record, as evidence of :subject: the server date and time (UTC), my IP address, my browser identification, the authentication method (:method):simulated, the exact version of :version_of, the fields shown and the values filled in:extras, and this statement.',
    'subject' => [
        'acceptance' => 'this acceptance',
        'approval' => 'this approval',
    ],
    'version_of' => [
        'single' => 'the document',
        'multi' => 'each document',
    ],
    'method' => [
        'email' => 'one-time code confirmed at the email address above',
        'sms' => 'one-time code sent by SMS to the mobile number provided by the sender',
        'whatsapp' => 'one-time code sent by WhatsApp to the mobile number provided by the sender',
        'pin_suffix' => ', followed by the PIN agreed with the sender',
    ],
    'simulated_note' => ' [:channel delivery simulated in this installation: no message was sent to the phone]',
    'extras' => [
        'cpf_lookup' => ', the result of the registry lookup of the CPF I entered, made through a service used by AssinaVelox (the lookup does not confirm that I am the holder of the CPF)',
        'photos' => ', the photos I sent on this screen (:photos), kept as a record, with no identity verification',
        // Phase 4 §4.1: with document face verification required, the photos go to the named provider.
        'photos_verification' => ', the photos I sent on this screen (:photos), forwarded to the external provider :provider, which compares the photo taken on the spot with the photo on the document and returns a result — the result reported by the provider is kept as evidence of this acceptance and the photos follow the retention period stated in the privacy notice —',
    ],
    'item4' => [
        'certificate' => [
            'single' => '4. I am aware that, once all acceptances have been collected, AssinaVelox (:operator) will consolidate the document with an evidence page and apply to the final file a cryptographic signature with a digital certificate held by AssinaVelox itself. That signature identifies AssinaVelox as the operator of the platform and makes it possible to detect later changes to the file; it is not my personal signature, nor a digital certificate issued in my name.',
            'multi' => '4. I am aware that, once all acceptances have been collected, AssinaVelox (:operator) will consolidate each document with an evidence page and apply to each final file a cryptographic signature with a digital certificate held by AssinaVelox itself. That signature identifies AssinaVelox as the operator of the platform and makes it possible to detect later changes to the file; it is not my personal signature, nor a digital certificate issued in my name.',
        ],
        'no_certificate' => [
            'single' => '4. I am aware that, once all acceptances have been collected, AssinaVelox (:operator) will consolidate the document with an evidence page and complete it as an electronic acceptance with evidence, without a cryptographic signature. The integrity of the final file can be checked through its SHA-256 digest, published on the verification page :url with the code :code.',
            'multi' => '4. I am aware that, once all acceptances have been collected, AssinaVelox (:operator) will consolidate each document with an evidence page and complete them as an electronic acceptance with evidence, without a cryptographic signature. The integrity of each final file can be checked through its respective SHA-256 digest, published on the verification page :url with the code :code.',
        ],
    ],
    'item5' => [
        'sign' => '5. I am aware that the validity and effects of this acceptance depend on the applicable law and on the agreement between the parties, and that AssinaVelox does not guarantee its acceptance by third parties.',
        'witness' => '5. I am aware that the validity and effects of this witness statement depend on the applicable law and on the agreement between the parties, and that AssinaVelox does not guarantee its acceptance by third parties.',
        'approve' => '5. I am aware that the validity and effects of this approval depend on the applicable law and on the agreement between the parties, and that AssinaVelox does not guarantee its acceptance by third parties.',
    ],
    'item6' => [
        'sign' => '6. I have read the privacy notice shown on this page and I know I can decline to sign by giving a reason.',
        'witness' => '6. I have read the privacy notice shown on this page and I know I can decline to sign as a witness by giving a reason.',
        'approve' => '6. I have read the privacy notice shown on this page and I know I can decline the approval by giving a reason.',
    ],

    'label' => [
        'sign' => [
            'single' => 'I have read the document :title and declare that I agree with its content and that the data recorded here — date and time, IP address, browser, :code, the exact version of the document and the fields I filled in — constitute evidence of my electronic acceptance.',
            'multi' => 'I have read the :count documents of :title and declare that I agree with the content of each one and that the data recorded here — date and time, IP address, browser, :code, the exact version of each document and the fields I filled in — constitute evidence of my electronic acceptance.',
        ],
        'witness' => [
            'single' => 'I had access to the document :title and declare, as a witness, that the data recorded here — date and time, IP address, browser, :code, the exact version of the document and the fields I filled in — constitute evidence of my electronic acceptance as a witness.',
            'multi' => 'I had access to the :count documents of :title and declare, as a witness, that the data recorded here — date and time, IP address, browser, :code, the exact version of each document and the fields I filled in — constitute evidence of my electronic acceptance as a witness.',
        ],
        'approve' => [
            'single' => 'I have read the document :title and declare that I approve its content and that the data recorded here — date and time, IP address, browser, :code and the exact version of the document — constitute evidence of my electronic approval.',
            'multi' => 'I have read the :count documents of :title and declare that I approve their content and that the data recorded here — date and time, IP address, browser, :code and the exact version of each document — constitute evidence of my electronic approval.',
        ],
    ],
    'code' => [
        'email' => 'code confirmed by email',
        'sms' => 'code confirmed by SMS',
        'whatsapp' => 'code confirmed by WhatsApp',
        'pin_suffix' => ' and PIN agreed with the sender',
    ],

    'privacy_summary' => 'This document was sent by :organization. :purpose, AssinaVelox will record the date, IP address, browser, the exact version of the document and the :code.',
    'privacy_purpose' => [
        'signer' => 'To record your acceptance',
        'approver' => 'To record your approval',
        'viewer' => 'To record your viewing',
    ],
    'privacy_notice' => [
        'signer' => [
            'How your data is used on this page',
            'Who is responsible for your data. This document was sent by :organization, who decided to request your signature and is the controller of your personal data. AssinaVelox (:operator) is the processor: it handles the data only to carry out the signature, following the sender\'s instructions.',
            'What we record and why. So that your acceptance has value as evidence, we record: :registered; the server date and time (UTC); your IP address and browser identification; the exact version of the document you saw (SHA-256 digest); the fields shown and the values you fill in; the image of your signature (drawn, typed or uploaded); and the acceptance text you tick. This data makes up the evidence page attached to the final document, delivered to the sender and to you.',
            'Opening this link is recorded. When you open this page, we record the date, IP address and browser as "opening detected". This does not mean that you read or agreed to anything.',
            'If you do not want to sign. You can simply close this page, or use "Decline to sign" and give a reason, which will be sent to the sender. Not requesting the code creates no acceptance.',
            'What we do not do. We do not ask for :not_asked. We do not use tracking cookies or ads on this page; only essential session and security cookies.',
            'For how long. The evidence is kept while the document exists in the sender\'s account or while there is a legal obligation or a need to prove the acceptance.',
            'Your rights. To access, correct or ask for information about your data, contact the sender first. You can also write to AssinaVelox at :support.',
        ],
        'approver' => [
            'How your data is used on this page',
            'Who is responsible for your data. This document was sent by :organization, who decided to request your approval and is the controller of your personal data. AssinaVelox (:operator) is the processor: it handles the data only to record the approval, following the sender\'s instructions.',
            'What we record and why. So that your approval has value as evidence, we record: :registered; the server date and time (UTC); your IP address and browser identification; the exact version of the document you saw (SHA-256 digest); the fields shown and the values you fill in; and the approval text you tick. The approval does not use a signature image. This data makes up the evidence page attached to the final document, delivered to the sender and to you.',
            'Opening this link is recorded. When you open this page, we record the date, IP address and browser as "opening detected". This does not mean that you read or agreed to anything.',
            'If you do not want to approve. You can simply close this page, or decline and give a reason, which will be sent to the sender. Not requesting the code creates no approval.',
            'What we do not do. We do not ask for :not_asked. We do not use tracking cookies or ads on this page; only essential session and security cookies.',
            'For how long. The evidence is kept while the document exists in the sender\'s account or while there is a legal obligation or a need to prove the approval.',
            'Your rights. To access, correct or ask for information about your data, contact the sender first. You can also write to AssinaVelox at :support.',
        ],
        'viewer' => [
            'How your data is used on this page',
            'Who is responsible for your data. This document was sent by :organization, who decided to share it with you for follow-up and is the controller of your personal data. AssinaVelox (:operator) is the processor: it handles the data only to make the document available, following the sender\'s instructions.',
            'What we record and why. To prove access to the document, we record: :registered; the server date and time (UTC); your IP address and browser identification; and the exact version of the document you saw (SHA-256 digest). As a viewer, you do not record an acceptance, do not fill in fields and no signature image is recorded.',
            'Opening this link is recorded. When you open this page, we record the date, IP address and browser as "opening detected". This does not mean that you read or agreed to anything.',
            'You do not need to do anything. You can simply close this page. Not requesting the code creates no record other than the opening.',
            'What we do not do. We do not ask for :not_asked. We do not use tracking cookies or ads on this page; only essential session and security cookies.',
            'For how long. The records are kept while the document exists in the sender\'s account or while there is a legal obligation or a need to prove the access.',
            'Your rights. To access, correct or ask for information about your data, contact the sender first. You can also write to AssinaVelox at :support.',
        ],
    ],
    'registered' => [
        'base' => ':who (provided by the sender); the confirmation code sent :where (stored only in irreversible form)',
        'who' => [
            'email' => 'your name and email address',
            'phone' => 'your name, email address and mobile number',
        ],
        'where' => [
            'email' => 'to your email address',
            'sms' => 'by SMS to your mobile phone',
            'whatsapp' => 'by WhatsApp to your mobile phone',
        ],
        'pin' => '; the confirmation of the PIN the sender agreed with you (the PIN is also stored only in irreversible form)',
        'cpf_lookup' => '; the CPF you enter in the document, which is not checked only by its digits: it is also sent to a registry lookup service used by AssinaVelox, only to check the status of the number in that service\'s database, and the result of the lookup is recorded with the acceptance (the lookup does not confirm that you are the holder of the CPF)',
        'cpf' => '; the CPF you enter in the document, checked only by its digits (this does not confirm who holds it)',
        'photos' => '; the photos you send (:photos), kept as a record of the acceptance, with no face comparison, no image analysis and no reading of the document, and :retention',
        // Phase 4 §4.1: sharing with the named provider and the purpose (LGPD art. 9 and 11).
        'photos_verification' => '; the photos you send (:photos), forwarded to the external provider :provider only to compare the photo taken on the spot with the photo on the document (the platform does not compare the images: it sends the photos and records the provider\'s answer, which is kept as evidence of the acceptance), and :retention',
        'retention_days' => 'deleted :days days later',
        'retention_kept' => 'kept while the document exists in the sender\'s account',
    ],
    'not_asked' => [
        'password' => 'a password',
        'cpf' => 'CPF',
        'photo' => 'a photo',
        'location' => 'your location',
        'or' => ' or ',
    ],
    'photo_kinds' => [
        'selfie' => 'face photo',
        'document_front' => 'photo of the document (front)',
        'document_back' => 'photo of the document (back)',
    ],

    'completion' => [
        'certificate' => 'At the end, AssinaVelox will apply to the file a cryptographic signature with a certificate it holds itself. It identifies the operator and detects later changes; it is not your personal signature.',
        'no_certificate' => 'At the end, this document will be completed as an electronic acceptance with evidence, without a cryptographic signature. The integrity of the file is checked through the SHA-256 digest published on the verification page.',
        'participant_certificate' => 'At the end, AssinaVelox will apply to the file a cryptographic signature with a certificate it holds itself (it identifies the operator; it is not your personal signature). ',
        'participant_no_certificate' => 'At the end, this document will be completed as an electronic acceptance with evidence. ',
        'participant_suffix' => 'If you send your own digital certificate within the deadline, the file will also receive a cryptographic signature made with your own certificate, in addition to your acceptance. The integrity of the file is checked through the SHA-256 digest published on the verification page.',
    ],
];
