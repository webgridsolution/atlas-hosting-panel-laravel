<?php

namespace Tests\Unit;

use App\Domain\Licensing\LicenseKeyGenerator;
use PHPUnit\Framework\TestCase;

class LicenseKeyGeneratorTest extends TestCase
{
    public function test_key_matches_the_documented_format(): void
    {
        $key = (new LicenseKeyGenerator)->generate();

        $this->assertMatchesRegularExpression('/^ATLS(-[0-9A-HJKMNP-TV-Z]{4}){4}$/', $key);
        $this->assertSame(24, strlen($key));
    }

    public function test_keys_are_unique_across_many_generations(): void
    {
        $generator = new LicenseKeyGenerator;
        $keys = [];

        for ($i = 0; $i < 500; $i++) {
            $keys[] = $generator->generate();
        }

        $this->assertCount(500, array_unique($keys));
    }

    public function test_excluded_ambiguous_characters_never_appear(): void
    {
        $generator = new LicenseKeyGenerator;

        for ($i = 0; $i < 200; $i++) {
            $body = str_replace(['ATLS-', '-'], '', $generator->generate());

            foreach (['I', 'L', 'O', 'U'] as $ambiguous) {
                $this->assertStringNotContainsString($ambiguous, $body);
            }
        }
    }

    public function test_normalisation_repairs_common_typos(): void
    {
        $normalised = (new LicenseKeyGenerator)->normalise('atls 7k2q m1o0 i1l2 abcd ');

        $this->assertSame('ATLS-7K2Q-M100-1112-ABCD', $normalised);
    }
}
