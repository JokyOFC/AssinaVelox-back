import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/otp';

export default {
    'otp.simulated': 'simulado',
    'otp.simulated_title': 'Entorno de pruebas: el mensaje no llega al móvil.',
    'otp.by_channel': ' por {channel}',
    'otp.unavailable_title': 'No es posible enviar el código{by} ahora',
    'otp.unavailable_contact':
        'Hable con {sender} ({organization}) para recibir el documento por otro canal.',
    'otp.will_send':
        'Enviaremos un código de {length} dígitos{by} a {destination}.',
    'otp.receive': 'Recibir código por {channel}',
    'otp.sent': 'Enviamos un código{by} a {destination}',
    'otp.input_aria': 'Código de {length} dígitos recibido por {channel}',
    'otp.valid_for': 'Código válido por {minutes} min',
    'otp.resend_in': 'Reenviar en {seconds}s',
    'otp.resend': 'Reenviar código',
    'otp.attempts_exhausted': 'Intentos agotados. Pida un código nuevo.',
    'otp.attempts_left.one':
        'Queda {count} intento antes de necesitar un código nuevo.',
    'otp.attempts_left.other':
        'Quedan {count} intentos antes de necesitar un código nuevo.',
    'otp.confirm': 'Confirmar código y continuar',
    'otp.hello': 'Hola, {name}',
    'otp.heading_default': 'Confirme su identidad para firmar',
    'otp.intro': '{sender} ({organization}) le envió este documento.',
    'otp.intro_sent':
        '{sender} ({organization}) le envió este documento el {date}.',
    'otp.deadline': 'Plazo: {deadline}.',
    'otp.pin_blocked_title': 'PIN bloqueado',
    'otp.pin_blocked_body':
        'El PIN se bloqueó después de varios intentos incorrectos. Hable con {sender} ({organization}): solo quien envió el documento puede definir un PIN nuevo.',
    'otp.pin_locked_title': 'PIN bloqueado temporalmente',
    'otp.pin_locked_body':
        'Después de varios intentos incorrectos, el PIN queda bloqueado hasta las {time} (faltan {remaining}). Luego pida un código nuevo y escriba el PIN de nuevo.',
    'otp.pin_after_code':
        'Después del código, escribirá el PIN que {sender} acordó con usted.',
    'otp.agree':
        'Al continuar, usted acepta los {terms}. Sus datos se tratan conforme a la ley brasileña de protección de datos (LGPD).',
    'otp.terms_link': 'términos de uso (en portugués)',
    'pin.intro':
        'Código confirmado. Ahora escriba el {pin} que {sender} acordó con usted. AssinaVelox no envía ese PIN.',
    'pin.label': 'PIN (de {min} a {max} dígitos)',
    'pin.attempts_left.one':
        'Queda {count} intento antes de que el PIN se bloquee por un tiempo.',
    'pin.attempts_left.other':
        'Quedan {count} intentos antes de que el PIN se bloquee por un tiempo.',
    'pin.confirm': 'Confirmar PIN y continuar',
    'pin.help':
        '¿No recibió un PIN? Hable con {sender}: solo quien envió el documento puede informarlo.',
} satisfies NamespaceOf<typeof reference>;
