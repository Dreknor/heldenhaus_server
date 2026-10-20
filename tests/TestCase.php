<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Überraschungen sind zufällig – in Tests aus, außer ein Test schaltet sie gezielt ein.
        config(['game.surprise.base_chance' => 0, 'game.surprise.pity_step' => 0]);
    }
}
