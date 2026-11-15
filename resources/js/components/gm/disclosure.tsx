import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId } from 'react';
import { cn } from '@/lib/utils';

/**
 * The body of a show/hide toggle, animated (house rule, 2026-11-15): its
 * height eases between 0 and its content with a CSS grid-rows transition
 * (no JS measuring), with a fade alongside. The content stays in the DOM
 * but is `inert` while closed, so Tab and screen readers skip it. Reduced
 * motion switches instantly (`motion-reduce`).
 */
export function DisclosurePanel({
    open,
    id,
    children,
    className,
}: {
    open: boolean;
    id?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            id={id}
            data-state={open ? 'open' : 'closed'}
            inert={!open}
            className={cn(
                'grid transition-[grid-template-rows,opacity] duration-200 ease-out motion-reduce:transition-none',
                open
                    ? 'grid-rows-[1fr] opacity-100'
                    : 'grid-rows-[0fr] opacity-0',
            )}
        >
            <div className={cn('min-h-0 overflow-hidden', className)}>
                {children}
            </div>
        </div>
    );
}

/** The chevron that turns over as its disclosure opens. */
export function DisclosureChevron({
    open,
    className,
}: {
    open: boolean;
    className?: string;
}) {
    return (
        <ChevronDown
            aria-hidden
            className={cn(
                'size-4 shrink-0 transition-transform duration-200 ease-out motion-reduce:transition-none',
                open && 'rotate-180',
                className,
            )}
        />
    );
}

/** A unique id to tie a toggle button to its panel (`aria-controls`). */
export function useDisclosureId(): string {
    return useId();
}
