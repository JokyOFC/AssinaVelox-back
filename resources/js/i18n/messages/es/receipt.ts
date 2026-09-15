import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/receipt';

export default {
    'receipt.closed.expired':
        'El plazo de firma terminó antes de todas las aceptaciones, así que la recolección se cerró y no habrá archivo final. Su aceptación sigue registrada y este comprobante sigue siendo válido como prueba de lo que usted hizo.',
    'receipt.closed.canceled':
        'Quien envió el documento canceló esta solicitud, así que la recolección se cerró y no habrá archivo final. Su aceptación sigue registrada y este comprobante sigue siendo válido como prueba de lo que usted hizo.',
    'receipt.closed.refused':
        'Un participante rechazó, así que la recolección se cerró y no habrá archivo final. Su aceptación sigue registrada y este comprobante sigue siendo válido como prueba de lo que usted hizo.',
    'receipt.closing.completed': 'El archivo final ya está disponible.',
    'receipt.closing.last_signer':
        'Usted fue el último en firmar: todas las aceptaciones exigidas quedaron registradas y el archivo final se está preparando. En cuanto esté listo, aparece aquí y se envía por correo a quienes participaron.',
    'receipt.closing.waiting':
        'Recibirá el archivo final cuando todos los participantes concluyan.',
    'receipt.title.completed': 'Documento concluido',
    'receipt.title.closed': 'Recolección cerrada',
    'receipt.title.last_signer': 'Aceptaciones concluidas',
    'receipt.title.approved': 'Usted ya aprobó',
    'receipt.title.signed': 'Usted ya firmó',
    'receipt.recorded_approval': 'Aprobación registrada.',
    'receipt.recorded_acceptance': 'Aceptación registrada.',
    'receipt.statement':
        'Su manifestación quedó registrada el {local} ({utc} UTC), autenticada por un código enviado al correo {email}. Código de verificación: {code}. {closing}',
    'receipt.waiting_participants.one': 'Esperando a {count} participante.',
    'receipt.waiting_participants.other': 'Esperando a {count} participantes.',
    'receipt.waiting_signers.one': 'Esperando a {count} firmante.',
    'receipt.waiting_signers.other': 'Esperando a {count} firmantes.',
    'receipt.dt.receipt': 'Comprobante',
    'receipt.dt.datetime': 'Fecha y hora',
    'receipt.dt.auth': 'Autenticación',
    'receipt.dt.code': 'Código',
    'receipt.dt.ip': 'IP registrada',
    'receipt.dt.terms': 'Texto aceptado',
    'receipt.dt.files': 'Archivos',
    'receipt.dt.document_sha': 'SHA-256 del documento',
    'receipt.dt.final_sha': 'SHA-256 final',
    'receipt.action_fallback': 'Aceptación electrónica',
    'receipt.window_closed':
        'La ventana de descarga de esta sesión terminó. Por seguridad, el comprobante y el archivo final solo se entregan a quien acaba de confirmar el código enviado por correo — tener el enlace de la invitación no basta. Cuando el documento se concluya, recibirá por correo un enlace propio para descargar el archivo.',
    'receipt.verify_note':
        'Este comprobante puede verificarse en cualquier momento en la página pública de verificación, sin iniciar sesión y sin exponer datos personales: {link}',
    'receipt.verify_link': 'verificar con el código {code}',
    'receipt.download_file': 'Descargar {position}. {name}',
    'receipt.file_fallback_lower': 'archivo {position}',
    'receipt.download_copy': 'Descargar copia',
    'receipt.no_final': 'Sin archivo final',
    'receipt.available_when_signed': 'Disponible cuando todos firmen',
    'receipt.evidence_report': 'Informe de evidencias',
} satisfies NamespaceOf<typeof reference>;
