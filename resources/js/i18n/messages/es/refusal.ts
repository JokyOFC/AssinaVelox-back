import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/refusal';

export default {
    'refusal.title_signature': '¿Rechazar la firma?',
    'refusal.title_approval': '¿Rechazar la aprobación?',
    'refusal.description':
        '{organization} recibirá el motivo informado. El rechazo cierra este documento y usted no puede deshacerlo.',
    'refusal.reason_label': 'Motivo del rechazo',
    'refusal.placeholder':
        'Ej.: el valor del alquiler es diferente del acordado.',
    'refusal.min': 'Mínimo de {min} caracteres.',
    'refusal.submit_signature': 'Rechazar firma',
    'refusal.submit_approval': 'Rechazar aprobación',
    'delegation.done_title': 'Delegación registrada',
    'delegation.done_close': 'Puede cerrar esta página.',
    'delegation.error_network':
        'No fue posible enviar ahora. Revise la conexión e inténtelo de nuevo.',
    'delegation.error_generic': 'No fue posible registrar la delegación.',
    'delegation.title': 'Delegación',
    'delegation.received_from':
        'Usted recibió este documento por delegación de {name}. La aceptación que usted registre es suya, con su código y sus evidencias.',
    'delegation.pending':
        'Usted pidió delegar en {name} ({email}) el {date}. Quien envió el documento todavía va a decidir; hasta entonces, la participación sigue siendo suya.',
    'delegation.rejected':
        'Quien envió el documento rechazó la solicitud de delegación{note}. La participación sigue siendo suya.',
    'delegation.rejected_note': ': “{note}”',
    'delegation.prompt':
        '¿No es usted quien debe responder? Indique quién participará en su lugar.',
    'delegation.open': 'Delegar en otra persona',
    'delegation.dialog_title_signature': '¿Delegar la firma?',
    'delegation.dialog_title_approval': '¿Delegar la aprobación?',
    'delegation.dialog_intro':
        'La persona indicada recibe una invitación propia, confirma el código enviado a su correo y registra su propia aceptación.',
    'delegation.dialog_requires_confirmation':
        'Quien envió el documento necesita confirmar antes; hasta entonces, la participación sigue siendo suya.',
    'delegation.dialog_immediate':
        'En cuanto usted confirme, su enlace deja de ser válido.',
    'delegation.name': 'Nombre completo',
    'delegation.email': 'Correo electrónico',
    'delegation.reason': 'Motivo',
    'delegation.reason_placeholder':
        'Ej.: la persona indicada es quien se ocupa de este contrato en la empresa.',
    'delegation.dialog_video':
        'La persona indicada también tendrá que grabar un video corto de su rostro antes de aceptar.',
    'delegation.reason_hint':
        'Mínimo de {min} caracteres. Se envía a quien envió el documento y a la página de evidencias.',
    'delegation.submit': 'Delegar',
} satisfies NamespaceOf<typeof reference>;
