<?php

namespace App\Services\Matches\Stories;

/**
 * The words for conditional match stories (house rule, 2026-11-07): the
 * pop-up's title and story, added to the match's news summary too.
 *
 * Every story is one paragraph of 5–8 sentences (the story-text rule in
 * CLAUDE.md, 2026-11-11). {tokens} are filled by the story; pronoun tokens follow the
 * person they name ({rival_he}, {victim_him}…).
 */
final class ConditionalStoryTexts
{
    /** @var array<string, string> */
    public const TITLES = [
        'hornswoggle_shillelagh.success' => 'Hornswoggle to the rescue!',
        'undertaker_lights_out.success' => 'The lights go out!',
        'undertaker_lights_out.failure' => 'Lights out, but not this time',
        'kane_pyro.success' => "Kane's pyro!",
        'kane_pyro.failure' => 'Pyro without the payoff',
        'dudley_tables.success' => 'Get the tables!',
        'dudley_tables.failure' => 'No tables this time',
        'hardy_ladder.success' => "Team Xtreme's ladder spot",
        'hardy_ladder.failure' => 'Off the ladder',
    ];

    /** @var array<string, string> */
    public const STORIES = [
        'hornswoggle_shillelagh.success' => 'With the referee busy with {victim}, Hornswoggle slid out from under the ring holding {finlay}\'s shillelagh. He looked both ways, then tossed it up to {finlay}, who caught it with a grin. The crowd saw it coming and roared, but the referee never turned around. One swing later {victim} was down, and {finlay} finished the job. By the time the referee looked back, the shillelagh was gone and Hornswoggle was nowhere to be seen. {finlay} owes this one to his little bastard.',

        'undertaker_lights_out.success' => '{opening} the arena went dark for about 10 seconds. A bell tolled somewhere in the blackness, and the crowd went silent. {rival} stood in the ring in plain disbelief, waiting for whatever was coming. When the lights came back, Undertaker was right behind {rival_him}, and a {move} later {scorer} took full advantage to win the {match_word}. Undertaker was gone before anyone could blink. {rival} vowed to get revenge on Undertaker and settle things between them once and for all.',
        'undertaker_lights_out.failure' => '{opening} the arena went dark for about 10 seconds. A bell tolled somewhere in the blackness, and the crowd held its breath. {rival} braced for it, eyes fixed on the darkness. When the lights came back, {rival_he} had already turned around and slipped away from Undertaker\'s {move}. {rival} went on to win the {match_word}, and this rivalry is far from over. The Deadman will not take kindly to being outsmarted.',

        'kane_pyro.success' => 'Out of nowhere, Kane\'s pyro exploded from the {location}. The ring shook with every blast, and the crowd turned toward the entrance as one. {rival} turned to stare at the flames, waiting for the Big Red Machine to appear. He never did, but he didn\'t need to. That split second was all {scorer} needed to take advantage and win the {match_word}. {rival} vowed to get revenge on Kane and settle things between them once and for all.',
        'kane_pyro.failure' => 'Kane\'s pyro exploded from the {location}, but {rival} wasn\'t fazed by the shenanigans one bit. The crowd jumped at every blast, and the ring rattled under their feet. {rival_He} kept {rival_his} eyes on {rival_his} opponent and made sure Kane couldn\'t get in the way of {rival_his} win. The flames died down without changing a thing. {rival} won the {match_word} anyway, and Kane will have to try something else.',

        'dudley_tables.success' => '{setter} slid out of the ring and pulled a table from underneath it. The crowd knew what was coming and started the chant before it was even set up. "Get the tables!" echoed around the arena as the Dudleys went to work. {opening} {thrower} drove {victim} through it with authority. Wood splintered everywhere, and the fans at ringside ducked for cover. {outcome}',
        'dudley_tables.failure' => '{setter} slid out of the ring and pulled a table from underneath it. The crowd started the chant right away, sensing a big moment. {setter_He} set it up by the ropes and called for {victim}. Before the Dudleys could use it, {victim} knocked the table over and the plan fell apart. The fans groaned as the table clattered to the floor. The match went on without it.',

        'hardy_ladder.success' => '{partner} dragged a ladder into the ring and set it up in the corner. The crowd rose to its feet the moment they saw it. {climber} climbed to the top while {victim} lay on the mat below. {opening} {climber} came off the top with a {move}, crashing down on {victim}. It was the kind of risk only Team Xtreme would take. {outcome}',
        'hardy_ladder.success_lita' => '{partner} dragged a ladder into the ring and set it up in the corner. The crowd rose to its feet the moment they saw it. Lita held it steady while {climber} climbed to the top, with {victim} down on the mat below. {opening} {climber} came off the top with a {move}, crashing down on {victim}. Lita was the first to celebrate. {outcome}',
        'hardy_ladder.failure' => '{partner} dragged a ladder into the ring and set it up in the corner. The crowd rose to its feet, ready for something big. {climber} started to climb with {victim} down on the mat below. Before {climber_he} could hit the {move}, {victim} got back up and knocked {climber_him} off the ladder. The fans winced as {climber_he} hit the mat. Team Xtreme\'s big moment never came.',
        'hardy_ladder.failure_lita' => '{partner} dragged a ladder into the ring and set it up in the corner. The crowd rose to its feet, ready for something big. Lita tried to hold it steady, but {victim} shoved her away from it first. {climber} lost {climber_his} footing before {climber_he} could hit the {move}, and came crashing down. The fans winced at the fall. Team Xtreme\'s big moment never came.',
    ];

    /**
     * @param  array<string, string>  $tokens
     */
    public static function render(string $key, array $tokens): string
    {
        $template = self::STORIES[$key] ?? self::STORIES[self::base($key)];
        $filled = strtr($template, self::braced($tokens));

        // An empty opening leaves a stray space at the start of a sentence.
        return trim((string) preg_replace('/\s{2,}/', ' ', $filled));
    }

    public static function title(string $key): string
    {
        return self::TITLES[$key] ?? self::TITLES[self::base($key)];
    }

    /**
     * "hardy_ladder.success_lita" → "hardy_ladder.success".
     */
    private static function base(string $key): string
    {
        return (string) preg_replace('/_lita$/', '', $key);
    }

    /**
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    private static function braced(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $name => $value) {
            $out['{'.$name.'}'] = $value;
        }

        return $out;
    }
}
