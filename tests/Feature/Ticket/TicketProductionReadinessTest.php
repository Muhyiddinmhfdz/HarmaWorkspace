<?php

namespace Tests\Feature\Ticket;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Enums\MasterScope;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketRejectionReason;
use App\Models\TicketRelationType;
use App\Models\TicketResolution;
use App\Models\TicketSource;
use App\Models\TicketSubcategory;
use App\Models\TicketTransferReason;
use App\Models\TicketType;
use Database\Seeders\Development\DevelopmentSeeder;
use Database\Seeders\System\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

/**
 * A fresh production install (migrations + SystemSeeder only, never the
 * DevelopmentSeeder) must be able to create tickets right away, and the
 * SystemSeeder must never overwrite or reactivate what administrators chose.
 */
class TicketProductionReadinessTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_system_seeder_contains_required_production_ticket_masters(): void
    {
        $this->assertTrue(TicketType::query()->active()->where('code', 'GENERAL')->exists());
        $this->assertTrue(TicketCategory::query()->active()->where('code', 'GENERAL')->exists());
        $this->assertTrue(Status::query()->forScope(MasterScope::Ticket)->active()->where('is_initial', true)->exists());
        $this->assertSame(4, Priority::query()->forScope(MasterScope::Ticket)->active()->count());
        $this->assertTrue(TicketResolution::query()->active()->exists());
        $this->assertTrue(TicketTransferReason::query()->active()->exists());
        $this->assertTrue(TicketRejectionReason::query()->active()->exists());
        $this->assertTrue(TicketRelationType::query()->active()->where('is_duplicate', true)->exists());

        // Optional, organization-configurable catalogues stay empty.
        $this->assertSame(0, TicketSubcategory::query()->count());
        $this->assertSame(0, TicketSource::query()->count());
        $this->assertSame(1, TicketType::query()->count(), 'No demo types on a production install');
    }

    public function test_fresh_installation_can_create_a_ticket_without_development_data(): void
    {
        $department = Department::factory()->create(['name' => 'Technical Support']);
        $user = $this->member($department);
        $type = TicketType::query()->where('code', 'GENERAL')->sole();
        $category = TicketCategory::query()->where('code', 'GENERAL')->sole();

        $this->actingAs($user);

        $page = Livewire::test('pages::tickets.create')
            ->assertOk()
            ->assertDontSee('belum tersedia');

        $this->assertGreaterThanOrEqual(1, $page->instance()->options['types']->count());
        $this->assertGreaterThanOrEqual(1, $page->instance()->options['categories']->count());
        $this->assertTrue($page->instance()->mastersReady());

        $page->set('title', 'Akses printer lantai 2')
            ->set('ticket_type_id', (string) $type->id)
            ->set('ticket_category_id', (string) $category->id)
            ->set('current_department_id', $department->id)
            ->set('description', 'Printer tidak bisa dipakai sejak pagi.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $ticket = Ticket::query()->sole();
        $this->assertNull($ticket->ticket_subcategory_id, 'Subcategory stays optional');
        $this->assertNull($ticket->ticket_source_id, 'Source stays optional');
        $this->assertSame($user->id, $ticket->requester_user_id);
    }

    public function test_create_page_explains_missing_masters_instead_of_crashing(): void
    {
        TicketType::query()->update(['is_active' => false]);
        TicketCategory::query()->update(['is_active' => false]);

        $this->actingAs($this->staff());

        $page = Livewire::test('pages::tickets.create')
            ->assertOk()
            ->assertSee('Master Ticket Type belum tersedia.')
            ->assertSee('Master Ticket Category belum tersedia.')
            ->assertSee('Hubungi administrator untuk mengatur Master Data Ticket.');

        $this->assertFalse($page->instance()->mastersReady());

        $page->set('title', 'Test')->set('description', 'Test')->set('current_department_id', Department::factory()->create()->id)
            ->call('save')
            ->assertHasErrors(['ticket_type_id' => 'required', 'ticket_category_id' => 'required']);

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_backend_rejects_inactive_type_and_category(): void
    {
        $type = TicketType::query()->where('code', 'GENERAL')->sole();
        $category = TicketCategory::query()->where('code', 'GENERAL')->sole();

        $type->forceFill(['is_active' => false])->save();

        try {
            $this->createTicket(['ticket_type_id' => $type->id, 'ticket_category_id' => $category->id]);
            $this->fail('Inactive type must be rejected.');
        } catch (BusinessRuleException) {
        }

        $type->forceFill(['is_active' => true])->save();
        $category->forceFill(['is_active' => false])->save();

        $this->expectException(BusinessRuleException::class);
        $this->createTicket(['ticket_type_id' => $type->id, 'ticket_category_id' => $category->id]);
    }

    public function test_rerunning_system_seeder_keeps_admin_customization_and_never_duplicates(): void
    {
        $type = TicketType::query()->where('code', 'GENERAL')->sole();
        $type->forceFill(['name' => 'General Support Request', 'color' => 'teal', 'sort_order' => 99, 'is_active' => false])->save();
        TicketCategory::query()->where('code', 'GENERAL')->sole()->forceFill(['name' => 'Umum'])->save();
        $counts = [TicketType::query()->count(), TicketCategory::query()->count(), TicketResolution::query()->count(), Status::query()->count(), Priority::query()->count()];

        $this->seed(SystemSeeder::class);
        $this->seed(SystemSeeder::class);

        $type->refresh();
        $this->assertSame('General Support Request', $type->name);
        $this->assertSame('teal', $type->color);
        $this->assertSame(99, $type->sort_order);
        $this->assertFalse($type->is_active, 'A default deactivated by the admin is not reactivated');
        $this->assertSame('Umum', TicketCategory::query()->where('code', 'GENERAL')->value('name'));
        $this->assertSame($counts, [TicketType::query()->count(), TicketCategory::query()->count(), TicketResolution::query()->count(), Status::query()->count(), Priority::query()->count()]);
    }

    public function test_system_seeder_on_existing_phase_4_data_changes_nothing(): void
    {
        $this->superAdmin();
        $this->seed(DevelopmentSeeder::class);
        TicketType::query()->where('code', 'BUG')->sole()->forceFill(['name' => 'Defect'])->save();

        $snapshot = fn (): array => [
            Ticket::query()->orderBy('id')->get(['id', 'number', 'status_id', 'current_department_id', 'ticket_type_id', 'ticket_category_id'])->toArray(),
            TicketType::query()->orderBy('id')->get(['id', 'code', 'name', 'is_active'])->toArray(),
            TicketCategory::query()->orderBy('id')->get(['id', 'code', 'name', 'is_active'])->toArray(),
            TicketSubcategory::query()->count(),
            TicketSource::query()->count(),
        ];
        $before = $snapshot();

        $this->seed(SystemSeeder::class);

        $this->assertSame($before, $snapshot());
        $this->assertSame('Defect', TicketType::query()->where('code', 'BUG')->value('name'));
    }

    public function test_project_manager_visibility_is_not_a_management_bypass(): void
    {
        $pm = $this->staff();
        $project = $this->createProject(['project_manager_id' => $pm->id]);
        $department = Department::factory()->create();
        $pic = $this->member($department);
        $queued = $this->createTicket(['project_id' => $project->id, 'current_department_id' => $department->id], $this->superAdmin());
        $review = $this->ticketInReview($department, $pic, ['project_id' => $project->id]);

        $this->assertTrue($queued->isVisibleTo($pm));

        foreach (['accept', 'reject', 'assign', 'transfer', 'changePriority', 'manageDeadline', 'review', 'close', 'cancel', 'work'] as $ability) {
            $this->assertFalse($pm->can($ability, $queued), 'PM must not get '.$ability);
            $this->assertFalse($pm->can($ability, $review), 'PM must not get '.$ability);
        }

        $this->actingAs($pm);
        Livewire::test('pages::tickets.show', ['ticket' => $queued])->assertSet('actions.primary', null)->call('perform', 'accept')->assertForbidden();
        Livewire::test('pages::tickets.show', ['ticket' => $review])->call('perform', 'resolve')->assertForbidden();

        app(ChangeTicketStatus::class)->resolve($review, $this->resolution()->id, 'Selesai', $this->superAdmin());
        $this->assertFalse($pm->can('close', $review->refresh()));
        $this->assertNull($queued->refresh()->accepted_at);
    }
}
