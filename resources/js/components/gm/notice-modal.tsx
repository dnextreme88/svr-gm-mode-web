import { router } from '@inertiajs/react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import gm from '@/routes/gm';
import type { GmNotice } from '@/types/gm';

/**
 * One-off notices for the GM (house rule), shown once: a #1 contendership
 * that ended before its title match, and why.
 */
export function NoticeModal({
    saveId,
    notices,
}: {
    saveId: number;
    notices: GmNotice[];
}) {
    if (notices.length === 0) {
        return null;
    }

    return (
        <ConfirmDialog
            open
            title={
                notices.length === 1
                    ? 'A #1 contendership is off'
                    : `${notices.length} #1 contenderships are off`
            }
            message={
                <ul className="space-y-2" aria-label="Notices">
                    {notices.map((n) => (
                        <li key={n.id}>
                            {n.contenders} won’t get their{' '}
                            <strong className="text-gold-hot">{n.title}</strong>{' '}
                            match: {n.reason}.
                        </li>
                    ))}
                </ul>
            }
            cancelLabel="OK"
            onCancel={() =>
                router.post(
                    gm.notices.seen.url(saveId),
                    {},
                    { preserveScroll: true },
                )
            }
        />
    );
}
