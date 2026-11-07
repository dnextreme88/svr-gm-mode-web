import { brandLogo } from '@/lib/assets';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';

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
