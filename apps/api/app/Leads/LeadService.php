<?php

namespace App\Leads;

use App\Models\PlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** Captura e gestão dos leads da landing (PLT-011). Escreve com o papel de plataforma. */
class LeadService
{
    public const PUBLIC_FIELDS = [
        'name', 'email', 'phone', 'niche', 'message', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer', 'landing_path',
    ];

    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * Grava o lead. O mesmo e-mail em 24 horas não cria outro: só completa telefone e mensagem se vierem preenchidos.
     *
     * @param  array<string, mixed>  $data  campos de {@see self::PUBLIC_FIELDS}, já validados
     */
    public function capture(array $data, ?string $ip): void
    {
        $db = DB::connection('pgsql_platform');
        $email = mb_strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
        $recent = $db->table('leads')->whereRaw('lower(email) = ?', [$email])->where('created_at', '>', now()->subDay())->orderByDesc('created_at')->first();

        if ($recent instanceof stdClass) {
            $update = array_filter(['phone' => $data['phone'] ?? null, 'message' => $data['message'] ?? null], fn (mixed $value): bool => is_string($value) && $value !== '');

            if ($update !== []) {
                $db->table('leads')->where('id', $recent->id)->update($update + ['updated_at' => now()]);
            }

            return;
        }

        $row = ['id' => (string) Str::uuid(), 'email' => $email, 'status' => 'new'];

        foreach (self::PUBLIC_FIELDS as $field) {
            $row[$field] ??= $data[$field] ?? null;
        }

        $db->table('leads')->insert($row + [
            // Só o hash do IP, com sal: serve para achar abuso, não para identificar a pessoa.
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, Config::string('app.key')),
            'privacy_accepted_at' => now(), 'privacy_version' => Config::string('leads.privacy_version'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(?string $search, ?string $status, ?string $utmSource, int $limit): array
    {
        $query = DB::connection('pgsql_platform')->table('leads')->orderByDesc('created_at')->orderByDesc('id');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.addcslashes(trim($search), '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term));
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($utmSource !== null) {
            $query->where('utm_source', $utmSource);
        }

        $page = $query->cursorPaginate($limit);

        return [
            'data' => array_values(collect($page->items())->map(fn (mixed $row): array => $this->present($row instanceof stdClass ? $row : new stdClass))->all()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    /**
     * @param  array<string, mixed>  $changes  `status` e/ou `notes`
     * @return array<string, mixed>
     */
    public function update(PlatformUser $actor, string $id, array $changes): array
    {
        $db = DB::connection('pgsql_platform');
        $lead = $db->table('leads')->where('id', $id)->first();
        abort_unless($lead instanceof stdClass, 404);

        if ($changes !== []) {
            $db->table('leads')->where('id', $id)->update($changes + ['updated_at' => now()]);

            // As notas são texto livre da equipe sobre uma pessoa: ficam fora do log.
            $this->audit->record('lead.updated', $actor, ['lead' => $id, 'status_before' => $lead->status, 'status_after' => $changes['status'] ?? $lead->status]);
        }

        return $this->present($db->table('leads')->where('id', $id)->first() ?? $lead);
    }

    /** Apaga os leads descartados e os parados há mais do prazo de retenção; os convertidos ficam. */
    public function prune(): int
    {
        $limit = now()->subMonths(Config::integer('leads.retention_months'));

        return DB::connection('pgsql_platform')->table('leads')
            ->where(fn (Builder $q) => $q->where('status', 'discarded')->orWhere(fn (Builder $old) => $old->whereIn('status', ['new', 'contacted'])->where('updated_at', '<', $limit)))
            ->where('status', '<>', 'converted')
            ->delete();
    }

    /** @return array<string, mixed> */
    private function present(stdClass $row): array
    {
        $date = fn (mixed $value): ?string => is_string($value) ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
        $out = ['id' => $row->id];

        foreach (self::PUBLIC_FIELDS as $field) {
            $out[$field] = $row->{$field};
        }

        return $out + ['status' => $row->status, 'notes' => $row->notes, 'created_at' => $date($row->created_at), 'updated_at' => $date($row->updated_at)];
    }
}
