<?php

use AmphpEloquentMysql\Connection;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$capsule = new Manager();

$capsule->getDatabaseManager()->extend('ampmysql', function($config, $name) {
	$config['name'] = $name;
	return new Connection($config);
});

// 创建链接
$capsule->addConnection([
	'driver' => 'ampmysql',
	'host' => '127.0.0.1',
	'database' => 'test',
	'username' => 'root',
	'password' => '',
	'charset' => 'utf8mb4',
	'port' => 3306,
	'collation' => 'utf8mb4_general_ci',
	'prefix' => '',
]);

// 设置全局静态可访问DB
$capsule->setAsGlobal();

// 启动Eloquent （如果只使用查询构造器，这个可以注释）
$capsule->bootEloquent();

DB::swap($capsule->getDatabaseManager());

