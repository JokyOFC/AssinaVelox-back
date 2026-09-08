import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

/** Link textual azul 600 (padrão dos mocks de auth e rodapés de card). */
export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'font-semibold text-primary transition-colors hover:text-primary-hover hover:underline',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
