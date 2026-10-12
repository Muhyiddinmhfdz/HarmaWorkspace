<?php

namespace App\Domain\Project\Rules;

use App\Enums\MasterScope;
use App\Enums\ProjectVisibility;
use App\Models\Client;
use App\Models\Department;
use App\Models\Priority;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules for project forms. Domain actions re-check scope and
 * active-state invariants, so these rules give early, field-level feedback.
 */
class ProjectRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function general(?int $currentClientId = null, ?int $currentCategoryId = null, ?int $currentPriorityId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_id' => ['nullable', 'integer', self::activeOrCurrent(Client::class, $currentClientId)],
            'project_category_id' => ['required', 'integer', self::activeOrCurrent(ProjectCategory::class, $currentCategoryId)],
            'priority_id' => ['required', 'integer', self::activeOrCurrent(Priority::class, $currentPriorityId)->where('scope', MasterScope::Project->value)],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function visibility(): array
    {
        return [
            'visibility' => ['required', Rule::enum(ProjectVisibility::class)],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function ownership(?int $currentOwnerId = null, ?int $currentManagerId = null): array
    {
        return [
            'owner_id' => ['nullable', 'integer', self::activeOrCurrent(User::class, $currentOwnerId)],
            'project_manager_id' => ['required', 'integer', self::activeOrCurrent(User::class, $currentManagerId)],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function departments(): array
    {
        return [
            'lead_department_id' => ['required', 'integer', self::activeOrCurrent(Department::class, null)],
            'lead_user_id' => ['nullable', 'integer', self::activeOrCurrent(User::class, null)],
            'supporting_department_ids' => ['array'],
            'supporting_department_ids.*' => ['integer', 'distinct', 'different:lead_department_id', self::activeOrCurrent(Department::class, null)],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function schedule(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * Existing record that is active, or the value already stored on the project.
     *
     * @param  class-string  $model
     */
    private static function activeOrCurrent(string $model, ?int $currentId): Exists
    {
        return Rule::exists($model, 'id')->where(fn (Builder $query) => $query
            ->where('is_active', true)
            ->when($currentId !== null, fn (Builder $query) => $query->orWhere('id', $currentId)));
    }
}
