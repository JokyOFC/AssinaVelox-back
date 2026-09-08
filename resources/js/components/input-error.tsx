import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/** Mensagem de validação sob o campo (DESIGN §4.2: `text-danger text-[12px]`). */
export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p
            {...props}
            role="alert"
            className={cn('text-[12px] font-medium text-danger', className)}
        >
            {message}
        </p>
    ) : null;
}
