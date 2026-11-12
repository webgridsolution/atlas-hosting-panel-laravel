<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The compiled asset manifest is a build artifact and is not committed;
        // tests assert on markup, not on asset URLs, so Vite is stubbed out.
        $this->withoutVite();
    }
}
