<?php

use App\Base\Foundation\Services\LandingPageResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function (LandingPageResolver $landing) {
    return Auth::check()
        ? redirect()->to($landing->urlFor(Auth::user()))
        : redirect()->route('login');
})->name('home');

// Unmatched URLs are 404s thrown during routing — before the web middleware
// group runs — so a plain error view never sees the session and always renders
// as a guest. Routing the fallback through the web group starts the session and
// resolves auth, letting errors/404 render inside the app shell for signed-in
// users. JSON clients still get a JSON 404.
Route::fallback(function (Request $request) {
    if ($request->expectsJson()) {
        return response()->json(['message' => __('Not Found.')], 404);
    }

    return response()->view('errors.404', [], 404);
});
