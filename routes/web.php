<?php

use Illuminate\Support\Facades\Route;

// GM Mode runs without accounts for now; the home page is the save list.
Route::redirect('/', '/gm')->name('home');

require __DIR__.'/gm.php';
