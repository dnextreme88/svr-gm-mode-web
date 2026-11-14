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
    // A failed booking says so and keeps the reminder open (UI audit,
    // 2026-11-12); it used to close and fail silently.
    const [error, setError] = useState<string | null>(null);

    if (shots.length === 0) {
        return null;
    }

    return (
        <ConfirmDialog
            open={open}
            busy={booking}
            busyText="Booking…"
            footer={
                error && (
                    <p role="alert" className="text-sm text-zone-serious">
                        {error}
                    </p>
                )
            }
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
                          setError(null);

                          try {
                              await onBook();
                              setOpen(false);
                          } catch (e) {
                              setError(
                                  e instanceof Error && e.message
                                      ? e.message
                                      : 'Couldn’t book the title match. Try again, or book it by hand.',
                              );
                          } finally {
                              setBooking(false);
                          }
                      }
                    : undefined
            }
        />
    );
}
