<?php
declare(strict_types=1);
// Run with php -n so the scan cursor test double does not need a live Redis server.
class Redis
{
    public int $calls = 0;
    public array $deleted = [];
    public function scan(?int &$cursor, string $pattern, int $count): array|false
    {
        $this->calls++;
        $cursor = $this->calls === 1 ? 42 : 0;
        return $this->calls === 1 ? false : ['software:list:test'];
    }
    public function del(array $keys): void { $this->deleted = array_merge($this->deleted, $keys); }
}
require dirname(__DIR__) . '/app/src/Cache.php';
$redis = new Redis();
$property = new ReflectionProperty(App\Cache::class, 'redis');
$property->setValue(null, $redis);
App\Cache::forgetPrefix('software:');
if ($redis->calls !== 2 || $redis->deleted !== ['software:list:test']) {
    throw new RuntimeException('Empty SCAN batches must not terminate a nonzero cursor');
}
echo "Redis cursor invalidation test passed\n";
