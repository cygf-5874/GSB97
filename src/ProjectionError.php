<?php

declare(strict_types=1);

namespace Evolveproj;

/**
 * 投影层的统一异常。所有「契约里说会报错」的地方都抛它，不抛原生 Warning / TypeError。
 *
 * $kind 是对外契约的一部分，取值固定：
 *   'upcast_duplicate' 同一 from 重复注册升版器；
 *   'upcast_gap'       缺失中间版本，无法逐级升到当前版本；
 *   'upcast_ahead'     事件自带版本高于当前版本；
 *   'unknown_type'     未注册的事件类型，且策略为 FAIL；
 *   'snapshot_version' 快照 blob 的版本标记与当前模型版本不匹配；
 *   'snapshot_blob'    blob 不是本库写出的格式（解析失败）；
 *   'generic'          其它投影错误。
 */
class ProjectionError extends \RuntimeException
{
    public const DUPLICATE_STEP = 'upcast_duplicate';

    public const UPCAST_GAP = 'upcast_gap';

    public const UPCAST_AHEAD = 'upcast_ahead';

    public const UNKNOWN_TYPE = 'unknown_type';

    public const SNAPSHOT_VERSION = 'snapshot_version';

    public const SNAPSHOT_BLOB = 'snapshot_blob';

    public const GENERIC = 'generic';

    public readonly string $kind;

    public function __construct(string $message, string $kind = self::GENERIC, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->kind = $kind;
    }
}
