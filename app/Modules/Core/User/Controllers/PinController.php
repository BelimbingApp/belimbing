<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Controllers;

use App\Modules\Core\User\Models\UserPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the authenticated user's pinned sidebar items.
 *
 * Supports both menu item pins (from the sidebar menu) and page pins
 * (from individual pages like tool workspaces). Called from Alpine
 * components via fetch(). All endpoints return JSON and are protected
 * by the 'auth' middleware.
 */
class PinController
{
    /**
     * Toggle a pin for the current user.
     *
     * If the item is pinned (matched by type + pinnable_id), it is unpinned.
     * If not pinned, it is added at the end of the pinned list.
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', 'in:menu_item,page'],
            'pinnable_id' => ['required', 'string', 'max:150'],
            'label' => ['required', 'string', 'max:150'],
            'url' => ['required', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $type = $request->input('type');
        $pinnableId = $request->input('pinnable_id');

        $existing = UserPin::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('pinnable_id', $pinnableId)
            ->first();

        if ($existing) {
            $existing->delete();
            $user->unsetRelation('pins');

            return response()->json([
                'pinned' => false,
                'pins' => $user->getPins(),
            ]);
        }

        $maxOrder = UserPin::query()
            ->where('user_id', $user->id)
            ->max('sort_order') ?? -1;

        UserPin::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'pinnable_id' => $pinnableId,
            'label' => $this->dropTopLevelSegment($request->input('label')),
            'url' => $request->input('url'),
            'icon' => $request->input('icon'),
            'sort_order' => $maxOrder + 1,
        ]);

        $user->unsetRelation('pins');

        return response()->json([
            'pinned' => true,
            'pins' => $user->getPins(),
        ]);
    }

    /**
     * Reorder the current user's pinned items.
     *
     * Accepts an ordered array of pin references. Each item's sort_order
     * is updated to match its array index.
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'pins' => ['required', 'array', 'min:1'],
            'pins.*.type' => ['required', 'string', 'in:menu_item,page'],
            'pins.*.pinnable_id' => ['required', 'string', 'max:150'],
        ]);

        $user = $request->user();
        $pins = $request->input('pins');

        foreach ($pins as $index => $pin) {
            UserPin::query()
                ->where('user_id', $user->id)
                ->where('type', $pin['type'])
                ->where('pinnable_id', $pin['pinnable_id'])
                ->update(['sort_order' => $index]);
        }

        return response()->json([
            'pins' => $user->getPins(),
        ]);
    }

    /**
     * Drop the level-0 (top-level) segment from the pin label before persisting,
     * so pins show e.g. "Employees/Kiat" instead of "Administration/Employees/Kiat".
     * Callers must use "/" as path separator (no spaces).
     */
    private function dropTopLevelSegment(string $label): string
    {
        $firstSlash = strpos($label, '/');

        if ($firstSlash !== false) {
            return substr($label, $firstSlash + 1);
        }

        return $label;
    }
}
