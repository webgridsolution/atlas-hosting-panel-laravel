<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The only unit test in this application, and it earns its place: the panel has
 * no framework-independent logic worth unit-testing (everything talks to the
 * database, the agent or the licence), so feature tests carry the weight. This
 * test exists so the Unit suite is a real suite rather than an empty directory
 * that PHPUnit refuses to run.
 */
class Smoke extends TestCase
{
    public function test_the_unit_suite_runs(): void
    {
        $this->assertTrue(true);
    }
}
