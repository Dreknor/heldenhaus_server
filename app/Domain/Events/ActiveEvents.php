<?php

namespace App\Domain\Events;

use App\Models\FamilyEvent;
use Illuminate\Support\Collection;

/** Zwischenspeicher der laufenden Events je Familie (pro Anfrage bzw. Job, §12.6). */
class ActiveEvents
{
    /** @var array<int, Collection<int, FamilyEvent>> */
    private array $running = [];

    /** @return Collection<int, FamilyEvent> */
    public function for(int $familyId): Collection
    {
        return $this->running[$familyId] ??= FamilyEvent::where('family_id', $familyId)->running()->where('ends_at', '>', now())->get();
    }

    public function forget(?int $familyId = null): void
    {
        if ($familyId === null) {
            $this->running = [];
        } else {
            unset($this->running[$familyId]);
        }
    }
}
