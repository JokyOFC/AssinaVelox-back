/** PT-BR (referência) — etapa "Confirmar identidade": código e PIN. */
export default {
    'otp.simulated': 'simulado',
    'otp.simulated_title':
        'Ambiente de testes: a mensagem não chega ao celular.',
    'otp.by_channel': ' por {channel}',
    'otp.unavailable_title': 'Não é possível enviar o código{by} agora',
    'otp.unavailable_contact':
        'Fale com {sender} ({organization}) para receber o documento por outro canal.',
    'otp.will_send':
        'Enviaremos um código de {length} dígitos{by} para {destination}.',
    'otp.receive': 'Receber código por {channel}',
    'otp.sent': 'Enviamos um código{by} para {destination}',
    'otp.input_aria': 'Código de {length} dígitos recebido por {channel}',
    'otp.valid_for': 'Código válido por {minutes} min',
    'otp.resend_in': 'Reenviar em {seconds}s',
    'otp.resend': 'Reenviar código',
    'otp.attempts_exhausted': 'Tentativas esgotadas. Peça um novo código.',
    'otp.attempts_left.one':
        '{count} tentativa restante antes de precisar de um novo código.',
    'otp.attempts_left.other':
        '{count} tentativas restantes antes de precisar de um novo código.',
    'otp.confirm': 'Confirmar código e continuar',
    'otp.hello': 'Olá, {name}',
    'otp.heading_default': 'Confirme sua identidade para assinar',
    'otp.intro': '{sender} ({organization}) enviou este documento para você.',
    'otp.intro_sent':
        '{sender} ({organization}) enviou este documento para você em {date}.',
    'otp.deadline': 'Prazo: {deadline}.',
    'otp.pin_blocked_title': 'PIN bloqueado',
    'otp.pin_blocked_body':
        'O PIN foi bloqueado depois de várias tentativas incorretas. Fale com {sender} ({organization}): só quem enviou o documento pode definir um PIN novo.',
    'otp.pin_locked_title': 'PIN bloqueado temporariamente',
    'otp.pin_locked_body':
        'Depois de várias tentativas incorretas, o PIN fica bloqueado até {time} (faltam {remaining}). Em seguida, peça um novo código e informe o PIN de novo.',
    'otp.pin_after_code':
        'Depois do código, você vai informar o PIN que {sender} combinou com você.',
    'otp.agree':
        'Ao continuar você concorda com os {terms}. Seus dados são tratados conforme a LGPD.',
    'otp.terms_link': 'termos de uso',
    'pin.intro':
        'Código confirmado. Agora informe o {pin} que {sender} combinou com você. O AssinaVelox não envia esse PIN.',
    'pin.label': 'PIN ({min} a {max} dígitos)',
    'pin.attempts_left.one':
        '{count} tentativa restante antes de o PIN ser bloqueado por um tempo.',
    'pin.attempts_left.other':
        '{count} tentativas restantes antes de o PIN ser bloqueado por um tempo.',
    'pin.confirm': 'Confirmar PIN e continuar',
    'pin.help':
        'Não recebeu um PIN? Fale com {sender}: só quem enviou o documento pode informá-lo.',
} as const;
