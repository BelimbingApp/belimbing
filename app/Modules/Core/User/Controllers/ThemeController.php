<?php

namespace App\Modules\Core\User\Controllers;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class ThemeController
{
    public function __construct(private SettingsService $settings) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
        ]);
        $user = $request->user();

        abort_if($user === null, 401);

        $scope = Scope::user(
            (int) $user->getAuthIdentifier(),
            method_exists($user, 'getCompanyId') ? $user->getCompanyId() : null,
        );

        if ($validated['theme'] === 'system') {
            $this->settings->forget('ui.theme', $scope);
        } else {
            $this->settings->set('ui.theme', $validated['theme'], $scope);
        }

        return response()->json(['theme' => $validated['theme']]);
    }
}
