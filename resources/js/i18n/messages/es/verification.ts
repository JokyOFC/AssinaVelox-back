import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/verification';

export default {
    'verification.title': 'Verificación facial con documento',
    'verification.confirm_code_first':
        'Confirme el código antes de enviar las fotos para la verificación.',
    'verification.take_photos_first':
        'Tome las tres fotos de arriba — rostro, frente y dorso del documento — antes de enviar.',
    'verification.document_type': 'Tipo de documento',
    'verification.submit': 'Enviar para verificación',
    'verification.resubmit': 'Enviar las fotos de nuevo',
    'verification.sent':
        'Fotos enviadas al proveedor. El resultado aparece aquí.',
    'verification.waiting':
        'Esperando el resultado informado por el proveedor…',
    'verification.attempts': 'Intentos: {used} de {max}',
    'verification.no_attempts_left':
        'Se acabaron los intentos. Hable con quien envió el documento: solo esa persona puede habilitar un nuevo envío.',
    'verification.captures_changed':
        'Alguna foto se rehízo después del resultado. Envíe las fotos de nuevo para que el proveedor compare las imágenes actuales.',
    'verification.warn':
        'Antes de {verb}, envíe las fotos para la verificación facial con documento y espere el resultado informado por el proveedor.',
    'verification.consent_courtesy':
        'Traducción de cortesía: la autorización registrada es la del texto de referencia, en portugués.',
    'verification.preview':
        'Después del código, las tres fotos (rostro, frente y dorso del documento) se envían al proveedor {provider}, que compara la foto tomada en el momento con la foto del documento. La aceptación solo se habilita con el resultado “aprobado” informado por el proveedor.',
    'verification.error.unknown_delivery':
        'No recibimos la confirmación del envío. Revise la conexión: si las fotos llegaron, el estado se actualiza aquí en un momento.',
    'verification.error.not_required':
        'La verificación ya no se le pide. Recargue la página.',
    'verification.error.generic':
        'No fue posible enviar las fotos para la verificación. Inténtelo de nuevo.',
    'verification.error.poll':
        'No fue posible consultar el resultado ahora. Revise la conexión: la consulta continúa sola.',
} satisfies NamespaceOf<typeof reference>;
