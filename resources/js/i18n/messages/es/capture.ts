import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/capture';

export default {
    'capture.evidence_note':
        'La imagen se guarda como evidencia de su aceptación y no es una verificación de identidad.',
    'capture.confirm_code_first': 'Confirme el código antes de enviar fotos.',
    'capture.saved': '{label} registrada.',
    'capture.error.unknown_delivery':
        'No recibimos la confirmación del envío. Revise la conexión y toque “Usar esta foto” de nuevo — si la foto anterior llegó, se reemplaza.',
    'capture.error.not_required':
        'Esta foto ya no se le pide. Recargue la página.',
    'capture.error.generic':
        'No fue posible enviar la foto. Inténtelo de nuevo.',
    'capture.retention.one': ' Las fotos se guardan hasta {count} día.',
    'capture.retention.other': ' Las fotos se guardan hasta {count} días.',
    'capture.retention_kept':
        ' Las fotos se guardan mientras el documento exista en la cuenta de quien lo envió.',
    'capture.sent': 'Enviada {date}',
    'capture.redo': 'Rehacer',
    'capture.take': 'Tomar o enviar foto',
    'capture.preview': 'Después del código, usted enviará: {items}. {note}',
    'camera.error.denied':
        'Se denegó el permiso de la cámara. Puede habilitar el acceso en la configuración del navegador o enviar una foto de su dispositivo.',
    'camera.error.not_found':
        'No se encontró ninguna cámara en este dispositivo. Envíe una foto de su dispositivo.',
    'camera.error.busy':
        'La cámara está en uso por otra aplicación. Ciérrela e inténtelo de nuevo, o envíe una foto.',
    'camera.error.generic':
        'No fue posible abrir la cámara. Envíe una foto de su dispositivo.',
    'camera.intro':
        'Al tocar {open}, el navegador pide permiso para usar la cámara de este dispositivo. La cámara solo queda encendida en esta ventana y la foto solo se envía después de que usted la revise y la confirme.',
    'camera.open': 'Abrir cámara',
    'camera.choose': 'Elegir foto del dispositivo',
    'camera.unsupported':
        'Este navegador no permite usar la cámara en esta página. Elija una foto de su dispositivo.',
    'camera.waiting': 'Esperando el permiso de la cámara…',
    'camera.video_aria': 'Imagen de la cámara',
    'camera.take': 'Tomar foto',
    'camera.preview_alt': 'Vista previa: {label}',
    'camera.check':
        'Revise que la foto esté nítida y sin cortes antes de enviarla.',
    'camera.use': 'Usar esta foto',
    'camera.still_opening':
        'La cámara todavía se está abriendo. Espere un momento.',
    'camera.failed': 'No fue posible generar la foto. Inténtelo de nuevo.',
    'camera.format': 'Envíe una foto en JPEG o PNG.',
    'camera.too_large': 'La foto supera {size} MB.',
    'video.error.denied':
        'Se denegó el permiso de la cámara. Habilite el acceso en la configuración del navegador o envíe un video grabado con el dispositivo.',
    'video.error.not_found':
        'No se encontró ninguna cámara en este dispositivo. Envíe un video grabado con el dispositivo o abra el enlace en otro dispositivo.',
    'video.error.busy':
        'La cámara está en uso por otra aplicación. Ciérrela e inténtelo de nuevo.',
    'video.error.generic':
        'No fue posible abrir la cámara. Envíe un video grabado con el dispositivo o abra el enlace en otro dispositivo.',
    'video.confirm_code_first': 'Confirme el código antes de enviar el video.',
    'video.saved': 'Video corto registrado.',
    'video.error.unknown_delivery':
        'No recibimos la confirmación del envío. Revise la conexión y toque “Enviar video” de nuevo — si el anterior llegó, se reemplaza.',
    'video.error.not_required':
        'El video ya no se le pide. Recargue la página.',
    'video.error.upload': 'No fue posible enviar el video. Inténtelo de nuevo.',
    'video.retention.one': ' El video se guarda hasta {count} día.',
    'video.retention.other': ' El video se guarda hasta {count} días.',
    'video.retention_kept':
        ' El video se guarda mientras el documento exista en la cuenta de quien lo envió.',
    'video.sent': 'Enviado {date}',
    'video.up_to.one': 'Hasta {count} segundo.',
    'video.up_to.other': 'Hasta {count} segundos.',
    'video.record_again': 'Grabar de nuevo',
    'video.record': 'Grabar video',
    'video.no_recorder':
        'Este navegador no graba video. Envíe un video grabado con el dispositivo o abra el enlace en otro dispositivo.',
    'video.empty': 'La grabación quedó vacía. Inténtelo de nuevo.',
    'video.too_large': 'El video supera {size}. Grabe un video más corto.',
    'video.unsupported': 'Este navegador no graba video en la página.',
    'video.camera_on': 'Encender la cámara',
    'video.upload': 'Enviar video del dispositivo',
    'video.recording': '● {elapsed} de {max} s',
    'video.start': 'Empezar a grabar',
    'video.stop': 'Detener',
    'video.ready': 'Grabación lista',
    'video.chosen': 'Video elegido',
    'video.send': 'Enviar video',
    'video.consent_courtesy':
        'Traducción de cortesía: la autorización registrada es la del texto de referencia, en portugués.',
} satisfies NamespaceOf<typeof reference>;
