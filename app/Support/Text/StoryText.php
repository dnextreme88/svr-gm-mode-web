<?php

namespace App\Support\Text;

/**
 * The story-text rule (house rule, 2026-11-11): news summaries, story texts
 * and email bodies run 1–3 paragraphs (split on a blank line), each of 5–8
 * sentences. Bounds live in `config('gm.story_text')`.
 */
final class StoryText
{
    /** Abbreviations whose full stop doesn't end a sentence. */
    private const ABBREVIATIONS = ['vs', 'Jr', 'Sr', 'Mr', 'Mrs', 'Ms', 'Dr', 'St', 'Mt'];

    /**
     * @return list<string>
     */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/', trim($text)) ?: []),
            fn (string $p): bool => $p !== '',
        ));
    }

    public static function sentences(string $paragraph): int
    {
        $abbr = implode('|', array_map(fn (string $a): string => preg_quote($a, '/'), self::ABBREVIATIONS));
        // Drop abbreviations' full stops and decimals so they don't count.
        $text = preg_replace("/\\b(?:{$abbr})\\./", '', $paragraph) ?? $paragraph;
        $text = preg_replace('/(\d)\.(\d)/', '$1$2', $text) ?? $text;
        // An ellipsis or "?!" ends one sentence, not several.
        $text = preg_replace('/[.!?]+/', '.', $text) ?? $text;

        // preg_match_all only fails on a bad pattern or invalid UTF-8.
        $count = preg_match_all('/\.(?=["”’\')]*(\s|$))/u', $text);

        return $count === false ? 0 : $count;
    }

    /**
     * Why the text breaks the rule, or null when it fits.
     */
    public static function problem(string $text): ?string
    {
        $min = (int) config('gm.story_text.paragraph_min', 5);
        $max = (int) config('gm.story_text.paragraph_max', 8);
        $most = (int) config('gm.story_text.max_paragraphs', 3);
        $paragraphs = self::paragraphs($text);

        if ($paragraphs === []) {
            return 'empty';
        }

        if (count($paragraphs) > $most) {
            return count($paragraphs)." paragraphs (max {$most})";
        }

        foreach ($paragraphs as $i => $paragraph) {
            $n = self::sentences($paragraph);

            if ($n < $min || $n > $max) {
                return 'paragraph '.($i + 1)." has {$n} sentences ({$min}–{$max})";
            }
        }

        return null;
    }

    public static function fits(string $text): bool
    {
        return self::problem($text) === null;
    }
}
