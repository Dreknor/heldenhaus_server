<?php

namespace Tests;

use App\Domain\Events\EventCatalog;
use App\Domain\Features\Features;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Überraschungen sind zufällig – in Tests aus, außer ein Test schaltet sie gezielt ein.
        config(['game.surprise.base_chance' => 0, 'game.surprise.pity_step' => 0]);

        // Freischaltungen nach Level (§13) und Zufallsereignisse (§12.5) nur dort, wo ein Test sie gezielt prüft
        config(['game.features.enforce' => false, 'game.fun_events.enabled' => false]);
        Features::forget();
        EventCatalog::flush();
    }
}
