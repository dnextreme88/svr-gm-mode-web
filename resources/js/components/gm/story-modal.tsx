import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { TitleMark } from '@/components/gm/badges';

/** A conditional match story to show (house rule, 2026-11-07). */
export type ConditionalStoryPopup = {
    id: number;
    story: string;
    outcome: 'success' | 'failure';
    match_slot_id: number;
    show: string;
    position: number;
    championship: string | null;
    result: string;
    title: string;
    text: string;
    what_changed: string;
    next_label: string;
    next: string;
};

/**
 * The pop-up after a simulated match where a conditional story played out
 * (Hornswoggle's shillelagh, Undertaker's lights-out, Kane's pyro, the
 * Dudleys' tables, Team Xtreme's ladder). Several from Simulate all show
 * one at a time, in air order.
 */
export function StoryModal({
    story,
    index,
    total,
    onClose,
}: {
    story: ConditionalStoryPopup | null;
    /** 1-based, of `total` in this run. */
    index: number;
    total: number;
    onClose: () => void;
}) {
    if (story === null) {
        return null;
    }

    return (
        <ConfirmDialog
            open
            title={story.title}
            cancelLabel="Got it"
            onCancel={onClose}
            message={
                <div className="space-y-3" data-testid="story-modal">
                    <p className="gm-label text-gold-hot">
                        Story
                        {total > 1 && (
                            <span className="ml-2 text-ink-faint">
                                {index} of {total}
                            </span>
                        )}
                    </p>
                    <p className="text-sm text-ink-faint">
                        {story.show} · Match {story.position}
                        {story.championship && (
                            <span className="ml-1 inline-flex items-center gap-1 text-gold-hot">
                                · <TitleMark name={story.championship} />
                                {story.championship}
                            </span>
                        )}
                    </p>
                    {story.text
                        .split(/\n\s*\n/)
                        .filter((p) => p.trim() !== '')
                        .map((p, i) => (
                            <p key={i} className="text-ink">
                                {p}
                            </p>
                        ))}
                    <dl className="grid gap-1 text-sm sm:grid-cols-[max-content_1fr] sm:gap-x-3">
                        <dt className="gm-label">Result</dt>
                        <dd>{story.result}</dd>
                        <dt className="gm-label">What changed</dt>
                        <dd>{story.what_changed}</dd>
                        <dt className="gm-label">{story.next_label}</dt>
                        <dd>{story.next}</dd>
                    </dl>
                </div>
            }
        />
    );
}
