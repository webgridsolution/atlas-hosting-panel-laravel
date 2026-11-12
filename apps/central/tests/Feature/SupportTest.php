<?php

namespace Tests\Feature;

use App\Domain\Licensing\IssueLicense;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;

    private User $customerUser;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class]);

        $this->customerUser = $this->makeCustomer('rohit@example.com');
        $this->otherUser = $this->makeCustomer('meera@example.com');
    }

    private function makeCustomer(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $user->assignRole('customer');
        Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        return $user->fresh();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);
        Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        return $user->fresh();
    }

    private function openTicket(User $user, array $overrides = []): SupportTicket
    {
        $this->actingAs($user)->post('/support', array_merge([
            'subject' => 'Installer fails at step 4',
            'category' => 'installation',
            'priority' => 'high',
            'body' => 'The installer downloads the panel package but then reports a checksum mismatch on Ubuntu 24.04.',
        ], $overrides));

        return SupportTicket::latest('id')->firstOrFail();
    }

    public function test_customer_can_open_a_ticket_with_the_first_message(): void
    {
        $ticket = $this->openTicket($this->customerUser);

        $this->assertSame('open', $ticket->status);
        $this->assertStringStartsWith('TKT-', $ticket->number);
        $this->assertSame(1, $ticket->replies()->count());
        $this->assertFalse($ticket->replies()->first()->is_staff);
    }

    public function test_ticket_requires_a_meaningful_body(): void
    {
        $this->actingAs($this->customerUser)->post('/support', [
            'subject' => 'Hi', 'category' => 'general', 'priority' => 'low', 'body' => 'help',
        ])->assertSessionHasErrors(['subject', 'body']);

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_customer_sees_their_ticket_thread(): void
    {
        $ticket = $this->openTicket($this->customerUser);

        $this->actingAs($this->customerUser)->get('/support')->assertOk()->assertSee($ticket->number);
        $this->actingAs($this->customerUser)->get('/support/'.$ticket->public_id)->assertOk()
            ->assertSee('Installer fails at step 4');
    }

    public function test_customer_cannot_open_another_customers_ticket(): void
    {
        $ticket = $this->openTicket($this->customerUser);

        $this->actingAs($this->otherUser)->get('/support/'.$ticket->public_id)->assertForbidden();
        $this->actingAs($this->otherUser)->post('/support/'.$ticket->public_id.'/reply', ['body' => 'hi'])
            ->assertForbidden();
    }

    public function test_customer_reply_reopens_a_pending_ticket(): void
    {
        $ticket = $this->openTicket($this->customerUser);
        $ticket->update(['status' => 'pending']);

        $this->actingAs($this->customerUser)
            ->post('/support/'.$ticket->public_id.'/reply', ['body' => 'Tried that, still failing on the second run.'])
            ->assertRedirect();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(2, $ticket->replies()->count());
    }

    public function test_closed_ticket_rejects_further_customer_replies(): void
    {
        $ticket = $this->openTicket($this->customerUser);
        $ticket->update(['status' => 'closed']);

        $this->actingAs($this->customerUser)
            ->post('/support/'.$ticket->public_id.'/reply', ['body' => 'One more thing.'])
            ->assertSessionHasErrors('body');

        $this->assertSame(1, $ticket->replies()->count());
    }

    public function test_support_agent_can_reply_and_triage(): void
    {
        $ticket = $this->openTicket($this->customerUser);
        $agent = $this->staff('support');

        $this->actingAs($agent)->get('/admin/tickets')->assertOk()->assertSee($ticket->number);
        $this->actingAs($agent)->get('/admin/tickets/'.$ticket->public_id)->assertOk();

        $this->actingAs($agent)
            ->post('/admin/tickets/'.$ticket->public_id.'/reply', ['body' => 'Please send the full installer log.'])
            ->assertRedirect();

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertTrue($ticket->replies()->latest('id')->first()->is_staff);

        $this->actingAs($agent)->patch('/admin/tickets/'.$ticket->public_id, [
            'status' => 'resolved', 'priority' => 'urgent', 'assigned_to' => $agent->id,
        ])->assertRedirect();

        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->closed_at);
    }

    public function test_finance_role_cannot_touch_the_support_queue(): void
    {
        $ticket = $this->openTicket($this->customerUser);
        $finance = $this->staff('finance');

        $this->actingAs($finance)->get('/admin/tickets')->assertForbidden();
        $this->actingAs($finance)->get('/admin/tickets/'.$ticket->public_id)->assertForbidden();
        $this->actingAs($finance)->post('/admin/tickets/'.$ticket->public_id.'/reply', ['body' => 'hi'])
            ->assertForbidden();
    }

    public function test_customer_cannot_perform_staff_triage(): void
    {
        $ticket = $this->openTicket($this->customerUser);

        $this->actingAs($this->customerUser)->patch('/admin/tickets/'.$ticket->public_id, [
            'status' => 'closed', 'priority' => 'low',
        ])->assertForbidden();
    }

    public function test_ticket_reply_requires_a_body(): void
    {
        $ticket = $this->openTicket($this->customerUser);

        $this->actingAs($this->customerUser)
            ->post('/support/'.$ticket->public_id.'/reply', ['body' => ''])
            ->assertSessionHasErrors('body');
    }

    public function test_customer_can_attach_their_own_licence_but_not_someone_elses(): void
    {
        $plan = Plan::where('slug', 'professional')->firstOrFail();
        $otherLicense = app(IssueLicense::class)
            ->handle($this->otherUser->customer, $plan);

        $this->actingAs($this->customerUser)->post('/support', [
            'subject' => 'Licence question',
            'category' => 'licensing',
            'priority' => 'normal',
            'license_id' => $otherLicense->id,
            'body' => 'I need help understanding what happens after my licence expires next month.',
        ])->assertForbidden();
    }
}
