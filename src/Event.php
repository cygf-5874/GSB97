<?php

declare(strict_types=1);

namespace Evolveproj;

/**
 * 不可变事件。升版时按「整条替换」的方式造新实例，不做原地修改。
 *
 * $eventId 是幂等去重的唯一依据：同一个 eventId 再次投递不得重复计入投影。
 * $version 是这条事件**写下来时**的 schema 版本（历史事件往往是旧版本）。
 */
final class Event
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly int $version,
        /** @var array<string, mixed> */
        public readonly array $payload = [],
    ) {
    }

    public function withVersion(int $version): self
    {
        return new self($this->eventId, $this->type, $version, $this->payload);
    }

    public function withType(string $type): self
    {
        return new self($this->eventId, $type, $this->version, $this->payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        return new self($this->eventId, $this->type, $this->version, $payload);
    }
}
