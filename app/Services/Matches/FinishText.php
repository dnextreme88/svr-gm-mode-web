<?php

namespace App\Services\Matches;

/**
 * Finish text that fits the match's win condition (house rule): a Table match
 * is won by putting someone through a table, a Ladder match by retrieving the
 * briefcase, and so on.
 *
 * Templates use {W} (the one winner who scores it; house rule: drawn with
 * equal odds from the winning side), {L} (the one wrestler on the losing
 * side the finish happens to), {he}/{him}/{his}/{He} for {L}, and
 * [singular|plural] verb forms. Callers can pass more tokens, e.g. {T} for a
 * Captain Fall team's captain.
 */
final class FinishText
{
    /**
     * A clean finish, keyed by `match_types.win_condition`.
     *
     * @var array<string, string>
     */
    private const CLEAN = [
        'Put through a table' => '{W} [puts|put] {L} through a table.',
        'Briefcase retrieval' => '{W} [knocks|knock] {L} off the ladder and [pulls|pull] down the briefcase.',
        '10-count' => "{W} [pummels|pummel] {L} so hard on the mat that {he} can't answer the 10-count.",
        'Cage escape' => '{W} [leaves|leave] {L} down on the mat and [escapes|escape] the cage.',
        'Over the top rope' => '{W} last [eliminates|eliminate] {L} over the top rope.',
        'Last one in the ring' => '{W} [tosses|toss] {L} over the top rope and [is|are] the last one in the ring.',
        'Buried alive' => '{W} [buries|bury] {L} alive. {He} is nowhere to be seen (out for {weeks} weeks).',
        'First blood' => '{W} [busts|bust] {L} open for first blood.',
        'Bra & Panties' => '{W} [strips|strip] {L} down to {his} bra and panties.',
        'Most falls' => '{W} [scores|score] the deciding fall on {L} as time runs out.',
        'Submission' => '{L} taps out to {W}.',
    ];

    /**
     * How a chair shot or a run-in ends, keyed by `match_types.win_condition`:
     * it follows "{W} levels {L} with a steel chair and …".
     *
     * @var array<string, string>
     */
    private const CLAUSE = [
        'Put through a table' => '[puts|put] {him} through a table',
        'Briefcase retrieval' => '[climbs|climb] the ladder to retrieve the briefcase',
        '10-count' => "[stands|stand] tall as {L} can't answer the 10-count",
        'Cage escape' => '[escapes|escape] the cage',
        'Over the top rope' => '[tosses|toss] {him} over the top rope',
        'Last one in the ring' => '[tosses|toss] {him} over the top rope to be the last one in the ring',
        'Buried alive' => '[buries|bury] {him} alive. {He} is nowhere to be seen (out for {weeks} weeks)',
        'First blood' => '[draws|draw] first blood',
        'Bra & Panties' => '[strips|strip] {him} down to {his} bra and panties',
        'Most falls' => '[scores|score] the deciding fall',
        'Submission' => '[locks|lock] in a submission until {he} taps out',
    ];

    /**
     * @param  array<string, string>  $extra  more {token} values
     */
    public function __construct(
        private readonly string $winner,
        private readonly bool $plural,
        private readonly string $victim,
        private readonly bool $victimIsDiva,
        private readonly array $extra = [],
    ) {}

    /**
     * Every win condition with finish text, for the coverage test.
     *
     * @return list<string>
     */
    public static function conditions(): array
    {
        return array_keys(self::CLEAN);
    }

    /**
     * The clean finish for a win condition, or a plain fallback.
     */
    public function clean(string $condition): string
    {
        return $this->render(self::CLEAN[$condition] ?? '{W} [wins|win] by '.mb_strtolower($condition).'.');
    }

    /**
     * How the match ends after a chair shot or a run-in: the win condition's
     * clause, or "makes the cover" / "covers for three" when it's decided
     * by pinfall.
     */
    public function clause(?string $condition, bool $interference = false): string
    {
        $template = match (true) {
            $condition === null => $interference ? '[covers|cover] for three' : '[makes|make] the cover',
            default => self::CLAUSE[$condition] ?? '[wins|win] by '.mb_strtolower($condition),
        };

        return $this->render($template);
    }

    public function render(string $template): string
    {
        $text = (string) preg_replace_callback('/\[([^|\]]*)\|([^\]]*)\]/', fn (array $m): string => $this->plural ? $m[2] : $m[1], $template);

        return strtr($text, [
            '{W}' => $this->winner,
            '{L}' => $this->victim,
            '{he}' => $this->victimIsDiva ? 'she' : 'he',
            '{He}' => $this->victimIsDiva ? 'She' : 'He',
            '{him}' => $this->victimIsDiva ? 'her' : 'him',
            '{his}' => $this->victimIsDiva ? 'her' : 'his',
            ...$this->extra,
        ]);
    }
}
