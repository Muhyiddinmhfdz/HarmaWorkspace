<?php

namespace App\Providers;

use App\Domain\System\Settings\Settings;
use App\Listeners\RecordLastLogin;
use App\Models\Client;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Position;
use App\Models\Setting;
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
