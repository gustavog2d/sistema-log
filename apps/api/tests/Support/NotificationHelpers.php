<?php

use App\Notifications\TransactionalMail;
use Illuminate\Support\Facades\Mail;

/** Quantos avisos desse tipo estão na fila (para o endereço, se informado). */
function queuedCount(string $kind, ?string $to = null): int
{
    return Mail::queued(TransactionalMail::class, fn (TransactionalMail $m) => $m->kind === $kind && ($to === null || $m->hasTo($to)))->count();
}

function allQueued(): int
{
    return Mail::queued(TransactionalMail::class)->count();
}
