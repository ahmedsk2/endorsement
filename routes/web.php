<?php

use Illuminate\Support\Facades\Route;

// The root will land on the four-unit endorsement chooser once the module exists
// (Phase 4). Until auth ships (Phase 2) it simply redirects there.
Route::get('/', fn () => redirect('/endorsement'));
