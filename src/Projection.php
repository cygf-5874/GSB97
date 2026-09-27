<?php

declare(strict_types=1);

namespace Evolveproj;

/**
 * 事件溯源投影：按投递顺序重放事件、维护规范状态、导出/载入快照。
 *
 * 起点能力（已可用）：单版本重放 + 快照往返。
 *   - 事件类型注册与投递、同 eventId 幂等（内存去重）、state() 规范形式；
 *   - 未注册类型的三种策略 IGNORE / FAIL / DEFER，以及 flush()；
 *   - snapshot() / restore() 的最小往返：**只存状态**，不带去重集合、不带版本标记。
 *
 * 两个可插拔位由外部注入，构造时不给就走上面那条最小路径：
 *   - $upcast   ：把旧版本事件逐级升到当前版本（见 Upcast）；
 *   - $snapshot ：快照 blob 的编解码，负责把去重集合与版本标记一并持久化（见 Snapshot）。
 */
final class Projection
{
    /** 当前模型版本：registerType 的语义以它为准。 */
    public const MODEL_VERSION = 3;

    /** 未注册的事件类型：跳过并记入 skipped[]。 */
    public const IGNORE = 'ignore';

    /** 未注册的事件类型：抛 ProjectionError。 */
    public const FAIL = 'fail';

    /** 未注册的事件类型：缓存进 deferred[]，注册后 flush()。 */
    public const DEFER = 'defer';

    /** @var array<string, mixed> */
    private array $state = [];

    /** @var array<string, callable> */
    private array $handlers = [];

    /** @var array<string, true> 已计入投影的 eventId（去重集合） */
    private array $seen = [];

    /** @var list<string> 被 IGNORE 跳过的 eventId，保持投递顺序 */
    private array $skipped = [];

    /** @var list<Event> 被 DEFER 缓存的事件，保持投递顺序 */
    private array $deferred = [];

    private readonly ?Upcast $upcast;

    private readonly ?Snapshot $snapshot;

    private readonly string $unknownPolicy;

    public function __construct(
        ?Upcast $upcast = null,
        ?Snapshot $snapshot = null,
        string $unknownPolicy = self::DEFER,
    ) {
        if (!in_array($unknownPolicy, [self::IGNORE, self::FAIL, self::DEFER], true)) {
            throw new ProjectionError('未知的未注册类型策略: ' . $unknownPolicy);
        }

        $this->upcast = $upcast;
        $this->snapshot = $snapshot;
        $this->unknownPolicy = $unknownPolicy;
    }

    public function unknownPolicy(): string
    {
        return $this->unknownPolicy;
    }

    /**
     * 注册某个事件类型的处理器。处理器拿到「当前状态 + 事件 payload」，返回新状态。
     *
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $handler
     */
    public function registerType(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function hasType(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /** 投递一条事件。同一 eventId 只计入一次。 */
    public function apply(Event $event): void
    {
        if (isset($this->seen[$event->eventId])) {
            return;
        }

        $resolved = $this->upcast === null ? $event : $this->upcast->apply($event);

        if (!isset($this->handlers[$resolved->type])) {
            if ($this->unknownPolicy === self::FAIL) {
                // FAIL 策略下不占用 eventId：调用方修好注册表后重投仍应生效。
                throw new ProjectionError(
                    '未注册的事件类型: ' . $resolved->type,
                    ProjectionError::UNKNOWN_TYPE
                );
            }

            $this->seen[$event->eventId] = true;

            if ($this->unknownPolicy === self::IGNORE) {
                $this->skipped[] = $event->eventId;

                return;
            }

            $this->deferred[] = $resolved;

            return;
        }

        $this->seen[$event->eventId] = true;
        $this->dispatch($resolved);
    }

    /**
     * 按投递顺序批量投递。
     *
     * @param iterable<Event> $events
     */
    public function applyAll(iterable $events): void
    {
        foreach ($events as $event) {
            $this->apply($event);
        }
    }

    /**
     * 重新投递 deferred[]。此时已注册的类型按**原投递顺序**应用；
     * 仍未注册的类型原样留在 deferred[] 里（位置与顺序都不变）。
     */
    public function flush(): void
    {
        $pending = $this->deferred;
        $this->deferred = [];

        foreach ($pending as $event) {
            if (isset($this->handlers[$event->type])) {
                $this->dispatch($event);
                continue;
            }

            $this->deferred[] = $event;
        }
    }

    /**
     * 规范状态：所有键按字节序升序，嵌套数组同样处理。
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return self::canonical($this->state);
    }

    /** @return list<string> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<Event> */
    public function deferred(): array
    {
        return $this->deferred;
    }

    /**
     * 导出快照 blob。
     *
     * 不注入 Snapshot 时走最小往返：只序列化状态。注入 Snapshot 后由它负责把
     * 去重集合与版本标记一并写进 blob。
     */
    public function snapshot(): string
    {
        if ($this->snapshot !== null) {
            return $this->snapshot->pack($this->state(), $this->seenIds(), self::MODEL_VERSION);
        }

        $blob = json_encode(
            ['state' => $this->state()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($blob === false) {
            throw new ProjectionError('快照序列化失败');
        }

        return $blob;
    }

    /**
     * 载入快照 blob，覆盖当前状态与去重集合。
     *
     * 注入 Snapshot 时由它解码；blob 的版本标记与当前模型版本不一致 → ProjectionError。
     */
    public function restore(string $blob): void
    {
        if ($this->snapshot !== null) {
            $data = $this->snapshot->unpack($blob);
        } else {
            /** @var mixed $decoded */
            $decoded = json_decode($blob, true);
            if (!is_array($decoded) || !isset($decoded['state']) || !is_array($decoded['state'])) {
                throw new ProjectionError('快照 blob 无法解析', ProjectionError::SNAPSHOT_BLOB);
            }

            // 最小往返不带版本标记，视作当前版本。
            $data = [
                'modelVersion' => self::MODEL_VERSION,
                'state' => $decoded['state'],
                'seen' => [],
            ];
        }

        if (($data['modelVersion'] ?? null) !== self::MODEL_VERSION) {
            throw new ProjectionError(
                '快照版本不匹配: 期望 ' . self::MODEL_VERSION . ' 实际 '
                    . var_export($data['modelVersion'] ?? null, true),
                ProjectionError::SNAPSHOT_VERSION
            );
        }

        $state = $data['state'] ?? null;
        if (!is_array($state)) {
            throw new ProjectionError('快照里的状态不是数组', ProjectionError::SNAPSHOT_BLOB);
        }

        $this->state = $state;
        $this->seen = [];
        $this->skipped = [];
        $this->deferred = [];

        foreach (($data['seen'] ?? []) as $id) {
            if (is_string($id) && $id !== '') {
                $this->seen[$id] = true;
            }
        }
    }

    /** @return list<string> 去重集合，按字节序升序（与数组 hash 顺序无关） */
    private function seenIds(): array
    {
        $ids = array_keys($this->seen);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** 应用一条「已升到当前版本、类型也已注册」的事件。 */
    private function dispatch(Event $event): void
    {
        $handler = $this->handlers[$event->type];
        $this->state = $handler($this->state, $event->payload);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
