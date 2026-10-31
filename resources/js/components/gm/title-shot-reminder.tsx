import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import type { TitleContext } from '@/types/gm';

type Shot = TitleContext['title_shots'][number];

/**
 * House rule: the card a contender's earned Champion vs. Contender title
 * shot is due on opens with a reminder. "Book it now" books the title
 * match (champion vs contender) into a free slot; "Later" closes it until
 * the next visit. A champion's win earns no shot, so there's no reminder.
 */
export function TitleShotReminder({
    shots,
    canBook,
    onBook,
}: {
    /** Due shots this card hasn't booked yet. */
    shots: Shot[];
    /** Whether the card can still be booked (not confirmed, not aired). */
    canBook: boolean;
    onBook: () => Promise<void> | void;
}) {
    const [open, setOpen] = useState(shots.length > 0);
    const [booking, setBooking] = useState(false);

    if (shots.length === 0) {
        return null;
    }

    return (
        <ConfirmDialog
            open={open && !booking}
            title="Title match to book"
            message={
                <ul className="space-y-2">
                    {shots.map((shot) => (
                        <li key={shot.id}>
                            {shot.contenders} earned a{' '}
                            <strong className="text-gold-hot">
                                {shot.championship}
                            </strong>{' '}
                            match by winning a Champion vs. Contender match. It
                            has to be on this card before the week airs.
                        </li>
                    ))}
                </ul>
            }
            cancelLabel="Later"
            confirmLabel="Book it now"
            onCancel={() => setOpen(false)}
            onConfirm={
                canBook
                    ? async () => {
                          setBooking(true);

                          try {
                              await onBook();
                          } finally {
                              setBooking(false);
                              setOpen(false);
                          }
                      }
                    : undefined
            }
        />
    );
}
