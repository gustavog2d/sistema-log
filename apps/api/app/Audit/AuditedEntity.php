<?php

namespace App\Audit;

/** Entidade de negócio com histórico (FND-008): quais campos auditar e o nome do tipo. Veja o trait {@see Audited}. */
interface AuditedEntity
{
    /** @return list<string> campos que entram no histórico (nunca senhas, tokens ou segredos) */
    public function auditedFields(): array;

    /** Tipo da entidade no histórico (`client`, `appointment`…). */
    public function auditType(): string;
}
