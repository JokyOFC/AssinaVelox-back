import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/signature';

export default {
    'signature.signature.type_label': 'Escriba su nombre',
    'signature.signature.upload_label': 'Envíe una imagen de su firma',
    'signature.signature.hint': 'Dibuje con el dedo o con el ratón',
    'signature.signature.preview': 'Su firma',
    'signature.signature.aria': 'Recuadro para dibujar la firma',
    'signature.initials.type_label': 'Escriba sus iniciales',
    'signature.initials.upload_label': 'Envíe una imagen de su rúbrica',
    'signature.initials.hint': 'Trace las iniciales con el dedo o con el ratón',
    'signature.initials.preview': 'Su rúbrica',
    'signature.initials.aria': 'Recuadro para dibujar la rúbrica',
    'signature.mode.drawn': 'Dibujar',
    'signature.mode.typed': 'Escribir',
    'signature.mode.uploaded': 'Enviar imagen',
    'signature.mode_aria': 'Cómo quiere firmar',
    'signature.upload_hint': 'PNG o JPG, hasta {mb} MB',
    'signature.remove_background': 'Quitar el fondo claro de la foto',
    'signature.preparing': 'Preparando la imagen…',
    'signature.prepare_failed':
        'No fue posible preparar esta imagen. Pruebe con otra.',
    'signature.ready': 'Lista',
    'signature.style.caveat': 'Manuscrita',
    'signature.style.caveat_slanted': 'Manuscrita inclinada',
    'signature.pad.preparing': 'Preparando el recuadro…',
    'signature.pad.undo': 'Deshacer',
    'signature.pad.clear': 'Borrar',
    'signature.error.unsupported_browser':
        'Este navegador no admite la captura de firma.',
    'signature.error.open':
        'No fue posible abrir esta imagen. Envíe un PNG o JPG.',
    'signature.error.format':
        'Formato no aceptado. Envíe una imagen PNG o JPG.',
    'signature.error.too_large':
        'La imagen tiene {size} y el límite es {limit}.',
    'signature.error.empty': 'La imagen enviada está vacía.',
    'signature.error.empty_after_background':
        'La imagen quedó vacía después de quitar el fondo. Desactive "Quitar el fondo claro" e inténtelo de nuevo.',
} satisfies NamespaceOf<typeof reference>;
