import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/signature';

export default {
    'signature.signature.type_label': 'Type your name',
    'signature.signature.upload_label': 'Upload an image of your signature',
    'signature.signature.hint': 'Draw with your finger or the mouse',
    'signature.signature.preview': 'Your signature',
    'signature.signature.aria': 'Pad to draw the signature',
    'signature.initials.type_label': 'Type your initials',
    'signature.initials.upload_label': 'Upload an image of your initials',
    'signature.initials.hint':
        'Draw your initials with your finger or the mouse',
    'signature.initials.preview': 'Your initials',
    'signature.initials.aria': 'Pad to draw the initials',
    'signature.mode.drawn': 'Draw',
    'signature.mode.typed': 'Type',
    'signature.mode.uploaded': 'Upload image',
    'signature.mode_aria': 'How do you want to sign',
    'signature.upload_hint': 'PNG or JPG, up to {mb} MB',
    'signature.remove_background': 'Remove the light background of the photo',
    'signature.preparing': 'Preparing the image…',
    'signature.prepare_failed':
        'Could not prepare this image. Try another one.',
    'signature.ready': 'Ready',
    'signature.style.caveat': 'Handwritten',
    'signature.style.caveat_slanted': 'Slanted handwritten',
    'signature.pad.preparing': 'Preparing the pad…',
    'signature.pad.undo': 'Undo',
    'signature.pad.clear': 'Clear',
    'signature.error.unsupported_browser':
        'This browser does not support signature capture.',
    'signature.error.open': 'Could not open this image. Upload a PNG or JPG.',
    'signature.error.format': 'Format not accepted. Upload a PNG or JPG image.',
    'signature.error.too_large':
        'The image is {size} and the limit is {limit}.',
    'signature.error.empty': 'The uploaded image is empty.',
    'signature.error.empty_after_background':
        'The image was empty after removing the background. Turn off "Remove the light background" and try again.',
} satisfies NamespaceOf<typeof reference>;
