/** PT-BR (referência) — comprovante do aceite. */
export default {
    'receipt.closed.expired':
        'O prazo para assinatura terminou antes de todos os aceites, então a coleta foi encerrada e não haverá arquivo final. Seu aceite continua registrado e este comprovante segue válido como prova do que você fez.',
    'receipt.closed.canceled':
        'O remetente cancelou esta solicitação, então a coleta foi encerrada e não haverá arquivo final. Seu aceite continua registrado e este comprovante segue válido como prova do que você fez.',
    'receipt.closed.refused':
        'Um participante recusou, então a coleta foi encerrada e não haverá arquivo final. Seu aceite continua registrado e este comprovante segue válido como prova do que você fez.',
    'receipt.closing.completed': 'O arquivo final já está disponível.',
    'receipt.closing.last_signer':
        'Você foi o último a assinar: todos os aceites exigidos foram registrados e o arquivo final está sendo preparado. Assim que ficar pronto, ele aparece aqui e é enviado por e-mail a quem participou.',
    'receipt.closing.waiting':
        'Você receberá o arquivo final quando todos os participantes concluírem.',
    'receipt.title.completed': 'Documento concluído',
    'receipt.title.closed': 'Coleta encerrada',
    'receipt.title.last_signer': 'Aceites concluídos',
    'receipt.title.approved': 'Você já aprovou',
    'receipt.title.signed': 'Você já assinou',
    'receipt.recorded_approval': 'Aprovação registrada.',
    'receipt.recorded_acceptance': 'Aceite registrado.',
    'receipt.statement':
        'Sua manifestação foi gravada em {local} ({utc} UTC), autenticada por código enviado ao e-mail {email}. Código de verificação: {code}. {closing}',
    'receipt.waiting_participants.one': 'Aguardando {count} participante.',
    'receipt.waiting_participants.other': 'Aguardando {count} participantes.',
    'receipt.waiting_signers.one': 'Aguardando {count} signatário.',
    'receipt.waiting_signers.other': 'Aguardando {count} signatários.',
    'receipt.dt.receipt': 'Comprovante',
    'receipt.dt.datetime': 'Data e hora',
    'receipt.dt.auth': 'Autenticação',
    'receipt.dt.code': 'Código',
    'receipt.dt.ip': 'IP registrado',
    'receipt.dt.terms': 'Texto aceito',
    'receipt.dt.files': 'Arquivos',
    'receipt.dt.document_sha': 'SHA-256 do documento',
    'receipt.dt.final_sha': 'SHA-256 final',
    'receipt.action_fallback': 'Aceite eletrônico',
    'receipt.window_closed':
        'A janela de download desta sessão terminou. Por segurança, o comprovante e o arquivo final só são entregues a quem acabou de confirmar o código enviado por e-mail — a posse do link do convite não basta. Quando o documento for concluído, você receberá por e-mail um link próprio para baixar o arquivo.',
    'receipt.verify_note':
        'Este comprovante pode ser conferido a qualquer momento na página pública de verificação, sem login e sem expor dados pessoais: {link}',
    'receipt.verify_link': 'verificar com o código {code}',
    'receipt.download_file': 'Baixar {position}. {name}',
    'receipt.file_fallback_lower': 'arquivo {position}',
    'receipt.download_copy': 'Baixar cópia',
    'receipt.no_final': 'Sem arquivo final',
    'receipt.available_when_signed': 'Disponível quando todos assinarem',
    'receipt.evidence_report': 'Relatório de evidências',
} as const;
