# amphp-eloquent-mysql

Async/coroutine amphp eloquent mysql adapter.

Amphp mysql adapter to laravel eloquent, combining the connection pool, high concurrency of amphp mysql with the functionality and flexibility of eloquent.

Now you have a high concurrency eloquent.

php version >= 8.1, no PDO required, no mysqli required.

## Usage

```php
use AmphpEloquentMysql\Connection;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;

$capsule = new Manager();

$capsule->getDatabaseManager()->extend('ampmysql', function($config, $name) {
	$config['name'] = $name;
	return new Connection($config);
});

// Create connection
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

// Set can be visit as global static
$capsule->setAsGlobal();

// Boot Eloquent (can ignore this if you use QueryBuilder only)
$capsule->bootEloquent();

DB::swap($capsule->getDatabaseManager());

// Initialized finished, you can use it now

$item = DB::table('users')->first();
```
