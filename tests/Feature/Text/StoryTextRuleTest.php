<?php

use App\Enums\NewsEventType;
use App\Services\Mail\EmailTexts;
use App\Services\Matches\Stories\ConditionalStoryTexts;
use App\Services\News\FallbackStories;
use App\Services\News\NewsTemplates;
use App\Services\News\PromoStories;
use App\Support\Text\StoryText;

/*
| The story-text rule (house rule, 2026-11-11): every news summary, story
| text and email body runs 1–3 paragraphs of 5–8 sentences. Headlines,
| subjects and ticker lines are exempt.
*/

/**
 * Fill every {placeholder} with a sample value; `$fragments` covers the ones
 * that are whole sentences (or nothing).
 *
 * @param  array<string, string>  $fragments
 */
function sampleFill(string $text, array $fragments = []): string
{
    return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $fragments[$m[1]] ?? match ($m[1]) {
        'how' => 'via pinfall',
        'gimmick' => ' in a Ladder match',
        'ppv_how' => ' via pinfall',
        'by' => ' by pinfall',
        'race' => "Right now you're ahead: 120,000 fans to 110,000.",
        'weeks' => '3 weeks',
        'amount' => '$400,000',
        default => 'Edge',
    }, $text);
}

/**
 * The sentence fragments some summaries end with, empty and filled.
 *
 * @return list<array<string, string>>
 */
function fragmentSets(): array
{
    return [
        ['crowd' => '', 'others' => '', 'budget' => '', 'rivalry' => ''],
        [
            'crowd' => ' 41,302 fans were in the building.',
            'others' => ' Friday Night Smackdown drew 12,300.',
            'budget' => 'Money is tight, though: Raw is down to $400,000. The GM needs to act fast.',
            'rivalry' => ' The attack has started a rivalry between the two.',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function constantOf(string $class, string $name): array
{
    return (array) (new ReflectionClassConstant($class, $name))->getValue();
}

it('counts paragraphs and sentences', function () {
    expect(StoryText::paragraphs("One. Two.\n\nThree."))->toHaveCount(2)
        ->and(StoryText::sentences('Edge vs. John Cena drew 41,302 fans. He won! Did he? Yes.'))->toBe(4)
        ->and(StoryText::sentences('It cost $1.5 million, and more.'))->toBe(1)
        ->and(StoryText::fits('One. Two. Three. Four. Five.'))->toBeTrue()
        ->and(StoryText::fits('One. Two. Three. Four.'))->toBeFalse()
        ->and(StoryText::fits('One. Two. Three. Four. Five. Six. Seven. Eight. Nine.'))->toBeFalse()
        ->and(StoryText::fits(implode("\n\n", array_fill(0, 4, 'One. Two. Three. Four. Five.'))))->toBeFalse();
});

it('writes every news summary to the rule', function () {
    // Descriptions for /gm/docs; their real text comes from FallbackStories.
    $described = ['tickets_on_sale', 'brand_spotlight', 'superstar_recap', 'injury_report', 'locker_room_note'];

    foreach (constantOf(NewsTemplates::class, 'SUMMARIES') as $key => $template) {
        if (in_array($key, $described, true)) {
            continue;
        }

        foreach (fragmentSets() as $fragments) {
            expect(StoryText::problem(sampleFill($template, $fragments)))->toBeNull("news {$key}");
        }
    }
});

it('writes every promo and quiet-week story to the rule', function () {
    foreach ([PromoStories::class, FallbackStories::class] as $class) {
        foreach (constantOf($class, 'STORIES') as $key => $variants) {
            // Ticker lines have no summary; budget lines are part of a spotlight story.
            if (str_starts_with($key, 'locker.') || str_starts_with($key, 'budget.')) {
                continue;
            }

            foreach ($variants as $i => [, $summary]) {
                foreach (fragmentSets() as $fragments) {
                    expect(StoryText::problem(sampleFill($summary, $fragments)))->toBeNull("{$key}#{$i}");
                }
            }
        }
    }
});

it('appends a conditional story to a news summary as its own paragraph', function () {
    $summary = app(NewsTemplates::class)->summary(NewsEventType::UpsetWin, [
        'winner' => 'Finlay',
        'loser' => 'Edge',
        'aside_text' => ConditionalStoryTexts::render('hornswoggle_shillelagh.success', ['victim' => 'Edge', 'finlay' => 'Finlay']),
    ]);

    expect(StoryText::paragraphs($summary))->toHaveCount(2)
        ->and(StoryText::fits($summary))->toBeTrue();
});

it('writes every email body to the rule, joined pieces included', function () {
    $whole = array_filter(EmailTexts::keys(), fn (string $key): bool => ! in_array($key, ['injury.fresh', 'injury.escalated', 'morale.upset', 'morale.unsatisfied'], true)
        && ! str_starts_with($key, 'morale.'));

    foreach ($whole as $key) {
        foreach (EmailTexts::variants($key) as $i => $text) {
            expect(StoryText::problem(sampleFill($text)))->toBeNull("{$key}#{$i}");
        }
    }

    // The Trainer's report: what happened, then the outlook.
    foreach (['injury.fresh', 'injury.escalated'] as $what) {
        foreach (['injury.serious', 'injury.minor'] as $outlook) {
            foreach (EmailTexts::variants($what) as $a) {
                foreach (EmailTexts::variants($outlook) as $b) {
                    expect(StoryText::problem(sampleFill("{$a}\n\n{$b}")))->toBeNull("{$what} + {$outlook}");
                }
            }
        }
    }

    // A morale complaint, then how unhappy they are.
    foreach (['losing_streak', 'contract_extension', 'title_match', 'inactivity'] as $type) {
        foreach (['upset', 'unsatisfied'] as $level) {
            foreach (EmailTexts::variants("morale.{$type}") as $a) {
                foreach (EmailTexts::variants("morale.{$level}") as $b) {
                    expect(StoryText::problem(sampleFill("{$a}\n\n{$b}")))->toBeNull("morale.{$type} + {$level}");
                }
            }
        }
    }
});
