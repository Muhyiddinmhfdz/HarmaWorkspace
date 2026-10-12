<?php

namespace App\Models;

use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasVisibilityScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deactivated_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'phone', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasActiveStatus, HasFactory, HasRoles, HasVisibilityScope, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * All department memberships, including ended ones.
     *
     * @return HasMany<DepartmentMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(DepartmentMembership::class);
    }

    /**
     * @return HasMany<DepartmentMembership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereNull('ended_at');
    }

    /**
     * @return HasOne<DepartmentMembership, $this>
     */
    public function primaryMembership(): HasOne
    {
        return $this->hasOne(DepartmentMembership::class)
            ->whereNull('ended_at')
            ->where('is_primary', true);
    }

    /**
     * Departments the user currently belongs to.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_memberships')
            ->wherePivotNull('ended_at')
            ->withPivot(['position_id', 'is_primary', 'is_leader', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Users with `user.view` see everyone; others only see themselves.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('user.view')) {
            return;
        }

        $query->whereKey($user->getKey());
    }

    /**
     * Determine whether the user holds the Super Admin system role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('harma.super_admin_role'));
    }

    /**
     * Determine whether the user leads the given department (contextual role).
     */
    public function leadsDepartment(Department|int $department): bool
    {
        $departmentId = $department instanceof Department ? $department->id : $department;

        return $this->activeMemberships()
            ->where('department_id', $departmentId)
            ->where('is_leader', true)
            ->exists();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
