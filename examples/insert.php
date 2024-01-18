<?php

use Illuminate\Database\Eloquent\Model;

require __DIR__.'/init.php';

/**
 * @property int $id
 * @property string $title
 * @property string $remark
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Remark extends Model {

	protected $table = 'remark';

}

$item = new Remark();
$item->title = 'Hello';
$item->remark = 'Hello World';
$result = $item->save();

var_dump($result);

print_r($item->toArray());
