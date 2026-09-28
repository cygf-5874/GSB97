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
        $ids = array_values($seen);
        sort($ids, SORT_STRING);

        $blob = json_encode(
            [
                'format' => self::FORMAT_VERSION,
                'modelVersion' => $modelVersion,
                'state' => self::canonical($state),
                'seen' => $ids,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($blob === false) {
            throw new ProjectionError('快照序列化失败');
        }

        return $blob;
    }

    /**
     * @return array{modelVersion: int, state: array<string, mixed>, seen: list<string>}
     */
    public function unpack(string $blob): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($blob, true);

        if (!is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT_VERSION) {
            throw new ProjectionError('快照 blob 无法解析', ProjectionError::SNAPSHOT_BLOB);
        }

        $modelVersion = $decoded['modelVersion'] ?? null;
        $state = $decoded['state'] ?? null;
        $seen = $decoded['seen'] ?? null;

        if (!is_int($modelVersion) || !is_array($state) || !is_array($seen)) {
            throw new ProjectionError('快照 blob 结构不完整', ProjectionError::SNAPSHOT_BLOB);
        }

        $ids = [];
        foreach ($seen as $id) {
            if (!is_string($id)) {
                throw new ProjectionError('快照 blob 的去重集合含非字符串', ProjectionError::SNAPSHOT_BLOB);
            }

            $ids[] = $id;
        }

        return [
            'modelVersion' => $modelVersion,
            'state' => $state,
            'seen' => $ids,
        ];
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
