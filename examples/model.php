<?php

use Illuminate\Database\Eloquent\Model;

require __DIR__.'/init.php';

class PostError extends Model {

	protected $table = 'post_errors';

}

$item = PostError::query()->first();
print_r($item);
