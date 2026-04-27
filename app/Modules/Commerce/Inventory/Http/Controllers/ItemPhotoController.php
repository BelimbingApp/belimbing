<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Http\Controllers;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ItemPhotoController
{
    private const DISK = 'local';

    public function show(Request $request, Item $item, ItemPhoto $photo): BinaryFileResponse
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        app(AuthorizationService::class)->authorize(
            Actor::forUser($user),
            'commerce.inventory_item.view',
        );

        if ((int) $photo->item_id !== (int) $item->id) {
            abort(404);
        }

        if ((int) $item->company_id !== (int) $user->company_id) {
            abort(404);
        }

        $path = Storage::disk(self::DISK)->path($photo->storage_key);
        if (! is_file($path)) {
            abort(404);
        }

        $mimeType = (string) ($photo->mime_type ?? $request->query('mime', ''));
        $isImage = str_starts_with($mimeType, 'image/');
        $disposition = $isImage ? 'inline' : 'attachment';

        return response()->file($path, [
            'Content-Disposition' => $disposition,
        ]);
    }
}
