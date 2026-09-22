<?php

/*
|--------------------------------------------------------------------------
| Textos jurídicos da página pública — modelos (Fase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
|
| Esta é a versão de REFERÊNCIA. Ela reproduz, palavra por palavra, o que
| App\Services\Signing\ConsentText gera (o teste ConsentParityTest compara os
| dois): a declaração, o rótulo da caixa, o aviso de privacidade e o aviso de
| conclusão. A declaração gravada como evidência continua sendo a do
| ConsentText; estes modelos existem para que en/es tenham exatamente a mesma
| estrutura (lang/en/signer_legal.php e lang/es/signer_legal.php são tradução
| de cortesia até a revisão profissional por idioma).
|
| Mudou uma palavra aqui? Mude no ConsentText também — e crie nova versão.
| Espaços no começo/fim de alguns valores são intencionais.
|
*/

return [
    'heading' => [
        'sign' => 'Declaração de aceite eletrônico',
        'witness' => 'Declaração de aceite eletrônico como testemunha',
        'approve' => 'Declaração de aprovação eletrônica',
    ],
    'version_line' => ':heading — versão :version',
    'intro' => 'Eu, :name, identificado(a) nesta solicitação pelo e-mail :email,:capacity declaro que:',
    'capacity' => [
        'sign' => '',
        'witness' => ' na qualidade de testemunha,',
        'approve' => ' na qualidade de aprovador(a),',
    ],
    'item1' => [
        'sign' => [
            'single' => '1. Li integralmente o documento ":title", enviado por :sender, cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 :sha, e concordo com o seu conteúdo.',
            'multi' => '1. Li integralmente os :count documentos da solicitação ":title", enviados por :sender, cujos conteúdos apresentados nesta tela correspondem aos resumos SHA-256 relacionados abaixo, e concordo com o conteúdo de cada um deles.',
        ],
        'witness' => [
            'single' => '1. Tive acesso integral ao documento ":title", enviado por :sender, cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 :sha, e declaro, na qualidade de testemunha, ter tomado conhecimento do seu conteúdo e da sua celebração por meio desta plataforma. Esta declaração não me torna parte do documento nem expressa concordância pessoal com as obrigações nele previstas.',
            'multi' => '1. Tive acesso integral aos :count documentos da solicitação ":title", enviados por :sender, cujos conteúdos apresentados nesta tela correspondem aos resumos SHA-256 relacionados abaixo, e declaro, na qualidade de testemunha, ter tomado conhecimento do seu conteúdo e da sua celebração por meio desta plataforma. Esta declaração não me torna parte dos documentos nem expressa concordância pessoal com as obrigações neles previstas.',
        ],
        'approve' => [
            'single' => '1. Li integralmente o documento ":title", enviado por :sender, cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 :sha, e aprovo o conteúdo do documento.',
            'multi' => '1. Li integralmente os :count documentos da solicitação ":title", enviados por :sender, cujos conteúdos apresentados nesta tela correspondem aos resumos SHA-256 relacionados abaixo, e aprovo o conteúdo de cada um deles.',
        ],
    ],
    'document_line' => '   1.:index. ":document" — SHA-256 :sha',
    'item2' => [
        'sign' => '2. Os campos que preenchi nesta tela e a imagem de assinatura que forneci (desenhada, digitada ou enviada) são de minha autoria. Reconheço que essa imagem é uma representação visual e que a minha manifestação de vontade é este aceite.',
        'witness' => '2. Os campos que preenchi nesta tela e a imagem de assinatura que forneci (desenhada, digitada ou enviada) são de minha autoria. Reconheço que essa imagem é uma representação visual e que a minha manifestação é este aceite eletrônico como testemunha.',
        'approve' => '2. Esta aprovação não contém representação visual de assinatura e não me torna signatário(a) do documento. Os campos que eventualmente preenchi nesta tela são de minha autoria.',
    ],
    'item3' => '3. Estou ciente de que a AssinaVelox registrará, como evidência :subject: a data e a hora do servidor (UTC), o meu endereço IP, a identificação do meu navegador, o método de autenticação (:method):simulated, a versão exata :version_of, os campos apresentados e os valores preenchidos:extras, e esta declaração.',
    'subject' => [
        'acceptance' => 'deste aceite',
        'approval' => 'desta aprovação',
    ],
    'version_of' => [
        'single' => 'do documento',
        'multi' => 'de cada documento',
    ],
    'method' => [
        'email' => 'código de uso único confirmado no e-mail acima',
        'sms' => 'código de uso único enviado por SMS ao celular informado pela remetente',
        'whatsapp' => 'código de uso único enviado por WhatsApp ao celular informado pela remetente',
        'pin_suffix' => ', seguido do PIN combinado com a remetente',
    ],
    'simulated_note' => ' [envio por :channel simulado nesta instalação: nenhuma mensagem foi transmitida ao celular]',
    'extras' => [
        'cpf_lookup' => ', o resultado da consulta cadastral do CPF que informei, feita a um serviço usado pela AssinaVelox (a consulta não confirma que sou o titular do CPF)',
        'photos' => ', as fotos que enviei nesta tela (:photos), guardadas como registro, sem verificação de identidade',
        // Fase 4 §4.1: com a verificação facial com documento exigida, as fotos saem para o provedor nomeado.
        'photos_verification' => ', as fotos que enviei nesta tela (:photos), encaminhadas ao provedor externo :provider, que compara a foto tirada na hora com a foto do documento e devolve um resultado — o resultado informado pelo provedor fica guardado como evidência deste aceite e as fotos seguem o prazo de guarda informado no aviso de privacidade —',
    ],
    'item4' => [
        'certificate' => [
            'single' => '4. Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (:operator) consolidará o documento com uma página de evidências e aplicará ao arquivo final uma assinatura criptográfica com certificado digital de sua própria titularidade. Essa assinatura identifica a AssinaVelox como operadora da plataforma e permite detectar alterações posteriores no arquivo; ela não é a minha assinatura pessoal nem um certificado digital emitido em meu nome.',
            'multi' => '4. Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (:operator) consolidará cada documento com uma página de evidências e aplicará a cada arquivo final uma assinatura criptográfica com certificado digital de sua própria titularidade. Essa assinatura identifica a AssinaVelox como operadora da plataforma e permite detectar alterações posteriores no arquivo; ela não é a minha assinatura pessoal nem um certificado digital emitido em meu nome.',
        ],
        'no_certificate' => [
            'single' => '4. Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (:operator) consolidará o documento com uma página de evidências e o concluirá como aceite eletrônico com evidências, sem assinatura criptográfica. A integridade do arquivo final poderá ser conferida pelo seu resumo SHA-256, publicado na página de verificação :url com o código :code.',
            'multi' => '4. Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (:operator) consolidará cada documento com uma página de evidências e os concluirá como aceite eletrônico com evidências, sem assinatura criptográfica. A integridade de cada arquivo final poderá ser conferida pelo respectivo resumo SHA-256, publicado na página de verificação :url com o código :code.',
        ],
    ],
    'item5' => [
        'sign' => '5. Estou ciente de que a validade e os efeitos deste aceite dependem da legislação aplicável e do acordo entre as partes, e que a AssinaVelox não garante sua aceitação por terceiros.',
        'witness' => '5. Estou ciente de que a validade e os efeitos desta declaração de testemunha dependem da legislação aplicável e do acordo entre as partes, e que a AssinaVelox não garante sua aceitação por terceiros.',
        'approve' => '5. Estou ciente de que a validade e os efeitos desta aprovação dependem da legislação aplicável e do acordo entre as partes, e que a AssinaVelox não garante sua aceitação por terceiros.',
    ],
    'item6' => [
        'sign' => '6. Li o aviso de privacidade exibido nesta página e sei que posso recusar a assinatura informando um motivo.',
        'witness' => '6. Li o aviso de privacidade exibido nesta página e sei que posso recusar a assinatura como testemunha informando um motivo.',
        'approve' => '6. Li o aviso de privacidade exibido nesta página e sei que posso recusar a aprovação informando um motivo.',
    ],

    // Rótulo da caixa de aceite.
    'label' => [
        'sign' => [
            'single' => 'Li o documento :title e declaro que concordo com seu conteúdo e que os dados aqui registrados — data e hora, endereço IP, navegador, :code, a versão exata do documento e os campos que preenchi — constituem evidência do meu aceite eletrônico.',
            'multi' => 'Li os :count documentos de :title e declaro que concordo com o conteúdo de cada um e que os dados aqui registrados — data e hora, endereço IP, navegador, :code, a versão exata de cada documento e os campos que preenchi — constituem evidência do meu aceite eletrônico.',
        ],
        'witness' => [
            'single' => 'Tive acesso a o documento :title e declaro, na qualidade de testemunha, que os dados aqui registrados — data e hora, endereço IP, navegador, :code, a versão exata do documento e os campos que preenchi — constituem evidência do meu aceite eletrônico como testemunha.',
            'multi' => 'Tive acesso a os :count documentos de :title e declaro, na qualidade de testemunha, que os dados aqui registrados — data e hora, endereço IP, navegador, :code, a versão exata de cada documento e os campos que preenchi — constituem evidência do meu aceite eletrônico como testemunha.',
        ],
        'approve' => [
            'single' => 'Li o documento :title e declaro que aprovo seu conteúdo e que os dados aqui registrados — data e hora, endereço IP, navegador, :code e a versão exata do documento — constituem evidência da minha aprovação eletrônica.',
            'multi' => 'Li os :count documentos de :title e declaro que aprovo seu conteúdo e que os dados aqui registrados — data e hora, endereço IP, navegador, :code e a versão exata de cada documento — constituem evidência da minha aprovação eletrônica.',
        ],
    ],
    'code' => [
        'email' => 'código confirmado por e-mail',
        'sms' => 'código confirmado por SMS',
        'whatsapp' => 'código confirmado por WhatsApp',
        'pin_suffix' => ' e PIN combinado com a remetente',
    ],

    // Aviso de privacidade.
    'privacy_summary' => 'Este documento foi enviado por :organization. :purpose, a AssinaVelox gravará data, IP, navegador, a versão exata do documento e o :code.',
    'privacy_purpose' => [
        'signer' => 'Para registrar seu aceite',
        'approver' => 'Para registrar sua aprovação',
        'viewer' => 'Para registrar sua visualização',
    ],
    'privacy_notice' => [
        'signer' => [
            'Como seus dados são usados nesta página',
            'Quem é responsável pelos seus dados. Este documento foi enviado por :organization, que decidiu solicitar a sua assinatura e é a controladora dos seus dados pessoais. A AssinaVelox (:operator) é a operadora: trata os dados apenas para executar a assinatura, seguindo as instruções da remetente.',
            'O que registramos e por quê. Para que o seu aceite tenha valor como evidência, gravamos: :registered; a data e a hora do servidor (UTC); seu endereço IP e a identificação do navegador; a versão exata do documento que você viu (resumo SHA-256); os campos exibidos e os valores que você preencher; a imagem da sua assinatura (desenhada, digitada ou enviada); e o texto de aceite que você marcar. Esses dados compõem a página de evidências anexada ao documento final, entregue à remetente e a você.',
            'A abertura deste link é registrada. Ao abrir esta página, registramos data, IP e navegador como "abertura detectada". Isso não significa que você leu ou concordou com algo.',
            'Se você não quiser assinar. Você pode simplesmente fechar esta página, ou usar "Recusar assinatura" e informar o motivo, que será enviado à remetente. Não solicitar o código não gera nenhum aceite.',
            'O que não fazemos. Não pedimos :not_asked. Não usamos cookies de rastreamento nem anúncios nesta página; apenas cookies essenciais de sessão e segurança.',
            'Por quanto tempo. As evidências são guardadas enquanto o documento existir na conta da remetente ou enquanto houver obrigação legal ou necessidade de comprovar o aceite.',
            'Seus direitos. Para acessar, corrigir ou pedir informações sobre seus dados, contate primeiro a remetente. Você também pode escrever à AssinaVelox em :support.',
        ],
        'approver' => [
            'Como seus dados são usados nesta página',
            'Quem é responsável pelos seus dados. Este documento foi enviado por :organization, que decidiu solicitar a sua aprovação e é a controladora dos seus dados pessoais. A AssinaVelox (:operator) é a operadora: trata os dados apenas para registrar a aprovação, seguindo as instruções da remetente.',
            'O que registramos e por quê. Para que a sua aprovação tenha valor como evidência, gravamos: :registered; a data e a hora do servidor (UTC); seu endereço IP e a identificação do navegador; a versão exata do documento que você viu (resumo SHA-256); os campos exibidos e os valores que você preencher; e o texto de aprovação que você marcar. A aprovação não usa imagem de assinatura. Esses dados compõem a página de evidências anexada ao documento final, entregue à remetente e a você.',
            'A abertura deste link é registrada. Ao abrir esta página, registramos data, IP e navegador como "abertura detectada". Isso não significa que você leu ou concordou com algo.',
            'Se você não quiser aprovar. Você pode simplesmente fechar esta página, ou recusar e informar o motivo, que será enviado à remetente. Não solicitar o código não gera nenhuma aprovação.',
            'O que não fazemos. Não pedimos :not_asked. Não usamos cookies de rastreamento nem anúncios nesta página; apenas cookies essenciais de sessão e segurança.',
            'Por quanto tempo. As evidências são guardadas enquanto o documento existir na conta da remetente ou enquanto houver obrigação legal ou necessidade de comprovar a aprovação.',
            'Seus direitos. Para acessar, corrigir ou pedir informações sobre seus dados, contate primeiro a remetente. Você também pode escrever à AssinaVelox em :support.',
        ],
        'viewer' => [
            'Como seus dados são usados nesta página',
            'Quem é responsável pelos seus dados. Este documento foi enviado por :organization, que decidiu compartilhá-lo com você para acompanhamento e é a controladora dos seus dados pessoais. A AssinaVelox (:operator) é a operadora: trata os dados apenas para disponibilizar o documento, seguindo as instruções da remetente.',
            'O que registramos e por quê. Para comprovar o acesso ao documento, gravamos: :registered; a data e a hora do servidor (UTC); seu endereço IP e a identificação do navegador; e a versão exata do documento que você viu (resumo SHA-256). Como visualizador, você não registra aceite, não preenche campos e nenhuma imagem de assinatura é gravada.',
            'A abertura deste link é registrada. Ao abrir esta página, registramos data, IP e navegador como "abertura detectada". Isso não significa que você leu ou concordou com algo.',
            'Você não precisa fazer nada. Pode simplesmente fechar esta página. Não solicitar o código não gera nenhum registro além da abertura.',
            'O que não fazemos. Não pedimos :not_asked. Não usamos cookies de rastreamento nem anúncios nesta página; apenas cookies essenciais de sessão e segurança.',
            'Por quanto tempo. Os registros são guardados enquanto o documento existir na conta da remetente ou enquanto houver obrigação legal ou necessidade de comprovar o acesso.',
            'Seus direitos. Para acessar, corrigir ou pedir informações sobre seus dados, contate primeiro a remetente. Você também pode escrever à AssinaVelox em :support.',
        ],
    ],
    'registered' => [
        'base' => ':who (informados pela remetente); o código de confirmação enviado :where (guardado apenas de forma irreversível)',
        'who' => [
            'email' => 'seu nome e e-mail',
            'phone' => 'seu nome, e-mail e celular',
        ],
        'where' => [
            'email' => 'ao seu e-mail',
            'sms' => 'por SMS ao seu celular',
            'whatsapp' => 'por WhatsApp ao seu celular',
        ],
        'pin' => '; a confirmação do PIN que a remetente combinou com você (o PIN também é guardado só de forma irreversível)',
        'cpf_lookup' => '; o CPF que você digitar no documento, que não é conferido apenas pelos dígitos: ele também é enviado a um serviço de consulta cadastral usado pela AssinaVelox, só para conferir a situação do número na base desse serviço, e o resultado da consulta fica registrado com o aceite (a consulta não confirma que você é o titular do CPF)',
        'cpf' => '; o CPF que você digitar no documento, conferido apenas pelos dígitos (isso não confirma a titularidade)',
        'photos' => '; as fotos que você enviar (:photos), guardadas como registro do aceite, sem comparação de rostos, sem análise da imagem e sem leitura do documento, e :retention',
        // Fase 4 §4.1: compartilhamento com o provedor nomeado e a finalidade (LGPD art. 9º e 11).
        'photos_verification' => '; as fotos que você enviar (:photos), encaminhadas ao provedor externo :provider só para comparar a foto tirada na hora com a foto do documento (a plataforma não compara as imagens: ela envia as fotos e registra a resposta do provedor, que fica guardada como evidência do aceite), e :retention',
        'retention_days' => 'apagadas :days dias depois',
        'retention_kept' => 'guardadas enquanto o documento existir na conta da remetente',
    ],
    'not_asked' => [
        'password' => 'senha',
        'cpf' => 'CPF',
        'photo' => 'foto',
        'location' => 'localização',
        'or' => ' ou ',
    ],
    'photo_kinds' => [
        'selfie' => 'foto do rosto',
        'document_front' => 'foto do documento (frente)',
        'document_back' => 'foto do documento (verso)',
    ],

    // O que a plataforma afirma sobre a conclusão.
    'completion' => [
        'certificate' => 'Ao final, a AssinaVelox aplicará ao arquivo uma assinatura criptográfica com certificado de sua própria titularidade. Ela identifica a operadora e detecta alterações posteriores; não é a sua assinatura pessoal.',
        'no_certificate' => 'Ao final, este documento será concluído como aceite eletrônico com evidências, sem assinatura criptográfica. A integridade do arquivo é conferida pelo resumo SHA-256 publicado na página de verificação.',
        'participant_certificate' => 'Ao final, a AssinaVelox aplicará ao arquivo uma assinatura criptográfica com certificado de sua própria titularidade (identifica a operadora, não é a sua assinatura pessoal). ',
        'participant_no_certificate' => 'Ao final, este documento será concluído como aceite eletrônico com evidências. ',
        'participant_suffix' => 'Se você enviar o seu certificado digital no prazo, o arquivo também receberá uma assinatura criptográfica feita com o seu próprio certificado, que se soma ao seu aceite. A integridade do arquivo é conferida pelo resumo SHA-256 publicado na página de verificação.',
    ],
];
