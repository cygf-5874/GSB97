<?php

declare(strict_types=1);

/**
 * 既有用例：单版本重放 + 快照往返。**别改这个文件。**
 *
 * 用法： php tests/run.php     全绿 → 退出码 0，否则 1
 *
 * 只覆盖当前已经可用的那一层（Projection 的投递/状态/最小快照往返），
 * 不涉及旧版本升版（Upcast）与带版本标记的快照编解码（Snapshot）。
 */

require __DIR__ . '/../autoload.php';

use Evolveproj\Event;
use Evolveproj\Projection;
use Evolveproj\ProjectionError;

$GLOBALS['t_total'] = 0;
$GLOBALS['t_pass'] = 0;

function tcase(string $name, callable $fn): void
{
    $GLOBALS['t_total']++;

    try {
        $fn();
    } catch (\Throwable $exc) {
        printf("FAIL %s —— %s: %s\n", $name, get_class($exc), $exc->getMessage());

        return;
    }

    $GLOBALS['t_pass']++;
}

function same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new \RuntimeException(
            $what . ' 期望=' . var_export($want, true) . ' 实际=' . var_export($got, true)
        );
    }
}

function truthy(bool $cond, string $what): void
{
    if (!$cond) {
        throw new \RuntimeException($what . ' 不成立');
    }
}

/** 把 payload['n'] 累加进状态键的处理器。 */
function adder(string $key = 'total'): callable
{
    return static function (array $state, array $payload) use ($key): array {
        $state[$key] = ($state[$key] ?? 0) + (int) ($payload['n'] ?? 0);

        return $state;
    };
}

/** 把 payload['k'] => payload['v'] 合并进状态的处理器。 */
function kv(bool $nested = false): callable
{
    return static function (array $state, array $payload) use ($nested): array {
        $k = (string) ($payload['k'] ?? '');
        $v = $payload['v'] ?? null;
        if ($nested) {
            $state['inner'][$k] = $v;
        } else {
            $state[$k] = $v;
        }

        return $state;
    };
}

function num(string $id, int $n, int $version = Projection::MODEL_VERSION): Event
{
    return new Event($id, 'num', $version, ['n' => $n]);
}

// ---------------------------------------------------------------------------
// 1~5 单版本重放
// ---------------------------------------------------------------------------

tcase('空投影的 state 是空数组', static function (): void {
    $p = new Projection();
    same([], $p->state(), '空投影状态');
    same([], $p->skipped(), '空投影 skipped');
    same([], $p->deferred(), '空投影 deferred');
});

tcase('投递一条事件后状态累积', static function (): void {
    $p = new Projection();
    $p->registerType('num', adder());
    $p->apply(num('e1', 5));
    same(['total' => 5], $p->state(), '单条事件后的状态');
});

tcase('多条事件按投递顺序累积', static function (): void {
    $p = new Projection();
    $p->registerType('num', adder());
    $p->applyAll([num('e1', 1), num('e2', 2), num('e3', 4)]);
    same(['total' => 7], $p->state(), '三条事件后的状态');
});

tcase('同一 eventId 重复投递只算一次', static function (): void {
    $p = new Projection();
    $p->registerType('num', adder());
    $p->apply(num('dup', 3));
    $p->apply(num('dup', 3));
    $p->apply(num('dup', 3));
    same(['total' => 3], $p->state(), '重复投递后的状态');
});

tcase('同一 eventId 换 payload 重复投递仍只算一次', static function (): void {
    $p = new Projection();
    $p->registerType('num', adder());
    $p->apply(num('dup', 1));
    $p->apply(num('dup', 100));
    same(['total' => 1], $p->state(), '换 payload 重复投递后的状态');
});

// ---------------------------------------------------------------------------
// 6~7 规范形式
// ---------------------------------------------------------------------------

tcase('state() 顶层键按字节序升序', static function (): void {
    $p = new Projection();
    $p->registerType('kv', kv());
    $p->apply(new Event('k1', 'kv', 3, ['k' => 'zulu', 'v' => 1]));
    $p->apply(new Event('k2', 'kv', 3, ['k' => 'alpha', 'v' => 2]));
    $p->apply(new Event('k3', 'kv', 3, ['k' => 'Mike', 'v' => 3]));
    same(['Mike', 'alpha', 'zulu'], array_keys($p->state()), '字节序键');
});

tcase('state() 嵌套数组的键也按字节序升序', static function (): void {
    $p = new Projection();
    $p->registerType('kv', kv(true));
    $p->apply(new Event('n1', 'kv', 3, ['k' => 'zulu', 'v' => 1]));
    $p->apply(new Event('n2', 'kv', 3, ['k' => 'alpha', 'v' => 2]));
    same(['inner'], array_keys($p->state()), '顶层键');
    same(['alpha', 'zulu'], array_keys($p->state()['inner']), '嵌套键');
});

// ---------------------------------------------------------------------------
// 8~12 未注册类型的三种策略（默认 DEFER）
// ---------------------------------------------------------------------------

tcase('未注册类型默认进入 deferred()', static function (): void {
    $p = new Projection();
    $p->apply(num('d1', 1));
    $p->apply(num('d2', 2));
    same(2, count($p->deferred()), 'deferred 条数');
    same('d1', $p->deferred()[0]->eventId, 'deferred 首条');
    same([], $p->state(), '未注册时状态不变');
});

tcase('flush() 在类型仍未注册时保持 deferred 不变', static function (): void {
    $p = new Projection();
    $p->apply(num('h1', 1));
    $p->apply(num('h2', 2));
    $p->flush();
    same(['h1', 'h2'], array_map(static fn (Event $e): string => $e->eventId, $p->deferred()), 'flush 后的 deferred');
    same([], $p->state(), '状态仍为空');

    $p->flush();
    same(2, count($p->deferred()), '重复 flush 不吞事件');
});

tcase('flush() 在类型注册后按原投递顺序应用', static function (): void {
    $p = new Projection();
    $p->apply(new Event('f1', 'num', 3, ['i' => 1]));
    $p->apply(new Event('f2', 'num', 3, ['i' => 2]));
    $p->apply(new Event('f3', 'num', 3, ['i' => 3]));
    same([], $p->state(), '注册前不解算');

    $p->registerType('num', static function (array $state, array $payload): array {
        $state['trace'] = ($state['trace'] ?? '') . (string) $payload['i'];

        return $state;
    });
    $p->flush();
    same(['trace' => '123'], $p->state(), 'flush 后按原投递顺序应用');
    same([], $p->deferred(), 'flush 清空 deferred');
});

tcase('IGNORE 策略跳过并记入 skipped()', static function (): void {
    $p = new Projection(null, null, Projection::IGNORE);
    $p->registerType('num', adder());
    $p->apply(new Event('i1', 'ghost', 3, ['n' => 1]));
    $p->apply(num('i2', 2));
    same(['i1'], $p->skipped(), 'skipped 列表');
    same([], $p->deferred(), 'IGNORE 不进 deferred');
    same(['total' => 2], $p->state(), '已注册类型照常计入');
});

tcase('FAIL 策略抛 ProjectionError', static function (): void {
    $p = new Projection(null, null, Projection::FAIL);
    $p->registerType('num', adder());
    $p->apply(num('ok', 1));

    $thrown = null;

    try {
        $p->apply(new Event('bad', 'ghost', 3, ['n' => 9]));
    } catch (ProjectionError $exc) {
        $thrown = $exc;
    }

    truthy($thrown instanceof ProjectionError, 'FAIL 策略抛 ProjectionError');
    same(ProjectionError::UNKNOWN_TYPE, $thrown->kind, '错误种类');
    same(['total' => 1], $p->state(), '抛错不影响已解算的状态');
});

// ---------------------------------------------------------------------------
// 13~14 最小快照往返
// ---------------------------------------------------------------------------

tcase('snapshot() 往返后状态一致', static function (): void {
    $p = new Projection();
    $p->registerType('num', adder());
    $p->apply(num('s1', 2));
    $p->apply(num('s2', 3));

    $blob = $p->snapshot();
    truthy($blob !== '', 'blob 不为空');
    same($blob, $p->snapshot(), '同一状态重复导出逐字节相同');

    $q = new Projection();
    $q->registerType('num', adder());
    $q->restore($blob);
    same(['total' => 5], $q->state(), '还原后的状态');
});

tcase('restore() 后继续投递与从零全量重放一致', static function (): void {
    $p = new Projection();
    $p->registerType('num', adder());
    $p->apply(num('r1', 1));
    $p->apply(num('r2', 2));
    $blob = $p->snapshot();

    $q = new Projection();
    $q->registerType('num', adder());
    $q->restore($blob);
    $q->apply(num('r3', 4));
    $q->apply(num('r4', 8));

    $full = new Projection();
    $full->registerType('num', adder());
    $full->applyAll([num('r1', 1), num('r2', 2), num('r3', 4), num('r4', 8)]);

    same($full->state(), $q->state(), '续放与全量重放');
    same(json_encode($full->state()), json_encode($q->state()), '逐字节相同');
});

// ---------------------------------------------------------------------------

printf("通过 %d/%d\n", $GLOBALS['t_pass'], $GLOBALS['t_total']);
exit($GLOBALS['t_pass'] === $GLOBALS['t_total'] ? 0 : 1);
