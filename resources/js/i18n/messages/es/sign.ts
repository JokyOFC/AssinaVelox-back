import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/sign';

export default {
    'sign.steps.approve': 'Aprobar',
    'sign.steps.view': 'Seguir',
    'sign.file_fallback': 'Archivo {position}',
    'sign.file_label': '{position}. {name}',
    'sign.other.signs_after_you': 'firma después de usted',
    'sign.other.not_signed_yet': 'todavía no firmó',
    'sign.error.signature_too_large':
        'La imagen de la firma quedó demasiado grande. Limpie el recuadro y haga un trazo más simple, o envíe una imagen más pequeña.',
    'sign.invalid.title': 'Enlace inválido',
    'sign.invalid.body':
        'Este enlace no existe o fue reemplazado. Revise el correo más reciente.',
    'sign.expired.title': 'Plazo terminado',
    'sign.expired.body':
        'El plazo para firmar este documento terminó el {date}. Póngase en contacto con {sender} ({organization}).',
    'sign.canceled.title': 'Documento cancelado',
    'sign.canceled.body': '{organization} canceló esta solicitud de firma.',
    'sign.refused.title_approve': 'Aprobación rechazada',
    'sign.refused.title_sign': 'Firma rechazada',
    'sign.refused.at_approve':
        'Usted rechazó aprobar este documento el {date}.',
    'sign.refused.at_sign': 'Usted rechazó firmar este documento el {date}.',
    'sign.refused.reason': 'Motivo informado: “{reason}”',
    'sign.refused.approve': 'Usted rechazó aprobar este documento.',
    'sign.refused.sign': 'Usted rechazó firmar este documento.',
    'sign.receipt_fallback': 'Su aceptación quedó registrada.',
    'sign.tab.receipt': 'Comprobante · {title}',
    'sign.tab.document': 'Documento · {title}',
    'sign.tab.approve': 'Aprobar · {title}',
    'sign.tab.witness': 'Firmar como testigo · {title}',
    'sign.tab.sign': 'Firmar · {title}',
    'sign.heading.certificate':
        'Confirme su identidad para enviar el certificado',
    'sign.heading.approve': 'Confirme su identidad para aprobar',
    'sign.heading.view': 'Confirme su identidad para ver el documento',
    'sign.files.view_label': 'Archivos de este documento',
    'sign.files.sign_label': 'Revise todos los archivos',
    'sign.files.not_opened': 'Todavía no abierto',
    'sign.files.pending.one': '{count} campo pendiente',
    'sign.files.pending.other': '{count} campos pendientes',
    'sign.files.done': 'Abierto · sin pendientes',
    'sign.code_confirmed': 'Código confirmado',
    'sign.viewer.title': 'Copia para seguimiento',
    'sign.viewer.notice_fallback':
        'Usted recibió este documento para seguimiento. No es necesario firmar ni aprobar.',
    'sign.viewer.readonly':
        'Como observador, usted no registra aceptación ni rechazo. Esta pantalla es de solo lectura y no cambia el avance del documento.',
    'sign.viewer.final_completed': 'Copia final — concluido el {date}',
    'sign.viewer.final': 'Copia final',
    'sign.viewer.download_file': 'Descargar {file}',
    'sign.viewer.download_final': 'Descargar copia final',
    'sign.viewer.available_when_done': 'Disponible cuando todos concluyan',
    'sign.button_fallback': 'Firmar documento',
    'sign.panel.title_approve': 'Su aprobación',
    'sign.panel.title_witness': 'Su firma como testigo',
    'sign.panel.title_sign': 'Su firma',
    'sign.panel.approve_intro':
        'Usted aprueba el contenido {what}. No hay representación visual de firma: lo que registra su {bold} es la aceptación de abajo.',
    'sign.panel.approve_files': 'de los archivos',
    'sign.panel.approve_document': 'del documento',
    'sign.panel.approve_bold': 'aprobación electrónica',
    'sign.panel.witness_intro':
        'Usted participa como {bold}. La imagen es la representación visual de su firma; lo que registra su manifestación es la aceptación de abajo, con la declaración propia de testigo.',
    'sign.panel.witness_bold': 'testigo',
    'sign.panel.sign_intro':
        'Elija cómo quiere firmar. La imagen es la {bold} de su firma; lo que registra su voluntad es la aceptación de abajo.',
    'sign.panel.sign_bold': 'representación visual',
    'sign.panel.initials': 'Su rúbrica',
    'sign.warn.open_all.one':
        'Abra y revise todos los archivos antes de {verb}. Falta: {files}.',
    'sign.warn.open_all.other':
        'Abra y revise todos los archivos antes de {verb}. Faltan: {files}.',
    'sign.warn.file_not_arrived':
        ' El archivo abierto no llegó — use “Intentar de nuevo” o “Descargar PDF” en la barra del documento.',
    'sign.warn.document_not_arrived':
        'El documento no llegó. Use “Intentar de nuevo” o “Descargar PDF” en la barra del documento — solo es posible firmar después de revisar lo que se firma.',
    'sign.warn.document_loading':
        'Espere a que el documento termine de cargar para firmar.',
    'sign.warn.pending.one':
        'Falta {count} campo obligatorio — use “Siguiente campo” en la barra del documento.',
    'sign.warn.pending.other':
        'Faltan {count} campos obligatorios — use “Siguiente campo” en la barra del documento.',
    'sign.warn.cpf': 'Revise el CPF informado: los dígitos no coinciden.',
    'sign.warn.video': 'Antes de {verb}, envíe el video corto.',
    'sign.warn.capture': 'Antes de {verb}, envíe: {items}.',
    'sign.refuse.approve': 'Rechazar aprobación',
    'sign.refuse.sign': 'Rechazar firma',
    'sign.locked.email':
        'El documento se muestra después de que usted confirme el código enviado a su correo electrónico.',
    'sign.locked.phone':
        'El documento se muestra después de que usted confirme el código enviado por {channel} a su móvil.',
    'sign.locked.never_public':
        'Nunca está disponible en una dirección pública.',
    'sign.participants.title': 'Participantes',
    'sign.participants.you': 'usted',
    'sign.participants.approved': 'Aprobó',
    'sign.participants.signed': 'Firmó',
    'sign.participants.refused': 'Rechazó',
    'sign.participants.after_you': 'después de usted',
    'sign.participants.signs_after_you': 'firma después de usted',
    'sign.participants.pending': 'pendiente',
} satisfies NamespaceOf<typeof reference>;
