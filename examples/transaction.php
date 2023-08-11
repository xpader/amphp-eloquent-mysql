<?php

use Illuminate\Database\Eloquent\Model;

require __DIR__.'/init.php';

class PostError extends Model {

	protected $table = 'post_errors';

}

$item = PostError::query()->first();

\Illuminate\Support\Facades\DB::transaction(function() use ($item) {
	$item->increment('credit');
});

print_r($item->toArray());
