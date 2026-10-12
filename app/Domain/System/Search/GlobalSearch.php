<?php

namespace App\Domain\System\Search;

use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global search across the modules available so far. Each source is only
 * searched when the user may view it, and uses the same visibility rules as
 * the module's list page. Tasks and tickets join in later phases.
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
