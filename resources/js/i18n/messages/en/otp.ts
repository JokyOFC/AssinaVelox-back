import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/otp';

export default {
    'otp.simulated': 'simulated',
    'otp.simulated_title':
        'Test environment: the message does not reach the phone.',
    'otp.by_channel': ' by {channel}',
    'otp.unavailable_title': 'The code cannot be sent{by} right now',
    'otp.unavailable_contact':
        'Contact {sender} ({organization}) to receive the document through another channel.',
    'otp.will_send': 'We will send a {length}-digit code{by} to {destination}.',
    'otp.receive': 'Get code by {channel}',
    'otp.sent': 'We sent a code{by} to {destination}',
    'otp.input_aria': '{length}-digit code received by {channel}',
    'otp.valid_for': 'Code valid for {minutes} min',
    'otp.resend_in': 'Resend in {seconds}s',
    'otp.resend': 'Resend code',
    'otp.attempts_exhausted': 'No attempts left. Request a new code.',
    'otp.attempts_left.one': '{count} attempt left before you need a new code.',
    'otp.attempts_left.other':
        '{count} attempts left before you need a new code.',
    'otp.confirm': 'Confirm code and continue',
    'otp.hello': 'Hello, {name}',
    'otp.heading_default': 'Confirm your identity to sign',
    'otp.intro': '{sender} ({organization}) sent you this document.',
    'otp.intro_sent':
        '{sender} ({organization}) sent you this document on {date}.',
    'otp.deadline': 'Deadline: {deadline}.',
    'otp.pin_blocked_title': 'PIN blocked',
    'otp.pin_blocked_body':
        'The PIN was blocked after several incorrect attempts. Contact {sender} ({organization}): only the sender can set a new PIN.',
    'otp.pin_locked_title': 'PIN temporarily blocked',
    'otp.pin_locked_body':
        'After several incorrect attempts, the PIN is blocked until {time} ({remaining} left). Then request a new code and enter the PIN again.',
    'otp.pin_after_code':
        'After the code, you will enter the PIN that {sender} agreed with you.',
    'otp.agree':
        'By continuing you agree to the {terms}. Your data is handled under the Brazilian data protection law (LGPD).',
    'otp.terms_link': 'terms of use (in Portuguese)',
    'pin.intro':
        'Code confirmed. Now enter the {pin} that {sender} agreed with you. AssinaVelox does not send this PIN.',
    'pin.label': 'PIN ({min} to {max} digits)',
    'pin.attempts_left.one':
        '{count} attempt left before the PIN is blocked for a while.',
    'pin.attempts_left.other':
        '{count} attempts left before the PIN is blocked for a while.',
    'pin.confirm': 'Confirm PIN and continue',
    'pin.help':
        'Did not get a PIN? Contact {sender}: only the sender can give it to you.',
} satisfies NamespaceOf<typeof reference>;
