import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/pdf';

export default {
    'pdf.render_failed': 'No fue posible dibujar esta página.',
    'pdf.loading': 'Cargando el documento…',
    'pdf.retry': 'Intentar de nuevo',
    'pdf.previous': 'Página anterior',
    'pdf.next': 'Página siguiente',
    'pdf.page_of': 'Página {page} de {total}',
    'pdf.converting': 'Convirtiendo el archivo a PDF',
    'pdf.converting_hint':
        'La vista previa aparece en cuanto termine la conversión.',
    'pdf.blocked_title': 'Archivo bloqueado para preparación',
    'pdf.failed_title': 'Error al procesar el archivo',
    'pdf.blocked_body':
        'Los PDF protegidos con contraseña o que ya contienen firma digital no pueden recibir campos. Envíe una versión sin protección.',
    'pdf.failed_body':
        'No pudimos preparar este archivo. Quítelo y envíe un PDF válido.',
    'pdf.zoom_out': 'Reducir zoom',
    'pdf.zoom_fit': 'Ajustar al ancho',
    'pdf.zoom_in': 'Aumentar zoom',
    'pdf.error.forbidden': 'Usted no tiene permiso para ver este documento.',
    'pdf.error.not_found': 'El archivo ya no está disponible.',
    'pdf.error.conflict': 'El documento todavía se está procesando.',
    'pdf.error.network':
        'No fue posible cargar el documento. Inténtelo de nuevo.',
    'pdf.error.password':
        'Este PDF está protegido con contraseña y no puede prepararse para firma.',
    'pdf.error.invalid':
        'No fue posible leer este PDF. El archivo puede estar dañado.',
    'pdf.error.unknown':
        'No fue posible mostrar el documento. Descargue el archivo para revisarlo.',
} satisfies NamespaceOf<typeof reference>;
