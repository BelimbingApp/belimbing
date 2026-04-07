<?php

use App\Modules\Core\AI\Models\ChatTurn;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Modules.Core.User.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('turn.{turnId}', function ($user, $turnId) {
    $turn = ChatTurn::query()->find($turnId);

    return $turn !== null && (int) $turn->acting_for_user_id === (int) $user->id;
});
