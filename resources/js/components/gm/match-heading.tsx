import { TitleBeltImage } from '@/components/gm/badges';

/**
 * The heading of the live results' outcome and Book to Win cards (2026-11-17):
 * the match and its type on the first line, the biggest text on the card,
 * then the title on the line (belt first) on its own line, the next size
 * down. No show name: the night toggle and the list already say which show.
 */
export function MatchHeading({
    testId,
    position,
    isMainEvent,
    matchType,
    title,
    vacant,
}: {
    testId: string;
    position: number;
    isMainEvent: boolean;
    matchType: string | null;
    title: string | null;
    vacant: boolean;
}) {
    return (
        <header data-testid={testId}>
            <h2 className="font-display text-xl leading-tight font-bold tracking-wide text-ink uppercase lg:text-2xl">
                {isMainEvent ? 'Main Event' : `Match ${position}`}
                {matchType && ` - ${matchType}`}
            </h2>
            {title && (
                <p
                    data-testid={`${testId}-title`}
                    className="mt-2 inline-flex items-center gap-2 font-condensed text-xl font-semibold tracking-wide text-gold-hot lg:text-2xl"
                >
                    <TitleBeltImage
                        name={title}
                        decorative
                        className="h-8 md:h-9"
                    />
                    {title}
                    {vacant && ' (vacant)'}
                </p>
            )}
        </header>
    );
}
