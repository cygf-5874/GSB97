<?php

declare(strict_types=1);

/**
 * 固定验收入口：evolveproj 的「对外契约」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                    跑全部场景，全过才退 0
 *   php check/check.php -list              列出全部场景
 *   php check/check.php --only upcast      只跑一组
 *   php check/check.php --only defer,state 只跑指定的几组
 *
 * 五组共 11 个场景：
 *   upcast   3 —— 逐级且每级恰好一次（含重复注册报错）、缺中间版本报错、高版本报错；
 *   snapshot 3 —— 快照 + 续放与全量重放一致、去重集合随快照持久化、blob 版本不匹配报错；
 *   defer    2 —— DEFER 后 flush 保持原投递顺序、未注册时 deferred 不变；
 *   state    2 —— 规范形式逐字节稳定、skipped 与 deferred 有序；
 *   robust   1 —— 空事件流 / 只有快照 / 同一 eventId 跨版本。
 *
 * 只依赖 PHP 8 标准库；判据完全确定：不读时间、不用随机源、不看数组 hash 顺序。
 * 失败不早退，每个场景都兜住 Throwable。
 */

require __DIR__ . '/../autoload.php';

use Evolveproj\Event;
use Evolveproj\Projection;
use Evolveproj\ProjectionError;
use Evolveproj\Snapshot;
use Evolveproj\Upcast;

final class CheckFailure extends \Exception
{
    public function __construct(
        string $message,
        public readonly string $expected = '-',
        public readonly string $actual = '-',
    ) {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function tk(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
}

function assert_same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure(
            $what,
            var_export($want, true),
            var_export($got, true)
        );
    }
}

function assert_error(callable $fn, ?string $kind, string $what): void
{
    $want = $kind === null ? 'ProjectionError' : 'ProjectionError(kind=' . $kind . ')';

    try {
        $fn();
    } catch (ProjectionError $exc) {
        if ($kind !== null && $exc->kind !== $kind) {
            throw new CheckFailure(
                $what . '（kind 不符）',
                $want,
                'ProjectionError(kind=' . $exc->kind . ')'
            );
        }

        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure($what, $want, get_class($exc) . ': ' . $exc->getMessage());
    }

    throw new CheckFailure($what, $want, '未抛错（正常返回）');
}

/** @param list<Event> $events @return list<string> */
function ids(array $events): array
{
    $out = [];
    foreach ($events as $event) {
        $out[] = $event->eventId;
    }

    return $out;
}

/** 把 payload['n'] 累加进状态键的处理器。 */
function adder(string $key = 'total'): callable
{
    return static function (array $state, array $payload) use ($key): array {
        $state[$key] = ($state[$key] ?? 0) + (int) ($payload['n'] ?? 0);

        return $state;
    };
}

/** 把 payload['i'] 按投递顺序拼成状态键 trace 的处理器。 */
function tracer(string $key = 'trace'): callable
{
    return static function (array $state, array $payload) use ($key): array {
        $state[$key] = ($state[$key] ?? '') . (string) ($payload['i'] ?? '?');

        return $state;
    };
}

/** 把 payload['k'] => payload['v'] 合并进状态的处理器（$nested 时写进 inner 子数组）。 */
function kvhandler(bool $nested = false): callable
{
    return static function (array $state, array $payload) use ($nested): array {
        $k = (string) ($payload['k'] ?? '');
        if ($nested) {
            $state['inner'][$k] = $payload['v'] ?? null;
        } else {
            $state[$k] = $payload['v'] ?? null;
        }

        return $state;
    };
}

/** 升版器：给 payload 打上目标版本标记。 */
function mark(int $to): callable
{
    return static function (array $payload) use ($to): array {
        $payload['v'] = $to;

        return $payload;
    };
}

/** 升版器：往 payload['trace'] 追加一段标记（用来验证每级恰好应用一次）。 */
function stepper(string $tag): callable
{
    return static function (array $payload) use ($tag): array {
        $payload['trace'] = ($payload['trace'] ?? '') . $tag;

        return $payload;
    };
}

function num(string $id, int $n, int $version = Projection::MODEL_VERSION): Event
{
    return new Event($id, 'num', $version, ['n' => $n]);
}

function full(int $n = 3): Upcast
{
    $up = new Upcast();
    $up->register(1, mark(2));
    $up->register(2, mark(3));

    return $up;
}

// ---------------------------------------------------------------------------
// upcast
// ---------------------------------------------------------------------------

$GROUPS = [];

$GROUPS['upcast'] = [
    tk('graded', '逐级升版且每级恰好应用一次，重复注册报错', static function (): void {
        assert_same(3, Upcast::CURRENT_VERSION, '当前模型版本');
        assert_same(Projection::MODEL_VERSION, Upcast::CURRENT_VERSION, '两处版本口径一致');

        $up = new Upcast();
        $up->register(1, stepper('1>2;'));
        $up->register(2, stepper('2>3;'));

        $one = $up->apply(new Event('u1', 'T', 1, ['trace' => '']));
        assert_same(3, $one->version, 'v1 升到当前版本');
        assert_same('1>2;2>3;', $one->payload['trace'], '两级各应用一次且按顺序');
        assert_same('u1', $one->eventId, 'eventId 不变');
        assert_same('T', $one->type, 'type 不变');

        $two = $up->apply(new Event('u2', 'T', 2, ['trace' => '']));
        assert_same(3, $two->version, 'v2 升到当前版本');
        assert_same('2>3;', $two->payload['trace'], '只跑缺的那一级');

        $three = $up->apply(new Event('u3', 'T', 3, ['trace' => '']));
        assert_same(3, $three->version, '已是当前版本');
        assert_same('', $three->payload['trace'], '当前版本不再升版');

        assert_error(
            static fn () => $up->register(1, stepper('again')),
            ProjectionError::DUPLICATE_STEP,
            '同一 from 重复注册'
        );
    }),

    tk('gap', '缺失中间版本报错', static function (): void {
        $up = new Upcast();
        $up->register(1, stepper('1>2;'));

        assert_error(
            static fn () => $up->apply(new Event('g1', 'T', 1, ['trace' => ''])),
            ProjectionError::UPCAST_GAP,
            '缺 2>3 时升 v1 事件'
        );
        assert_error(
            static fn () => $up->apply(new Event('g2', 'T', 2, ['trace' => ''])),
            ProjectionError::UPCAST_GAP,
            '缺 2>3 时升 v2 事件'
        );
    }),

    tk('ahead', '事件版本高于当前版本报错', static function (): void {
        $up = full();

        assert_error(
            static fn () => $up->apply(new Event('a1', 'T', 4, ['trace' => ''])),
            ProjectionError::UPCAST_AHEAD,
            'v4 事件'
        );
        assert_error(
            static fn () => $up->apply(new Event('a2', 'T', 9, ['trace' => ''])),
            ProjectionError::UPCAST_AHEAD,
            'v9 事件'
        );

        // 报错之后注册表仍然可用。
        assert_same(3, $up->apply(new Event('a3', 'T', 3, ['trace' => '']))->version, '报错后仍可升版');
    }),
];

// ---------------------------------------------------------------------------
// snapshot
// ---------------------------------------------------------------------------

$GROUPS['snapshot'] = [
    tk('resume', '快照 + 续放与从零全量重放一致', static function (): void {
        $p = new Projection();
        $p->registerType('num', adder());
        $p->applyAll([num('r1', 1), num('r2', 2)]);
        $blob = $p->snapshot();
        assert_same(true, $blob !== '', 'blob 非空');

        $q = new Projection();
        $q->registerType('num', adder());
        $q->restore($blob);
        $q->applyAll([num('r3', 4)]);

        $all = new Projection();
        $all->registerType('num', adder());
        $all->applyAll([num('r1', 1), num('r2', 2), num('r3', 4)]);

        assert_same($all->state(), $q->state(), '续放与全量重放的状态');
        assert_same(json_encode($all->state()), json_encode($q->state()), '逐字节相同');
        assert_same(['total' => 7], $q->state(), '具体值');
    }),

    tk('dedup', '去重集合随快照持久化', static function (): void {
        $snap = new Snapshot();

        $p = new Projection(null, $snap);
        $p->registerType('num', adder());
        $p->applyAll([num('k1', 1), num('k2', 2)]);
        $blob = $p->snapshot();

        $q = new Projection(null, $snap);
        $q->registerType('num', adder());
        $q->restore($blob);

        // 快照前的事件再投一遍：必须被去重集合挡住。
        $q->applyAll([num('k1', 1), num('k2', 2)]);
        $q->apply(num('k3', 4));

        $all = new Projection(null, $snap);
        $all->registerType('num', adder());
        $all->applyAll([num('k1', 1), num('k2', 2), num('k3', 4)]);

        assert_same($all->state(), $q->state(), '续放与全量重放的状态');
        assert_same(['total' => 7], $q->state(), '快照前的事件没有被重复计入');

        // 快照之后新到的事件照常计入。
        $q->apply(num('k4', 8));
        assert_same(['total' => 15], $q->state(), '快照后的新事件');
    }),

    tk('version', 'blob 版本标记不匹配报错', static function (): void {
        $snap = new Snapshot();

        $p = new Projection(null, $snap);
        $p->registerType('num', adder());
        $p->apply(num('v1', 5));
        $blob = $p->snapshot();

        $q = new Projection(null, $snap);
        $q->registerType('num', adder());
        $q->restore($blob);
        assert_same(['total' => 5], $q->state(), '同版本 blob 正常还原');

        $bad = $snap->pack(['total' => 5], [], Projection::MODEL_VERSION + 1);
        assert_error(
            static fn () => $q->restore($bad),
            ProjectionError::SNAPSHOT_VERSION,
            '更高模型版本的 blob'
        );
        assert_same(['total' => 5], $q->state(), '拒绝不兼容 blob 后状态不变');

        assert_error(
            static fn () => $q->restore('这不是一个快照 blob'),
            null,
            '坏 blob'
        );
    }),
];

// ---------------------------------------------------------------------------
// defer
// ---------------------------------------------------------------------------

$GROUPS['defer'] = [
    tk('order', 'DEFER 后 flush 保持原投递顺序', static function (): void {
        $p = new Projection(full());

        // 旧版本、类型还没注册：先 DEFER。
        $p->applyAll([
            new Event('d1', 'tick', 1, ['i' => 1]),
            new Event('d2', 'tick', 1, ['i' => 2]),
            new Event('d3', 'tick', 1, ['i' => 3]),
        ]);
        assert_same(['d1', 'd2', 'd3'], ids($p->deferred()), 'DEFER 顺序');
        assert_same([], $p->state(), 'DEFER 时不解算');
        assert_same([], $p->skipped(), 'DEFER 不记 skipped');

        $p->registerType('tick', static function (array $state, array $payload): array {
            $state['trace'] = ($state['trace'] ?? '')
                . (string) $payload['i'] . '@' . (string) ($payload['v'] ?? '?') . ';';

            return $state;
        });
        $p->flush();

        assert_same(['trace' => '1@3;2@3;3@3;'], $p->state(), 'flush 保持原投递顺序且已升到当前版本');
        assert_same([], $p->deferred(), 'flush 后清空');
        assert_same([], $p->skipped(), 'flush 不改 skipped');
    }),

    tk('hold', '未注册时 flush 后 deferred 不变', static function (): void {
        $p = new Projection(full());

        $p->apply(new Event('h1', 'tick', 3, ['i' => 1]));
        $p->apply(new Event('h2', 'other', 3, ['i' => 2]));
        $p->apply(new Event('h3', 'tick', 3, ['i' => 3]));
        assert_same(['h1', 'h2', 'h3'], ids($p->deferred()), '初始 deferred');

        $p->registerType('tick', tracer('trace'));
        $p->flush();

        assert_same(['h2'], ids($p->deferred()), '仍未注册的原样留下');
        assert_same(['trace' => '13'], $p->state(), '已注册的按顺序应用（跳过未注册那条）');

        $p->flush();
        assert_same(['h2'], ids($p->deferred()), '重复 flush 不吞事件');
        assert_same(['trace' => '13'], $p->state(), '重复 flush 不变状态');

        $p->registerType('other', tracer('trace'));
        $p->flush();
        assert_same([], $p->deferred(), '注册后才被消费');
        assert_same(['trace' => '132'], $p->state(), '后注册的类型补在原位置之后');
    }),
];

// ---------------------------------------------------------------------------
// state
// ---------------------------------------------------------------------------

$GROUPS['state'] = [
    tk('canonical', '规范形式逐字节稳定', static function (): void {
        $p = new Projection();
        $p->registerType('kv', kvhandler());
        $p->apply(new Event('c1', 'kv', 3, ['k' => 'zulu', 'v' => 1]));
        $p->apply(new Event('c2', 'kv', 3, ['k' => 'alpha', 'v' => 2]));
        $p->apply(new Event('c3', 'kv', 3, ['k' => 'Mike', 'v' => 3]));
        assert_same(['Mike', 'alpha', 'zulu'], array_keys($p->state()), '顶层键按字节序升序');

        $q = new Projection();
        $q->registerType('kv', kvhandler());
        $q->apply(new Event('c3', 'kv', 3, ['k' => 'Mike', 'v' => 3]));
        $q->apply(new Event('c1', 'kv', 3, ['k' => 'zulu', 'v' => 1]));
        $q->apply(new Event('c2', 'kv', 3, ['k' => 'alpha', 'v' => 2]));
        assert_same(json_encode($p->state()), json_encode($q->state()), '同语义状态逐字节相同');

        $r = new Projection();
        $r->registerType('kv', kvhandler(true));
        $r->apply(new Event('n1', 'kv', 3, ['k' => 'zulu', 'v' => 1]));
        $r->apply(new Event('n2', 'kv', 3, ['k' => 'beta', 'v' => 2]));
        assert_same(['beta', 'zulu'], array_keys($r->state()['inner']), '嵌套键也按字节序升序');

        $s = new Projection();
        $s->registerType('kv', kvhandler(true));
        $s->apply(new Event('n2', 'kv', 3, ['k' => 'beta', 'v' => 2]));
        $s->apply(new Event('n1', 'kv', 3, ['k' => 'zulu', 'v' => 1]));
        assert_same(json_encode($r->state()), json_encode($s->state()), '嵌套同语义逐字节相同');
    }),

    tk('ordered', 'skipped 与 deferred 有序', static function (): void {
        $p = new Projection();
        $p->apply(new Event('s2', 'tick', 3, ['i' => 2]));
        $p->apply(new Event('s1', 'tick', 3, ['i' => 1]));
        $p->apply(new Event('x9', 'ghost', 3, ['i' => 9]));
        assert_same(['s2', 's1', 'x9'], ids($p->deferred()), 'deferred 保持投递顺序');
        $p->flush();
        assert_same(['s2', 's1', 'x9'], ids($p->deferred()), '无匹配类型时顺序不变');
        assert_same([], $p->skipped(), '默认策略不记 skipped');

        $q = new Projection(null, null, Projection::IGNORE);
        $q->apply(new Event('k3', 'ghost', 3, ['i' => 3]));
        $q->apply(new Event('k1', 'ghost', 3, ['i' => 1]));
        $q->apply(new Event('k2', 'ghost', 3, ['i' => 2]));
        assert_same(['k3', 'k1', 'k2'], $q->skipped(), 'skipped 保持投递顺序');
        assert_same([], $q->deferred(), 'IGNORE 不进 deferred');
        assert_same([], $q->state(), 'IGNORE 不改状态');
    }),
];

// ---------------------------------------------------------------------------
// robust
// ---------------------------------------------------------------------------

$GROUPS['robust'] = [
    tk('bounds', '空事件流 / 只有快照 / 同一 eventId 跨版本', static function (): void {
        // 空事件流
        $empty = new Projection();
        $empty->registerType('num', adder());
        assert_same([], $empty->state(), '空事件流的状态');
        $empty->flush();
        assert_same([], $empty->state(), '空事件流也能 flush');
        assert_same([], $empty->deferred(), '空事件流无 deferred');

        // 只有快照、没有事件
        $a = new Projection();
        $a->registerType('num', adder());
        $a->apply(num('b1', 5));
        $blob = $a->snapshot();

        $b = new Projection();
        $b->registerType('num', adder());
        $b->restore($blob);
        assert_same(['total' => 5], $b->state(), '只有快照时的状态');
        assert_same(json_encode($a->state()), json_encode($b->state()), '逐字节相同');
        $b->flush();
        assert_same(['total' => 5], $b->state(), '无事件时 flush 无副作用');

        // 同一 eventId 在不同版本下出现
        $c = new Projection();
        $c->registerType('num', adder());
        $c->apply(new Event('dup', 'num', 3, ['n' => 1]));
        $c->apply(new Event('dup', 'num', 2, ['n' => 100]));
        $c->apply(new Event('dup', 'num', 1, ['n' => 1000]));
        assert_same(['total' => 1], $c->state(), '同一 eventId 跨版本只算一次');
        assert_same([], $c->deferred(), '重复投递不进 deferred');
        assert_same([], $c->skipped(), '重复投递不进 skipped');
    }),
];

// ---------------------------------------------------------------------------
// 跑场景
// ---------------------------------------------------------------------------

$flat = [];
foreach ($GROUPS as $group => $scenarios) {
    foreach ($scenarios as $scenario) {
        $flat[] = [$group, $scenario['name'], $scenario['why'], $scenario['fn']];
    }
}

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（upcast/snapshot/defer/state/robust）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-8s] %-9s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

$total = count($selected);
printf("结果：通过 %d/%d\n", $passed, $total);
exit($failed === 0 ? 0 : 1);
