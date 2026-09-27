<?php

declare(strict_types=1);

namespace Evolveproj;

/**
 * 快照 blob 的编解码器：把投影状态、去重集合与版本标记一起持久化。
 *
 * 对外契约（README「对外契约」4~5、8）：
 *   - pack(array $state, array $seen, int $modelVersion): string
 *       写出自包含 blob：带格式标记与模型版本标记，$seen 是已按字节序升序排好的
 *       eventId 列表，必须一起写进去，否则 restore 之后续放会把快照前的事件再算一遍；
 *   - unpack(string $blob): array
 *       还原成 ['modelVersion' => int, 'state' => array, 'seen' => list<string>]；
 *       blob 不是本库写出的格式 → ProjectionError(kind=snapshot_blob)。
 *
 * 版本标记与当前模型版本是否匹配由 Projection::restore 判定，这里只负责如实还原。
 */
final class Snapshot
{
    /** blob 自身的格式版本标记。 */
    public const FORMAT_VERSION = 1;

    /**
     * @param array<string, mixed> $state
     * @param list<string>         $seen
     */
    public function pack(array $state, array $seen, int $modelVersion): string
    {
        throw new \RuntimeException('not implemented');
    }

    /**
     * @return array{modelVersion: int, state: array<string, mixed>, seen: list<string>}
     */
    public function unpack(string $blob): array
    {
        throw new \RuntimeException('not implemented');
    }
}
