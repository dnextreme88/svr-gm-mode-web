import { brandLogo, ppvLogo } from '@/lib/assets';
import { cn } from '@/lib/utils';
import { PpvKeyLabels } from '@/types/enums';
import type { Brand, PpvKey } from '@/types/enums';

type Props = {
    brand: Brand;
    size?: 'xs' | 'sm' | 'md' | 'lg';
    className?: string;
};

const sizes = {
    xs: 'h-4',
    sm: 'h-6',
    md: 'h-9',
    lg: 'h-14',
};

/** The on-screen brand bug: Raw's or Smackdown's 2005 logo. */
export function BrandBug({ brand, size = 'md', className }: Props) {
    return (
        <img
            src={brandLogo[brand]}
            alt={brand === 'raw' ? 'Raw' : 'Smackdown'}
            draggable={false}
            className={cn(
                'inline-block w-auto max-w-none shrink-0 object-contain select-none',
                sizes[size],
                className,
            )}
        />
    );
}

/** A PPV's logo, sized like the brand bug (house rule, 2026-11-11). */
export function PpvBug({
    ppv,
    size = 'md',
    className,
}: {
    ppv: PpvKey;
    size?: Props['size'];
    className?: string;
}) {
    return (
        <img
            src={ppvLogo[ppv]}
            alt={PpvKeyLabels[ppv]}
            draggable={false}
            className={cn(
                'inline-block w-auto max-w-none shrink-0 object-contain select-none',
                sizes[size],
                className,
            )}
        />
    );
}
