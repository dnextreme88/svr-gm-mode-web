import { portraitFor, portraitPlaceholder } from '@/lib/assets';
import { cn } from '@/lib/utils';

/** On-screen sizes: inline text, table rows, featured names. */
const sizes = {
    sm: { px: 24, file: 64 },
    md: { px: 32, file: 64 },
    lg: { px: 48, file: 96 },
} as const;

/**
 * A superstar's portrait before their name (2026-11-20): a small circle. It's
 * decorative, since the name sits beside it. Superstars without a portrait
 * yet get the placeholder silhouette, framed by a gray backing and a thin
 * ring.
 */
export function SuperstarPortrait({
    slug,
    size = 'md',
    className,
}: {
    slug: string | null | undefined;
    size?: keyof typeof sizes;
    className?: string;
}) {
    const { px, file } = sizes[size];
    const src = portraitFor(slug, file);
    // The gray backing and the ring frame the placeholder silhouette; a real
    // photo shows as it is.
    const placeholder = src === portraitPlaceholder;

    return (
        <img
            src={src}
            width={px}
            height={px}
            alt=""
            aria-hidden
            loading="lazy"
            decoding="async"
            draggable={false}
            data-testid="superstar-portrait"
            data-placeholder={placeholder || undefined}
            className={cn(
                'inline-block shrink-0 object-cover select-none',
                placeholder && 'rounded-full bg-[#8a8f98] ring-1 ring-white/20',
                className,
            )}
            style={{ width: px, height: px }}
        />
    );
}
