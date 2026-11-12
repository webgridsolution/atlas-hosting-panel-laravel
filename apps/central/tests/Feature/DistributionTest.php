<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\License;
use App\Models\Plan;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * How a build reaches a customer, and what happens when it cannot.
 *
 * These are the surfaces a customer touches before they own anything: the
 * install command on the marketing page, the download endpoint behind it, and
 * the redirect after signing in. Each one has a failure mode that looks like
 * "the product is broken" if it is wrong.
 */
class DistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles, plans and features are the vocabulary every screen is built
        // from; a test that invents them would not be testing the real thing.
        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class, TaxRateSeeder::class]);
    }

    public function test_the_installer_script_is_served_as_a_real_shell_script(): void
    {
        $response = $this->get('/install.sh');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');

        $body = $response->getContent();

        $this->assertStringStartsWith('#!/bin/sh', $body);
        // The origin is baked in at request time, so the command a visitor
        // copies points at this installation and not at a vendor placeholder.
        $this->assertStringContainsString('BASE_DEFAULT="'.url('/').'"', $body);
        $this->assertStringNotContainsString('__ATLAS_BASE__', $body);
        $this->assertStringNotContainsString('example.com', $body);
    }

    public function test_the_installer_refuses_an_unvalidated_platform(): void
    {
        $body = $this->get('/install.sh')->getContent();

        // The guard is in the script; assert it exists rather than pretending
        // to run a root installer inside a test.
        $this->assertStringContainsString('ubuntu:22.04|ubuntu:24.04', $body);
        $this->assertStringContainsString('Refusing to install', $body);
        $this->assertStringContainsString('sha256sum -c', $body);
    }

    public function test_release_manifest_is_available_as_tsv_for_the_shell_installer(): void
    {
        $release = Release::create([
            'component' => 'panel', 'version' => '9.9.9', 'channel' => 'stable',
            'is_published' => true, 'published_at' => now(),
        ]);
        $release->artifacts()->create([
            'os' => 'ubuntu-24.04', 'arch' => 'amd64', 'filename' => 'atlas-9.9.9.tar.gz',
            'size_bytes' => 1024, 'sha256' => str_repeat('ab', 32),
        ]);

        $response = $this->get('/api/v1/releases/latest?format=tsv');

        $response->assertOk();
        $this->assertStringContainsString(
            "ubuntu-24.04\tamd64\tatlas-9.9.9.tar.gz\t".str_repeat('ab', 32),
            $response->getContent(),
        );
        // Six columns per row: os, arch, filename, sha256, size, url. The url
        // column may be empty (which the installer treats as "serve it from
        // this site"), so only the newline is trimmed — trimming the tab too
        // would silently shift every downstream column.
        $row = rtrim($response->getContent(), "\n");
        $this->assertCount(6, explode("\t", $row));
    }

    public function test_the_download_page_lists_nothing_until_a_build_is_published(): void
    {
        $user = $this->customerUser();

        // A draft release must not show up as if it were downloadable.
        $release = Release::create([
            'component' => 'panel', 'version' => '9.9.8', 'channel' => 'stable',
            'is_published' => false,
        ]);
        $release->artifacts()->create([
            'os' => 'ubuntu-24.04', 'arch' => 'amd64', 'filename' => 'draft.tar.gz',
            'size_bytes' => 10, 'sha256' => str_repeat('cd', 32),
        ]);

        $this->actingAs($user)->get('/downloads')
            ->assertOk()
            ->assertSee('No builds published yet')
            ->assertDontSee('draft.tar.gz');
    }

    public function test_a_missing_build_says_so_instead_of_linking_to_a_404(): void
    {
        Storage::fake(config('atlas.downloads.disk', 'local'));
        $user = $this->customerUser();
        $this->grantLicence($user);

        $artifact = $this->publishedArtifact();

        $this->actingAs($user)->get(route('downloads.get', $artifact))
            ->assertRedirect(route('downloads.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('download_logs', ['artifact_id' => $artifact->id]);
    }

    public function test_a_stored_build_is_streamed_with_the_right_filename(): void
    {
        Storage::fake(config('atlas.downloads.disk', 'local'));
        $user = $this->customerUser();
        $this->grantLicence($user);

        $artifact = $this->publishedArtifact();
        Storage::disk(config('atlas.downloads.disk', 'local'))->put(
            trim((string) config('atlas.downloads.path', 'downloads'), '/').'/'.$artifact->filename,
            'build-bytes',
        );

        $response = $this->actingAs($user)->get(route('downloads.get', $artifact));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename='.$artifact->filename);
        $this->assertSame(1, $artifact->fresh()->download_count);
    }

    public function test_a_download_requires_an_active_licence(): void
    {
        Storage::fake(config('atlas.downloads.disk', 'local'));
        $user = $this->customerUser();
        $artifact = $this->publishedArtifact();

        $this->actingAs($user)->get(route('downloads.get', $artifact))->assertForbidden();
    }

    public function test_staff_opening_the_login_page_land_in_the_control_plane(): void
    {
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $staff->assignRole('super_admin');

        // RedirectIfAuthenticated must not send staff to /dashboard, which is a
        // customer-only screen and answers 403.
        $this->actingAs($staff)->get('/login')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($staff)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_release_with_no_deliverable_build_cannot_be_published(): void
    {
        Storage::fake(config('atlas.downloads.disk', 'local'));

        $staff = User::factory()->create(['email_verified_at' => now()]);
        $staff->assignRole('super_admin');

        $release = Release::create([
            'component' => 'panel', 'version' => '9.9.6', 'channel' => 'stable',
            'is_published' => false,
        ]);
        $release->artifacts()->create([
            'os' => 'ubuntu-24.04', 'arch' => 'amd64', 'filename' => 'not-uploaded.tar.gz',
            'size_bytes' => 10, 'sha256' => str_repeat('aa', 32),
        ]);

        $this->actingAs($staff)
            ->patch(route('admin.releases.publish', $release))
            ->assertSessionHas('error');

        $this->assertFalse($release->fresh()->is_published);
    }

    public function test_publishing_succeeds_once_the_file_is_on_the_disk(): void
    {
        Storage::fake(config('atlas.downloads.disk', 'local'));

        $staff = User::factory()->create(['email_verified_at' => now()]);
        $staff->assignRole('super_admin');

        $release = Release::create([
            'component' => 'panel', 'version' => '9.9.5', 'channel' => 'stable',
            'is_published' => false,
        ]);
        $artifact = $release->artifacts()->create([
            'os' => 'ubuntu-24.04', 'arch' => 'amd64', 'filename' => 'real.tar.gz',
            'size_bytes' => 10, 'sha256' => str_repeat('bb', 32),
        ]);
        Storage::disk(config('atlas.downloads.disk', 'local'))->put(
            trim((string) config('atlas.downloads.path', 'downloads'), '/').'/'.$artifact->filename,
            'bytes',
        );

        $this->actingAs($staff)
            ->patch(route('admin.releases.publish', $release))
            ->assertSessionHas('status');

        $this->assertTrue($release->fresh()->is_published);
        $this->assertNotNull($release->fresh()->published_at);
    }

    private function customerUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('customer');
        Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        return $user;
    }

    private function publishedArtifact(): ReleaseArtifact
    {
        $release = Release::create([
            'component' => 'panel', 'version' => '9.9.7', 'channel' => 'stable',
            'is_published' => true, 'published_at' => now(),
        ]);

        return $release->artifacts()->create([
            'os' => 'ubuntu-24.04', 'arch' => 'amd64', 'filename' => 'atlas-9.9.7.tar.gz',
            'size_bytes' => 11, 'sha256' => str_repeat('ef', 32),
        ]);
    }

    private function grantLicence(User $user): void
    {
        License::create([
            'customer_id' => $user->customer->id,
            'plan_id' => Plan::query()->value('id'),
            'public_id' => (string) Str::ulid(),
            'key' => 'ATLS-'.strtoupper(Str::random(4)).'-TEST',
            'status' => 'active',
            'activation_limit' => 1,
            'activated_at' => now(),
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
    }
}
