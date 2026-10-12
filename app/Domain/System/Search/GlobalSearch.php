<?php

namespace App\Domain\System\Search;

use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global search: Projects, Tasks and Reports (tickets) first, then the
 * directory groups the user may view. Each source is only
 * searched when the user may view it, and uses the same visibility rules as
 * the module's list page, so hidden records never appear in results.
 */
class GlobalSearch
{
    private const int LIMIT_PER_GROUP = 5;

    /**
     * @return list<array{group: string, items: list<array{title: string, subtitle: string, url: string}>}>
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $results = [];

        if ($user->can('viewAny', Project::class)) {
            $results[] = [
                'group' => 'Projects',
                'items' => array_values(Project::query()
                    ->visibleTo($user)
                    ->where(fn (Builder $query) => $query->where('number', 'like', $like)->orWhere('name', 'like', $like))
                    ->orderByRaw('archived_at IS NULL DESC')
                    ->orderByDesc('id')
                    ->limit(self::LIMIT_PER_GROUP)
                    ->get(['id', 'number', 'name'])
                    ->map(fn (Project $found): array => [
                        'title' => $found->name,
                        'subtitle' => $found->number,
                        'url' => route('projects.show', $found),
                    ])
                    ->all()),
            ];
        }

        if ($user->can('viewAny', Task::class)) {
            $results[] = [
                'group' => 'Tasks',
                'items' => array_values(Task::query()
                    ->visibleTo($user)
                    ->where(fn (Builder $query) => $query->where('number', 'like', $like)->orWhere('title', 'like', $like))
                    ->orderByDesc('id')
                    ->limit(self::LIMIT_PER_GROUP)
                    ->get(['id', 'number', 'title'])
                    ->map(fn (Task $found): array => [
                        'title' => $found->title,
                        'subtitle' => $found->number,
                        'url' => route('tasks.show', $found),
                    ])
                    ->all()),
            ];
        }

        if ($user->can('viewAny', Ticket::class)) {
            $results[] = [
                'group' => 'Reports',
                'items' => array_values(Ticket::query()
                    ->visibleTo($user)
                    ->where(fn (Builder $query) => $query
                        ->where('number', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $like)))
                    ->with(['status:id,name', 'currentDepartment:id,name'])
                    ->orderByDesc('id')
                    ->limit(self::LIMIT_PER_GROUP)
                    ->get(['id', 'number', 'title', 'status_id', 'current_department_id'])
                    ->map(fn (Ticket $found): array => [
                        'title' => $found->title,
                        'subtitle' => $found->number.' · '.$found->currentDepartment->name.' • '.$found->status->name,
                        'url' => route('tickets.show', $found),
                    ])
                    ->all()),
            ];
        }

        if ($user->can('user.view')) {
            $results[] = [
                'group' => 'Members',
                'items' => array_values(User::query()
                    ->visibleTo($user)
                    ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('username', 'like', $like)->orWhere('email', 'like', $like))
                    ->orderBy('name')
                    ->limit(self::LIMIT_PER_GROUP)
                    ->get(['id', 'name', 'username'])
                    ->map(fn (User $found): array => [
                        'title' => $found->name,
                        'subtitle' => '@'.$found->username,
                        'url' => route('admin.users.show', $found),
                    ])
                    ->all()),
            ];
        }

        if ($user->can('department.view')) {
            $results[] = [
                'group' => 'Departments',
                'items' => array_values(Department::query()
                    ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like))
                    ->orderBy('name')
                    ->limit(self::LIMIT_PER_GROUP)
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Department $found): array => [
                        'title' => $found->name,
                        'subtitle' => $found->code,
                        'url' => route('admin.departments.show', $found),
                    ])
                    ->all()),
            ];
        }

        if ($user->can('client.view')) {
            $results[] = [
                'group' => 'Clients',
                'items' => array_values(Client::query()
                    ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like))
                    ->orderBy('name')
                    ->limit(self::LIMIT_PER_GROUP)
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Client $found): array => [
                        'title' => $found->name,
                        'subtitle' => $found->code,
                        'url' => route('clients.show', $found),
                    ])
                    ->all()),
            ];
        }

        return array_values(array_filter($results, fn (array $group): bool => $group['items'] !== []));
    }
}
