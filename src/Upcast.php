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

    /** @var array<int, callable(array<string, mixed>): array<string, mixed>> */
    private array $steps = [];

    /**
     * 注册 v$from → v($from + 1) 的升版器。
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     */
    public function register(int $from, callable $fn): void
    {
        if (isset($this->steps[$from])) {
            throw new ProjectionError(
                '升版器重复注册: v' . $from . ' → v' . ($from + 1),
                ProjectionError::DUPLICATE_STEP
            );
        }

        $this->steps[$from] = $fn;
    }

    /**
     * 把事件升到当前版本。
     */
    public function apply(Event $event): Event
    {
        if ($event->version > self::CURRENT_VERSION) {
            throw new ProjectionError(
                '事件版本高于当前版本: v' . $event->version . ' > v' . self::CURRENT_VERSION,
                ProjectionError::UPCAST_AHEAD
            );
        }

        $resolved = $event;

        for ($version = $event->version; $version < self::CURRENT_VERSION; $version++) {
            if (!isset($this->steps[$version])) {
                throw new ProjectionError(
                    '缺失升版器: v' . $version . ' → v' . ($version + 1),
                    ProjectionError::UPCAST_GAP
                );
            }

            $resolved = $resolved
                ->withPayload(($this->steps[$version])($resolved->payload))
                ->withVersion($version + 1);
        }

        return $resolved;
    }
}
