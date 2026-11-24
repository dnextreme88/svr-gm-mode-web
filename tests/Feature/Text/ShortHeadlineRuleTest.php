<?php

use App\Enums\NewsEventType;
use App\Services\News\FallbackStories;
use App\Services\News\NewsTemplates;
use App\Services\News\PromoStories;

/*
| Short headlines (house rule, 2026-11-15): every news headline is a
| summarized headline of at most 6 words of its own text ("New record for
| the {title_short}!"), with at most 3 {tokens}. The detail goes in the
| summary. Ticker lines are exempt.
*/

const HEADLINE_MAX_WORDS = 6;
const HEADLINE_MAX_TOKENS = 3;

/** Headlines the GM wrote longer on purpose (2026-11-17). */
const HEADLINE_LONG_ALLOWED = ['news:number_one_contender.fired_up'];

/**
 * Words in a headline template once its {tokens} are taken out.
 */
function headlineWords(string $template): int
{
    $text = (string) preg_replace('/\{\w+\}/', ' ', $template);
    $words = array_filter(preg_split('/\s+/', $text) ?: [], fn (string $w): bool => preg_match('/[A-Za-z0-9#]/', $w) === 1);

    return count($words);
}

/**
 * @return array<string, string> key => headline template
 */
function headlineTemplates(): array
{
    $headlines = [];

    foreach ((array) (new ReflectionClassConstant(NewsTemplates::class, 'TEMPLATES'))->getValue() as $key => $template) {
        $headlines["news:{$key}"] = (string) $template;
    }

    foreach (PromoStories::keys() as $key) {
        foreach (PromoStories::variants($key) as $i => [$headline]) {
            $headlines["promo:{$key}.{$i}"] = $headline;
        }
    }

    // Budget lines have no headline.
    foreach ((array) (new ReflectionClassConstant(FallbackStories::class, 'STORIES'))->getValue() as $key => $variants) {
        if (str_starts_with((string) $key, 'ticker')) {
            continue;
        }

        foreach ($variants as $i => $variant) {
            if (($variant[0] ?? '') !== '') {
                $headlines["fallback:{$key}.{$i}"] = $variant[0];
            }
        }
    }

    return $headlines;
}

it('keeps every headline to a short summary', function () {
    $long = [];

    foreach (headlineTemplates() as $key => $template) {
        if (in_array($key, HEADLINE_LONG_ALLOWED, true)) {
            continue;
        }

        $tokens = preg_match_all('/\{\w+\}/', $template);

        if (headlineWords($template) > HEADLINE_MAX_WORDS || $tokens > HEADLINE_MAX_TOKENS) {
            $long[] = "{$key}: {$template}";
        }
    }

    expect($long)->toBe([]);
});

it('writes the record headline from the title’s short name', function () {
    expect(app(NewsTemplates::class)->render(NewsEventType::RecordBroken, [
        'wrestler' => 'Eugene',
        'record' => 'longest Cruiserweight Championship reign',
        'title_short' => 'Cruiserweight title',
    ]))->toBe('New record for the Cruiserweight title!');
});
