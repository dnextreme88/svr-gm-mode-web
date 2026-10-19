import { Fragment } from 'react';
import type { MentionMatcher } from '@/lib/mentions';
import { splitMentions } from '@/lib/mentions';

/**
 * Text with every wrestler name coloured by that wrestler's current brand:
 * Raw red, SmackDown blue. Free agents keep the surrounding colour.
 */
export function MentionText({
    text,
    matcher,
}: {
    text: string;
    matcher: MentionMatcher;
}) {
    return (
        <>
            {splitMentions(text, matcher).map((part, i) =>
                typeof part === 'string' ? (
                    <Fragment key={i}>{part}</Fragment>
                ) : (
                    <span
                        key={i}
                        data-mention={part.brand ?? 'free_agent'}
                        className={
                            part.brand === 'raw'
                                ? 'gm-name-raw'
                                : part.brand === 'smackdown'
                                  ? 'gm-name-smackdown'
                                  : 'font-semibold'
                        }
                    >
                        {part.name}
                    </span>
                ),
            )}
        </>
    );
}
