<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/src/bootstrap.php';
restore_exception_handler();
if (!str_contains(App\Config::get('DB_DATABASE'), 'test')) throw new RuntimeException('Test database required');
$db = App\Database::connection();
$names = ['release_test_author', 'release_test_other', 'release_test_admin'];
$ids = $db->query('SELECT id FROM users WHERE username IN ("release_test_author","release_test_other","release_test_admin")')->fetchAll(PDO::FETCH_COLUMN);
if ($ids) {
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM software WHERE author_id IN ($marks)")->execute($ids);
    $db->prepare("DELETE FROM users WHERE id IN ($marks)")->execute($ids);
}
foreach ($names as $name) {
    $db->prepare('INSERT INTO users (username,password_hash,role,points) VALUES (?,?,?,100)')
        ->execute([$name, password_hash('Integration_test_20260917!', PASSWORD_DEFAULT), $name === 'release_test_admin' ? 'admin' : 'user']);
}
App\Cache::forgetPrefix('software:');
echo "Isolated HTTP fixtures ready\n";
