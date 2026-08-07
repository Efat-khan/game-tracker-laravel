<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The single-page app
|--------------------------------------------------------------------------
|
| Everything that is not /api and not a real file falls through to the React
| shell, which does its own routing. The API is untouched — the SPA talks to
| it over the same 69 routes any other client would use.
|
*/

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|storage|build|up).*$');
