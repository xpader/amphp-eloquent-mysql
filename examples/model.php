<?php

use Illuminate\Database\Eloquent\Model;

require __DIR__.'/init.php';

class PostError extends Model {

	protected $table = 'post_errors';

}

$item = PostError::query()->limit(2)->get()->toArray();
print_r($item);
