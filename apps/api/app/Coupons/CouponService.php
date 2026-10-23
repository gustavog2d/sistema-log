<?php

namespace App\Coupons;

use App\Models\Plan;
use App\Models\PlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Cupons de desconto (PLT-012). O super admin cria; o cadastro consome. Toda escrita usa o papel de plataforma,
 * e o uso é contado na linha do cupom travada, na mesma transação do cadastro.
 */
class CouponService
{
    public const INVALID = 'Cupom inválido ou indisponível.';

    public function __construct(private readonly PlatformAudit $audit) {}

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** Preço com o desconto, arredondado para baixo em centavos. */
    public static function priceAfter(int $priceCents, int $percentOff): int
    {
        return intdiv($priceCents * (100 - $percentOff), 100);
    }

    /** O cupom se estiver valendo agora; qualquer outro caso (não existe, inativo, vencido, esgotado) dá `null`. */
    public function available(string $code, ?Connection $db = null, bool $lock = false): ?stdClass
    {
        $query = ($db ?? DB::connection('pgsql_platform'))->table('coupons')->where('code', self::normalize($code));
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $coupon instanceof stdClass) {
            return null;
        }

        $now = now();
        $started = ! is_string($coupon->valid_from) || $now->greaterThanOrEqualTo(Carbon::parse($coupon->valid_from, 'UTC'));
        $notEnded = ! is_string($coupon->valid_until) || $now->lessThan(Carbon::parse($coupon->valid_until, 'UTC'));
        $hasRoom = ! is_numeric($coupon->max_redemptions) || self::int($coupon->redeemed_count) < self::int($coupon->max_redemptions);

        return $coupon->status === 'active' && $started && $notEnded && $hasRoom ? $coupon : null;
    }

    /**
     * Preço do ciclo e preço final com o cupom (se houver). Só o cupom de 100% é cortesia e dispensa o cartão.
     *
     * @return array{price: int, final: int, coupon: stdClass|null, courtesy: bool}
     *
     * @throws ValidationException
     */
    public function quote(Plan $plan, string $cycle, ?string $code, string $field): array
    {
        $price = $cycle === 'yearly' ? ($plan->yearlyPriceCents() ?? 0) : ($plan->monthly_price_cents ?? 0);
        $coupon = $code === null ? null : $this->availableOrFail($code, $field);

        return [
            'price' => $price,
            'final' => $coupon === null ? $price : self::priceAfter($price, self::percentOf($coupon)),
            'coupon' => $coupon,
            'courtesy' => $coupon !== null && self::percentOf($coupon) === 100,
        ];
    }

    /** @throws ValidationException */
    public function availableOrFail(string $code, string $field, ?Connection $db = null, bool $lock = false): stdClass
    {
        return $this->available($code, $db, $lock) ?? throw ValidationException::withMessages([$field => self::INVALID]);
    }

    /**
     * Conta o uso e grava o registro, com o cupom travado (o último uso nunca é dado a dois cadastros).
     *
     * @throws ValidationException
     */
    public function redeem(Connection $db, string $code, string $tenantId, string $subscriptionId, int $originalCents, int $finalCents): stdClass
    {
        $coupon = $this->availableOrFail($code, 'coupon_code', $db, lock: true);

        $db->table('coupons')->where('id', $coupon->id)->update(['redeemed_count' => self::int($coupon->redeemed_count) + 1, 'updated_at' => now()]);
        $db->table('coupon_redemptions')->insert([
            'id' => (string) Str::uuid(), 'coupon_id' => $coupon->id, 'tenant_id' => $tenantId, 'subscription_id' => $subscriptionId,
            'percent_off' => $coupon->percent_off, 'original_price_cents' => $originalCents, 'final_price_cents' => $finalCents,
        ]);

        return $coupon;
    }

    public function recordRedeemed(stdClass $coupon, string $tenantId): void
    {
        $this->audit->record('coupon.redeemed', null, ['coupon' => $coupon->id, 'code' => $coupon->code, 'tenant' => $tenantId]);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_values(DB::connection('pgsql_platform')->table('coupons')->orderBy('created_at')->orderBy('code')->get()
            ->map(fn (stdClass $row): array => $this->present($row))->all());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(PlatformUser $actor, array $data): array
    {
        $id = (string) Str::uuid();

        try {
            DB::connection('pgsql_platform')->table('coupons')->insert([
                'id' => $id, 'code' => self::normalize(self::text($data['code'])), 'percent_off' => $data['percent_off'],
                'max_redemptions' => $data['max_redemptions'] ?? null, 'valid_from' => self::utc($data['valid_from'] ?? null),
                'valid_until' => self::utc($data['valid_until'] ?? null), 'description' => $data['description'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Já existe um cupom com este código.']);
        }

        $this->audit->record('coupon.created', $actor, ['coupon' => $id, 'code' => self::normalize(self::text($data['code'])), 'percent_off' => $data['percent_off']]);

        return $this->present($this->row($id));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(PlatformUser $actor, string $id, array $data): array
    {
        $before = [];
        $changes = DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($id, $data, &$before): array {
            $coupon = $db->table('coupons')->where('id', $id)->lockForUpdate()->first();
            abort_unless($coupon instanceof stdClass, 404);

            if (array_key_exists('max_redemptions', $data) && $data['max_redemptions'] !== null && self::int($data['max_redemptions']) < self::int($coupon->redeemed_count)) {
                throw ValidationException::withMessages(['max_redemptions' => 'O limite não pode ser menor que os usos já feitos ('.self::int($coupon->redeemed_count).').']);
            }

            foreach (['valid_from', 'valid_until'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = self::utc($data[$field]);
                }
            }

            $from = array_key_exists('valid_from', $data) ? $data['valid_from'] : $coupon->valid_from;
            $until = array_key_exists('valid_until', $data) ? $data['valid_until'] : $coupon->valid_until;

            if ($from !== null && $until !== null && strtotime(self::text($until)) <= strtotime(self::text($from))) {
                throw ValidationException::withMessages(['valid_until' => 'O fim da validade precisa ser depois do início.']);
            }

            // Cortesia nunca fica sem teto, nem depois de criada.
            if ($coupon->percent_off === 100 && array_key_exists('max_redemptions', $data) && $data['max_redemptions'] === null) {
                throw ValidationException::withMessages(['max_redemptions' => 'Cupom de 100% precisa de um limite de usos.']);
            }

            $before = (array) $coupon;

            if ($data !== []) {
                $db->table('coupons')->where('id', $id)->update($data + ['updated_at' => now()]);
            }

            return $data;
        });

        if ($changes !== []) {
            $this->audit->record('coupon.updated', $actor, ['coupon' => $id, 'before' => array_intersect_key($before, $changes), 'after' => $changes]);
        }

        return $this->present($this->row($id));
    }

    public static function percentOf(stdClass $coupon): int
    {
        return self::int($coupon->percent_off);
    }

    /** O banco guarda UTC sem fuso: a data recebida com deslocamento (ex.: -03:00) é convertida antes de gravar. */
    private static function utc(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->utc()->format('Y-m-d H:i:s') : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function row(string $id): stdClass
    {
        $row = DB::connection('pgsql_platform')->table('coupons')->where('id', $id)->first();
        abort_unless($row instanceof stdClass, 404);

        return $row;
    }

    /** @return array<string, mixed> */
    private function present(stdClass $row): array
    {
        $date = fn (mixed $value): ?string => is_string($value) ? Carbon::parse($value, 'UTC')->toIso8601String() : null;

        return [
            'id' => $row->id, 'code' => $row->code, 'percent_off' => self::int($row->percent_off), 'status' => $row->status,
            'max_redemptions' => $row->max_redemptions === null ? null : self::int($row->max_redemptions),
            'redeemed_count' => self::int($row->redeemed_count), 'valid_from' => $date($row->valid_from), 'valid_until' => $date($row->valid_until),
            'description' => $row->description, 'created_at' => $date($row->created_at),
        ];
    }
}
