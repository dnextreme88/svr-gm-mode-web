import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PanelProps = {
    title?: ReactNode;
    actions?: ReactNode;
    className?: string;
    /** Extra classes for the title, e.g. a larger size. */
    titleClassName?: string;
    bodyClassName?: string;
    children: ReactNode;
};

export function Panel({
    title,
    actions,
    className,
    titleClassName,
    bodyClassName,
    children,
}: PanelProps) {
    return (
        <section className={cn('gm-panel', className)}>
            {(title || actions) && (
                <header className="flex items-center justify-between gap-4 border-b border-white/6 px-4 py-2.5">
                    {title && (
                        <h2 className={cn('gm-label text-ink', titleClassName)}>
                            {title}
                        </h2>
                    )}
                    {actions}
                </header>
            )}
            <div className={cn('p-4', bodyClassName)}>{children}</div>
        </section>
    );
}

type PageTitleProps = {
    kicker?: ReactNode;
    title: ReactNode;
    children?: ReactNode;
};

/** Big lower-third page title with an optional kicker and actions. */
export function PageTitle({ kicker, title, children }: PageTitleProps) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div className="animate-gm-wipe">
                {kicker && (
                    <div className="gm-chyron mb-1 inline-block bg-brand py-0.5 pl-3 font-condensed font-bold tracking-[0.2em] text-white uppercase">
                        {kicker}
                    </div>
                )}
                <h1 className="gm-heading text-4xl font-bold text-ink md:text-5xl">
                    {title}
                </h1>
            </div>
            {children && (
                <div className="flex flex-wrap items-center gap-2">
                    {children}
                </div>
            )}
        </div>
    );
}

export function Stat({
    label,
    value,
    tone,
}: {
    label: ReactNode;
    value: ReactNode;
    tone?: 'good' | 'bad' | 'brand';
}) {
    return (
        <div>
            <div className="gm-label">{label}</div>
            <div
                className={cn(
                    'gm-num text-2xl',
                    tone === 'good' && 'text-zone-safe',
                    tone === 'bad' && 'text-zone-serious',
                    tone === 'brand' && 'text-brand-hot',
                )}
            >
                {value}
            </div>
        </div>
    );
}

export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <div className="border border-dashed border-white/10 px-6 py-10 text-center text-sm text-ink-dim">
            {children}
        </div>
    );
}
