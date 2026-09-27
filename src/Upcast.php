<?php

declare(strict_types=1);

namespace Evolveproj;

/**
 * 升版器注册表：把旧版本事件逐级升到当前版本。
 *
 * 对外契约（README「对外契约」1~3）：
 *   - register(int $from, callable $fn)：注册 vN → vN+1 的升版器；
 *     同一 from 重复注册 → ProjectionError(kind=upcast_duplicate)；
 *   - apply(Event $e): Event —— 从事件自带版本逐级升到当前版本，每级恰好应用一次；
 *     缺失中间版本 → ProjectionError(kind=upcast_gap)；
 *     事件版本高于当前版本 → ProjectionError(kind=upcast_ahead)；
 *   - 升版器签名固定为 fn(array $payload): array，只改 payload，不改 eventId / type。
 */
final class Upcast
{
    /** 当前模型版本：apply() 的终点。 */
    public const CURRENT_VERSION = 3;

    /**
     * 注册 v$from → v($from + 1) 的升版器。
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     */
    public function register(int $from, callable $fn): void
    {
        throw new \RuntimeException('not implemented');
    }

    /**
     * 把事件升到当前版本。
     */
    public function apply(Event $event): Event
    {
        throw new \RuntimeException('not implemented');
    }
}
