<?php

namespace App\Domain\System\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Builds the sidebar from config/navigation.php for a given user.
 */
class Navigation
{
    /**
     * Groups and items the user may see, with the current item flagged.
     *
     * @return list<array{heading: string, items: list<array{label: string, icon: string, url: string, current: bool}>}>
     */
    public function for(User $user): array
    {
        $groups = [];

        foreach (config('navigation', []) as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (! $user->can($item['permission'])) {
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
                $groups[] = ['heading' => $group['heading'], 'items' => $items];
            }
        }

        return $groups;
    }
}
