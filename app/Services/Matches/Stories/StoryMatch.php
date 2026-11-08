<?php

namespace App\Services\Matches\Stories;

use App\Enums\Brand;
use App\Enums\MatchFamily;
use App\Models\MatchSlot;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Week\WeekContext;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * The match a conditional story looks at, with what every story needs.
 */
final class StoryMatch
{
    /** @var array<int, SaveWrestler>|null */
    private ?array $people = null;

    /**
     * @param  Collection<int, Rivalry>  $rivalries  active going into the match
     */
    public function __construct(
        public readonly MatchSlot $slot,
        public readonly Show $show,
        public readonly WeekContext $context,
        public readonly Collection $rivalries,
    ) {}

    public function save(): Save
    {
        return $this->context->save;
    }

    public function week(): int
    {
        return $this->context->week;
    }

    /**
     * @return array<int, list<int>>
     */
    public function sides(): array
    {
        return $this->slot->sides();
    }

    public function sideOf(int $id): ?int
    {
        foreach ($this->sides() as $side => $ids) {
            if (in_array($id, $ids, true)) {
                return $side;
            }
        }

        return null;
    }

    /**
     * Everyone with a part in the match: competitors, managers, a referee.
     *
     * @return list<int>
     */
    public function participantIds(): array
    {
        return $this->slot->participantIds();
    }

    /**
     * The match's brand, or a wrestler's for an interbrand card.
     */
    public function brandOf(int $id): ?Brand
    {
        return $this->person($id)->brand ?? $this->slot->owner_brand ?? $this->show->brand;
    }

    public function family(): ?MatchFamily
    {
        return $this->slot->matchType?->family;
    }

    public function hasDqs(): bool
    {
        return (bool) $this->slot->matchType?->dq_enabled;
    }

    /**
     * "match", or "bout" in a Gauntlet (the story plays out in one bout).
     */
    public function matchWord(): string
    {
        return $this->family() === MatchFamily::Gauntlet ? 'bout' : 'match';
    }

    public function rng(string $what): Rng
    {
        return $this->context->rng("story.{$what}.{$this->show->streamKey()}.{$this->slot->position}");
    }

    public function person(int $id): ?SaveWrestler
    {
        if ($this->people === null) {
            $this->people = [];

            foreach ($this->slot->participants as $p) {
                $this->people[(int) $p->save_wrestler_id] = $p->saveWrestler;
            }
        }

        return $this->people[$id] ?? SaveWrestler::with('wrestler')->find($id);
    }

    public function name(int $id): string
    {
        return $this->person($id)?->name() ?? '?';
    }

    /**
     * {prefix_he}, {prefix_He}, {prefix_him}, {prefix_his} for a person.
     *
     * @return array<string, string>
     */
    public function pronouns(string $prefix, int $id): array
    {
        $diva = (bool) $this->person($id)?->wrestler->isDiva();

        return [
            "{$prefix}_he" => $diva ? 'she' : 'he',
            "{$prefix}_He" => $diva ? 'She' : 'He',
            "{$prefix}_him" => $diva ? 'her' : 'him',
            "{$prefix}_his" => $diva ? 'her' : 'his',
        ];
    }
}
