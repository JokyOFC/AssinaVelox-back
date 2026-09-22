/**
 * PT-BR (referência) — verificação facial com documento por provedor externo (Fase 4 §4.1).
 * Só os textos PRÓPRIOS da tela: o aviso, a autorização, o estado e a explicação do resultado
 * chegam prontos do servidor (já no idioma da página) e nomeiam o provedor — a tela repete o
 * que o provedor informou, nunca afirma por conta própria.
 */
export default {
    'verification.title': 'Verificação facial com documento',
    'verification.confirm_code_first':
        'Confirme o código antes de enviar as fotos para a verificação.',
    'verification.take_photos_first':
        'Tire as três fotos acima — rosto, frente e verso do documento — antes de enviar.',
    'verification.document_type': 'Tipo do documento',
    'verification.submit': 'Enviar para verificação',
    'verification.resubmit': 'Enviar as fotos de novo',
    'verification.sent':
        'Fotos enviadas ao provedor. O resultado aparece aqui.',
    'verification.waiting': 'Aguardando o resultado informado pelo provedor…',
    'verification.attempts': 'Tentativas: {used} de {max}',
    'verification.no_attempts_left':
        'As tentativas acabaram. Fale com quem enviou o documento: só ele pode liberar um novo envio.',
    'verification.captures_changed':
        'Alguma foto foi refeita depois do resultado. Envie as fotos de novo para o provedor comparar as imagens atuais.',
    'verification.warn':
        'Antes de {verb}, envie as fotos para a verificação facial com documento e aguarde o resultado informado pelo provedor.',
    'verification.consent_courtesy':
        'Tradução de cortesia: a autorização registrada é a do texto de referência, em português.',
    'verification.preview':
        'Depois do código, as três fotos (rosto, frente e verso do documento) são enviadas ao provedor {provider}, que compara a foto tirada na hora com a foto do documento. O aceite só é liberado com o resultado “aprovado” informado pelo provedor.',
    'verification.error.unknown_delivery':
        'Não recebemos a confirmação do envio. Verifique a conexão: se as fotos tiverem chegado, o estado é atualizado aqui em instantes.',
    'verification.error.not_required':
        'A verificação não é mais pedida para você. Recarregue a página.',
    'verification.error.generic':
        'Não foi possível enviar as fotos para a verificação. Tente de novo.',
    'verification.error.poll':
        'Não foi possível consultar o resultado agora. Verifique a conexão: a consulta continua sozinha.',
} as const;
