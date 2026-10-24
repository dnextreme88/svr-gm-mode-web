import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';

type Props = {
    brand: Brand;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
};

const sizes = {
    sm: 'text-sm px-2 py-0.5',
    md: 'text-xl px-3 py-1',
    lg: 'text-4xl px-5 py-2',
};

/**
 * The on-screen brand bug: a scratched scarlet block for Raw, a chrome-blue
 * wordmark for Smackdown.
 */
export function BrandBug({ brand, size = 'md', className }: Props) {
    if (brand === 'raw') {
        return (
            <span
                className={cn(
                    'gm-heading inline-block -skew-x-6 bg-raw font-bold tracking-tight text-white shadow-[0_0_24px_-4px_var(--color-raw)]',
                    sizes[size],
                    className,
                )}
                style={{
                    backgroundImage:
                        'repeating-linear-gradient(115deg, rgb(0 0 0 / 0.18) 0 2px, transparent 2px 7px)',
                }}
            >
                Raw
            </span>
        );
    }

    return (
        <span
            className={cn(
                'gm-heading inline-block -skew-x-6 bg-gradient-to-b from-silver via-white to-sd-hot bg-clip-text font-bold tracking-tight text-transparent drop-shadow-[0_0_12px_rgb(30_99_255_/_0.6)]',
                sizes[size],
                className,
            )}
        >
            Smackdown
        </span>
    );
}
