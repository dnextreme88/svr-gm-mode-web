<?php

use App\Enums\Brand;
use App\Enums\BrandScope;
use App\Enums\ChampionshipEligibility;
use App\Enums\ChampionshipKey;
use App\Enums\ComplaintLevel;
use App\Enums\ContractLength;
use App\Enums\DraftPhase;
use App\Enums\DraftPickSource;
use App\Enums\DraftPickType;
use App\Enums\EmailTemplate;
use App\Enums\FinishEvent;
use App\Enums\InjurySeverity;
use App\Enums\MatchFamily;
use App\Enums\MatchMethod;
use App\Enums\MitbStatus;
use App\Enums\Morale;
use App\Enums\MoraleComplaintType;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsSlot;
use App\Enums\NewsTier;
use App\Enums\PpvKey;
use App\Enums\PromoTypeKey;
use App\Enums\ReignEndReason;
use App\Enums\RosterType;
use App\Enums\Stipulation;
use App\Enums\TitleHolderType;
use App\Enums\TitleShotStatus;
use App\Enums\TradeStatus;
use App\Enums\WeightClass;
use App\Enums\WonVia;

/*
| Enum values must match the spec exactly, since they're persisted and
| mirrored into TypeScript.
*/

dataset('spec enums', [
    'Brand (§1)' => [Brand::class, ['raw', 'smackdown']],
    'BrandScope (§13)' => [BrandScope::class, ['raw', 'smackdown', 'shared']],
    'RosterType (§11)' => [RosterType::class, ['superstar', 'diva', 'legend']],
    'WeightClass (§12)' => [WeightClass::class, ['featherweight', 'cruiserweight', 'light_heavyweight', 'heavyweight', 'super_heavyweight', 'ultra_heavyweight']],
    'Morale (§15)' => [Morale::class, ['happy', 'content', 'unsatisfied', 'upset']],
    'ComplaintLevel (§11)' => [ComplaintLevel::class, ['content', 'unsatisfied', 'upset']],
    'MoraleComplaintType (§11)' => [MoraleComplaintType::class, ['losing_streak', 'contract_extension', 'title_match', 'inactivity']],
    'InjurySeverity (§6)' => [InjurySeverity::class, ['minor', 'serious']],
    'ContractLength (§2)' => [ContractLength::class, [5, 12, 24, 36, 52]],
    'Stipulation (§22)' => [Stipulation::class, ['champion_vs_contender', 'loser_leaves_show', 'last_chance']],
    'MatchMethod (§20)' => [MatchMethod::class, ['pinfall', 'submission', 'countout', 'dq', 'match_specific']],
    'FinishEvent (§20)' => [FinishEvent::class, ['referee_attacked', 'rival_interference', 'foreign_object', 'countout', 'cheap_rollup']],
    'TitleHolderType (§16)' => [TitleHolderType::class, ['wrestler', 'tag_team', 'vacant']],
    'WonVia (§16)' => [WonVia::class, ['match', 'awarded', 'vacancy_match', 'mitb_cash_in']],
    'ReignEndReason (§16)' => [ReignEndReason::class, ['active', 'lost_match', 'defected', 'injured', 'loser_leaves', 'released', 'contract_expired']],
    'MitbStatus (§11)' => [MitbStatus::class, ['held', 'cashed_in_won', 'cashed_in_lost', 'expired', 'voided']],
    'TitleShotStatus (§22)' => [TitleShotStatus::class, ['pending', 'fulfilled', 'voided']],
    'TradeStatus (§11)' => [TradeStatus::class, ['proposed', 'accepted', 'rejected', 'invalid']],
    'DraftPhase (§11)' => [DraftPhase::class, ['initial', 'wrestlemania_reset']],
    'DraftPickSource (§11)' => [DraftPickSource::class, ['free_agency', 'rival_roster']],
    'DraftPickType (§11)' => [DraftPickType::class, ['manual', 'random']],
    'NewsTier (§19)' => [NewsTier::class, ['headline', 'story', 'ticker']],
    'NewsSlot (§19)' => [NewsSlot::class, ['raw', 'smackdown', 'neutral']],
    'NewsBrand (§19)' => [NewsBrand::class, ['raw', 'smackdown', 'both']],
    'ChampionshipEligibility (§11)' => [ChampionshipEligibility::class, ['open', 'divas_only', 'cruiserweight_or_light_heavyweight']],
]);

it('matches the spec values', function (string $enum, array $expected) {
    expect($enum::values())->toBe($expected);
})->with('spec enums');

it('covers all 8 championships, 14 promos and 15 PPVs', function () {
    expect(ChampionshipKey::cases())->toHaveCount(8)
        ->and(PromoTypeKey::cases())->toHaveCount(14)
        ->and(PpvKey::cases())->toHaveCount(15);
});

it('lists the full §19 news catalog', function () {
    expect(NewsEventType::values())->toHaveCount(52)
        ->toContain('title_change', 'retained_by_dq', 'mitb_cash_in', 'interbrand_series_result', 'defection_blocked', 'season_reset', 'match_result', 'main_event_hype_result', 'promo_result', 'contract_expiring', 'rivalry_milestone');
});

it('covers every specialty family named in §21', function () {
    foreach (['tlc', 'ladder', 'hardcore', 'submission', 'iron_man', 'hell_in_a_cell', 'first_blood'] as $family) {
        expect(MatchFamily::tryFrom($family))->not->toBeNull();
    }
});

it('includes the core email templates from §11', function () {
    expect(EmailTemplate::values())->toContain('hint', 'expiring_contracts', 'contract_expired', 'injury', 'morale_complaint', 'defection_attempt');
});

it('gives every case a non-empty label', function () {
    expect(Brand::Smackdown->label())->toBe('Smackdown')
        ->and(ChampionshipKey::Womens->label())->toBe("Women's Championship")
        ->and(ContractLength::OneYear->label())->toBe('1 year')
        ->and(WeightClass::LightHeavyweight->label())->toBe('Light Heavyweight');
});
