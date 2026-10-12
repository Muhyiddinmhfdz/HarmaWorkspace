<?php

namespace App\Providers;

use App\Domain\System\Settings\Settings;
use App\Listeners\RecordLastLogin;
use App\Models\Attachment;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Position;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectDepartment;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectMember;
use App\Models\ProjectMilestone;
use App\Models\ProjectRole;
use App\Models\Setting;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubtask;
use App\Models\TaskType;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketCategory;
use App\Models\TicketRejectionReason;
use App\Models\TicketRelation;
use App\Models\TicketRelationType;
use App\Models\TicketResolution;
use App\Models\TicketSource;
use App\Models\TicketSubcategory;
use App\Models\TicketTransfer;
use App\Models\TicketTransferReason;
use App\Models\TicketType;
use App\Models\User;
use App\Policies\RolePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureModels();
        $this->configureAuthorization();
        $this->configureRouteBindings();

        Event::listen(Login::class, RecordLastLogin::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(8)
                ->letters()
                ->numbers(),
        );
    }

    protected function configureModels(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Stable aliases for polymorphic columns (activity log, model_has_roles, ...).
        Relation::enforceMorphMap([
            'user' => User::class,
            'department' => Department::class,
            'department_membership' => DepartmentMembership::class,
            'position' => Position::class,
            'client' => Client::class,
            'setting' => Setting::class,
            'status' => Status::class,
            'priority' => Priority::class,
            'project' => Project::class,
            'project_category' => ProjectCategory::class,
            'project_role' => ProjectRole::class,
            'project_health_status' => ProjectHealthStatus::class,
            'project_department' => ProjectDepartment::class,
            'project_member' => ProjectMember::class,
            'project_milestone' => ProjectMilestone::class,
            'task' => Task::class,
            'task_type' => TaskType::class,
            'task_category' => TaskCategory::class,
            'task_assignment' => TaskAssignment::class,
            'task_subtask' => TaskSubtask::class,
            'checklist_item' => ChecklistItem::class,
            'ticket' => Ticket::class,
            'ticket_type' => TicketType::class,
            'ticket_category' => TicketCategory::class,
            'ticket_subcategory' => TicketSubcategory::class,
            'ticket_source' => TicketSource::class,
            'ticket_resolution' => TicketResolution::class,
            'ticket_transfer_reason' => TicketTransferReason::class,
            'ticket_rejection_reason' => TicketRejectionReason::class,
            'ticket_relation_type' => TicketRelationType::class,
            'ticket_assignment' => TicketAssignment::class,
            'ticket_transfer' => TicketTransfer::class,
            'ticket_relation' => TicketRelation::class,
            'comment' => Comment::class,
            'attachment' => Attachment::class,
            'role' => Role::class,
            'permission' => Permission::class,
        ]);
    }

    /**
     * Explicit bindings so route-level `can:` middleware always receives models.
     */
    protected function configureRouteBindings(): void
    {
        Route::model('user', User::class);
        Route::model('department', Department::class);
        Route::model('client', Client::class);
        Route::model('role', Role::class);
        Route::model('project', Project::class);
        Route::model('task', Task::class);
        Route::model('ticket', Ticket::class);
        Route::model('attachment', Attachment::class);
    }

    protected function configureAuthorization(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        // The single Super Admin bypass. Business invariants still apply because
        // they are enforced inside domain actions, not in policies.
        Gate::before(function (User $user): ?bool {
            return $user->is_active && $user->isSuperAdmin() ? true : null;
        });
    }
}
