<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/init.php';

$item = DB::table('post_errors')->first();

print_r($item);
