<?php

namespace Tests\Feature\Organization;

use App\Domain\Organization\Actions\ChangeUserStatus;
use App\Domain\Organization\Actions\SyncUserMemberships;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Position;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_user_with_role_and_multiple_departments(): void
    {
        $programmer = Department::factory()->create();
        $qa = Department::factory()->create();
        $position = Position::factory()->create();

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.form')
            ->set('name', 'Andi Programmer')
            ->set('username', 'Andi.Programmer')
            ->set('email', 'Andi@Harma-Digital.com')
            ->set('password', 'secret-pass-123')
            ->set('password_confirmation', 'secret-pass-123')
            ->set('role', 'Staff')
            ->set('memberships', [
                ['department_id' => (string) $programmer->id, 'position_id' => (string) $position->id, 'is_leader' => true],
                ['department_id' => (string) $qa->id, 'position_id' => '', 'is_leader' => false],
            ])
            ->set('primaryRow', 1)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $user = User::query()->where('username', 'andi.programmer')->firstOrFail();
        $this->assertSame('andi@harma-digital.com', $user->email);

        $this->assertTrue($user->hasRole('Staff'));
        $this->assertTrue($user->is_active);
        $this->assertCount(2, $user->activeMemberships);
        $this->assertSame($qa->id, $user->primaryMembership->department_id);
        $this->assertTrue($user->leadsDepartment($programmer));
        $this->assertFalse($user->leadsDepartment($qa));
        $this->assertTrue(Activity::query()->where('event', 'user.created')->where('subject_id', $user->id)->exists());
    }

    public function test_username_must_be_unique(): void
    {
        User::factory()->create(['username' => 'taken']);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.form')
            ->set('name', 'Someone')
            ->set('username', 'TAKEN')
            ->set('password', 'secret-pass-123')
            ->set('password_confirmation', 'secret-pass-123')
            ->set('memberships', [])
            ->call('save')
            ->assertHasErrors(['username' => 'unique']);
    }

    public function test_reset_link_requires_an_email(): void
    {
        $target = User::factory()->withRole('Staff')->create(['email' => null]);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.show', ['user' => $target])->call('sendResetLink');

        $this->assertFalse(Activity::query()->where('event', 'user.password_reset_link_sent')->exists());
    }

    public function test_password_is_never_written_to_the_audit_log(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.form')
            ->set('name', 'Budi')
            ->set('username', 'budi')
            ->set('email', '')
            ->set('password', 'secret-pass-123')
            ->set('password_confirmation', 'secret-pass-123')
            ->set('role', 'Staff')
            ->set('memberships', [])
            ->call('save')
            ->assertHasNoErrors();

        $log = Activity::query()->where('event', 'user.created')->firstOrFail();
        $payload = json_encode([$log->attribute_changes, $log->properties]);

        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('remember_token', $payload);
    }

    public function test_user_form_validates_input(): void
    {
        User::factory()->create(['username' => 'taken', 'email' => 'taken@harma-digital.com']);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.form')
            ->set('name', '')
            ->set('username', 'Bad Name!')
            ->set('email', 'taken@harma-digital.com')
            ->set('password', 'short')
            ->set('password_confirmation', 'different')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'username' => 'regex', 'email' => 'unique', 'password']);
    }

    public function test_only_one_active_primary_department_per_user_is_enforced_by_the_database(): void
    {
        $user = User::factory()->create();
        DepartmentMembership::factory()->primary()->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);

        DepartmentMembership::factory()->primary()->create(['user_id' => $user->id]);
    }

    public function test_duplicate_active_membership_is_enforced_by_the_database(): void
    {
        $membership = DepartmentMembership::factory()->create();

        $this->expectException(QueryException::class);

        DepartmentMembership::factory()->create([
            'user_id' => $membership->user_id,
            'department_id' => $membership->department_id,
        ]);
    }

    public function test_ended_membership_keeps_history_and_allows_rejoining(): void
    {
        $user = User::factory()->create();
        $department = Department::factory()->create();
        $sync = app(SyncUserMemberships::class);

        $sync->handle($user, [['department_id' => $department->id, 'is_primary' => true]]);
        $sync->handle($user, []);

        $this->assertSame(0, $user->activeMemberships()->count());
        $this->assertSame(1, $user->memberships()->whereNotNull('ended_at')->count());

        $sync->handle($user, [['department_id' => $department->id, 'is_primary' => true]]);

        $this->assertSame(1, $user->activeMemberships()->count());
        $this->assertSame(2, $user->memberships()->count());
    }

    public function test_switching_primary_department_keeps_exactly_one_primary(): void
    {
        $user = User::factory()->create();
        [$first, $second] = Department::factory()->count(2)->create();
        $sync = app(SyncUserMemberships::class);

        $sync->handle($user, [
            ['department_id' => $first->id, 'is_primary' => true],
            ['department_id' => $second->id, 'is_primary' => false],
        ]);
        $sync->handle($user, [
            ['department_id' => $first->id, 'is_primary' => false],
            ['department_id' => $second->id, 'is_primary' => true],
        ]);

        $this->assertSame(1, $user->activeMemberships()->where('is_primary', true)->count());
        $this->assertSame($second->id, $user->primaryMembership()->value('department_id'));
    }

    public function test_memberships_require_exactly_one_primary(): void
    {
        $user = User::factory()->create();
        [$first, $second] = Department::factory()->count(2)->create();

        $this->expectException(BusinessRuleException::class);

        app(SyncUserMemberships::class)->handle($user, [
            ['department_id' => $first->id, 'is_primary' => true],
            ['department_id' => $second->id, 'is_primary' => true],
        ]);
    }

    public function test_inactive_department_cannot_be_used_for_a_new_membership(): void
    {
        $user = User::factory()->create();
        $inactive = Department::factory()->inactive()->create();

        $this->expectException(BusinessRuleException::class);

        app(SyncUserMemberships::class)->handle($user, [['department_id' => $inactive->id, 'is_primary' => true]]);
    }

    public function test_existing_membership_survives_department_deactivation_on_edit(): void
    {
        $user = User::factory()->create();
        $department = Department::factory()->create();
        $sync = app(SyncUserMemberships::class);
        $sync->handle($user, [['department_id' => $department->id, 'is_primary' => true]]);

        $department->forceFill(['is_active' => false])->save();
        $sync->handle($user, [['department_id' => $department->id, 'is_primary' => true, 'is_leader' => true]]);

        $this->assertTrue($user->leadsDepartment($department));
    }

    public function test_deactivating_user_revokes_sessions_and_is_audited(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->superAdmin();
        $user = $this->staff();
        DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin);

        Livewire::test('pages::admin.users.index')
            ->call('confirmStatus', $user->id)
            ->call('changeStatus')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->deactivated_at);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertTrue(Activity::query()->where('event', 'user.deactivated')->where('subject_id', $user->id)->exists());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_user_cannot_deactivate_themselves(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->expectException(BusinessRuleException::class);

        app(ChangeUserStatus::class)->handle($admin, false, $admin);
    }

    public function test_last_active_super_admin_cannot_be_deactivated_or_demoted(): void
    {
        $onlyAdmin = $this->superAdmin();
        $otherAdmin = $this->superAdmin();
        $otherAdmin->forceFill(['is_active' => false])->save();

        $this->actingAs($otherAdmin);

        $this->expectException(BusinessRuleException::class);

        app(ChangeUserStatus::class)->handle($onlyAdmin, false, $otherAdmin);
    }

    public function test_non_super_admin_cannot_grant_super_admin_role(): void
    {
        $manager = $this->userWithPermissions('user.view', 'user.update');
        $target = $this->staff();

        $this->actingAs($manager);

        Livewire::test('pages::admin.users.form', ['user' => $target])
            ->set('role', config('harma.super_admin_role'))
            ->call('save');

        $this->assertFalse($target->fresh()->isSuperAdmin());
    }

    public function test_user_cannot_change_their_own_role(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->actingAs($admin);

        Livewire::test('pages::admin.users.form', ['user' => $admin])
            ->set('role', 'Staff')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_role_change_is_audited(): void
    {
        $target = $this->staff();

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.form', ['user' => $target])
            ->set('role', 'Management')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole('Management'));
        $this->assertTrue(Activity::query()->where('event', 'user.role_changed')->where('subject_id', $target->id)->exists());
    }

    public function test_admin_can_send_password_reset_link(): void
    {
        Notification::fake();
        $target = $this->staff();

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.show', ['user' => $target])
            ->call('sendResetLink')
            ->assertHasNoErrors();

        Notification::assertSentTo($target, ResetPassword::class);
    }

    public function test_user_list_can_be_filtered_by_department_role_and_status(): void
    {
        $department = Department::factory()->create();
        $inDepartment = $this->staff();
        DepartmentMembership::factory()->primary()->create(['user_id' => $inDepartment->id, 'department_id' => $department->id]);
        $outside = User::factory()->withRole('Management')->create();
        $inactive = User::factory()->withRole('Staff')->inactive()->create();

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.index')
            ->set('department', (string) $department->id)
            ->assertSee($inDepartment->username)
            ->assertDontSee($outside->username);

        Livewire::test('pages::admin.users.index')
            ->set('role', 'Management')
            ->assertSee($outside->username)
            ->assertDontSee($inDepartment->username);

        Livewire::test('pages::admin.users.index')
            ->set('status', 'inactive')
            ->assertSee($inactive->username)
            ->assertDontSee($inDepartment->username);
    }
}
