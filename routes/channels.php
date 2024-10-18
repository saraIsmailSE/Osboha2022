<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('notifications-channel.{reciver_id}', function (User $user, int $userId) {
    return Auth::check();
});

Broadcast::channel('single-room-channel.{room_id}', function (User $user, int $userId) {
    return Auth::check();
});

Broadcast::channel('rooms-channel.{user_id}', function (User $user, int $userId) {
    return Auth::check();
});
