import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/capture';

export default {
    'capture.evidence_note':
        'The image is kept as evidence of your acceptance and is not an identity verification.',
    'capture.confirm_code_first': 'Confirm the code before sending photos.',
    'capture.saved': '{label} recorded.',
    'capture.error.unknown_delivery':
        'We did not receive confirmation of the upload. Check your connection and tap “Use this photo” again — if the previous photo arrived, it is replaced.',
    'capture.error.not_required':
        'This photo is no longer requested from you. Reload the page.',
    'capture.error.generic': 'The photo could not be sent. Try again.',
    'capture.retention.one': ' The photos are kept for up to {count} day.',
    'capture.retention.other': ' The photos are kept for up to {count} days.',
    'capture.retention_kept':
        ' The photos are kept while the document exists in the sender’s account.',
    'capture.sent': 'Sent {date}',
    'capture.redo': 'Redo',
    'capture.take': 'Take or upload photo',
    'capture.preview': 'After the code, you will send: {items}. {note}',
    'camera.error.denied':
        'Camera permission was denied. You can allow access in the browser settings or upload a photo from your device.',
    'camera.error.not_found':
        'No camera was found on this device. Upload a photo from your device.',
    'camera.error.busy':
        'The camera is being used by another app. Close it and try again, or upload a photo.',
    'camera.error.generic':
        'Could not open the camera. Upload a photo from your device.',
    'camera.intro':
        'When you tap {open}, the browser asks for permission to use this device’s camera. The camera stays on only in this window and the photo is only sent after you check and confirm it.',
    'camera.open': 'Open camera',
    'camera.choose': 'Choose a photo from the device',
    'camera.unsupported':
        'This browser does not allow using the camera on this page. Choose a photo from your device.',
    'camera.waiting': 'Waiting for camera permission…',
    'camera.video_aria': 'Camera image',
    'camera.take': 'Take photo',
    'camera.preview_alt': 'Preview: {label}',
    'camera.check':
        'Check that the photo is sharp and not cut off before sending.',
    'camera.use': 'Use this photo',
    'camera.still_opening': 'The camera is still opening. Wait a moment.',
    'camera.failed': 'The photo could not be created. Try again.',
    'camera.format': 'Send a JPEG or PNG photo.',
    'camera.too_large': 'The photo is larger than {size} MB.',
    'video.error.denied':
        'Camera permission was denied. Allow access in the browser settings or upload a video recorded on your device.',
    'video.error.not_found':
        'No camera was found on this device. Upload a video recorded on your device or open the link on another device.',
    'video.error.busy':
        'The camera is being used by another app. Close it and try again.',
    'video.error.generic':
        'Could not open the camera. Upload a video recorded on your device or open the link on another device.',
    'video.confirm_code_first': 'Confirm the code before sending the video.',
    'video.saved': 'Short video recorded.',
    'video.error.unknown_delivery':
        'We did not receive confirmation of the upload. Check your connection and tap “Send video” again — if the previous one arrived, it is replaced.',
    'video.error.not_required':
        'The video is no longer requested from you. Reload the page.',
    'video.error.upload': 'The video could not be sent. Try again.',
    'video.retention.one': ' The video is kept for up to {count} day.',
    'video.retention.other': ' The video is kept for up to {count} days.',
    'video.retention_kept':
        ' The video is kept while the document exists in the sender’s account.',
    'video.sent': 'Sent {date}',
    'video.up_to.one': 'Up to {count} second.',
    'video.up_to.other': 'Up to {count} seconds.',
    'video.record_again': 'Record again',
    'video.record': 'Record video',
    'video.no_recorder':
        'This browser does not record video. Upload a video recorded on your device or open the link on another device.',
    'video.empty': 'The recording was empty. Try again.',
    'video.too_large':
        'The video is larger than {size}. Record a shorter video.',
    'video.unsupported': 'This browser does not record video on the page.',
    'video.camera_on': 'Turn on the camera',
    'video.upload': 'Upload video from the device',
    'video.recording': '● {elapsed} of {max} s',
    'video.start': 'Start recording',
    'video.stop': 'Stop',
    'video.ready': 'Recording ready',
    'video.chosen': 'Video chosen',
    'video.send': 'Send video',
    'video.consent_courtesy':
        'Courtesy translation: the authorization recorded is the reference text, in Portuguese.',
} satisfies NamespaceOf<typeof reference>;
