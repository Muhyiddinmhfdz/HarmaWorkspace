<?php

namespace App\Domain\System\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Builds the sidebar from config/navigation.php for a given user.
 *
 * An item is shown when the user holds one of its permissions and, when the
 * item declares `when`, the contextual condition holds. Empty groups are
 * dropped so no section heading appears without items.
 */
class Navigation
{
    /**
     * Groups and items the user may see, with the current item flagged.
     *
     * @return list<array{heading: string|null, items: list<array{label: string, icon: string, url: string, current: bool}>}>
     */
    public function for(User $user): array
    {
        $groups = [];

        foreach (config('navigation', []) as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (! $user->canAny((array) $item['permission'])) {
                    continue;
                }

                if (isset($item['when']) && ! $this->condition($item['when'], $user)) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'url' => route($item['route']),
                    'current' => Request::routeIs(...explode('|', $item['active'])),
                ];
            }

            if ($items !== []) {
                $groups[] = ['heading' => $group['heading'] ?? null, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * Contextual visibility conditions (never role names).
     */
    private function condition(string $condition, User $user): bool
    {
        return match ($condition) {
            // A work queue only makes sense for members of a department, or
            // for those who manage every inbox.
            'department-inbox' => $user->can('ticket.view-all')
                || $user->can('ticket.manage-inbox')
                || $user->activeMemberships()->exists(),
            default => false,
        };
    }
}
