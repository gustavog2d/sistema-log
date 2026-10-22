<?php

namespace App\Plans;

/** O que um plano (ou uma exceção do assinante) permite para uma funcionalidade. */
final readonly class Entitlement
{
    public function __construct(
        public string $feature,
        public bool $enabled,
        public ?int $limit,
        public bool $visible,
        public string $source,
    ) {}

    /** @return array{feature: string, enabled: bool, limit: int|null, visible: bool, source: string} */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'enabled' => $this->enabled,
            'limit' => $this->limit,
            'visible' => $this->visible,
            'source' => $this->source,
        ];
    }
}
