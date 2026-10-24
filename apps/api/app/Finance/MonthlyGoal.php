<?php

namespace App\Finance;

use Illuminate\Database\UniqueConstraintViolationException;

/** Meta mensal de receita do negócio (FIN-003): uma só, vale para todos os meses. */
class MonthlyGoal
{
    private const KEY = 'monthly_goal_cents';

    public function get(): ?int
    {
        $value = TenantSetting::query()->where('key', self::KEY)->value('value');

        return is_numeric($value) ? (int) $value : null;
    }

    public function set(?int $cents): ?int
    {
        $setting = TenantSetting::query()->where('key', self::KEY)->first();

        if ($cents === null) {
            $setting?->delete();

            return null;
        }

        if ($setting === null) {
            try {
                TenantSetting::create(['key' => self::KEY, 'value' => (string) $cents]);
            } catch (UniqueConstraintViolationException) {
                // Outra gravação criou a meta agora: sobrescreve com o valor mais novo.
                TenantSetting::query()->where('key', self::KEY)->firstOrFail()->fill(['value' => (string) $cents])->save();
            }
        } elseif ($setting->value !== (string) $cents) {
            $setting->fill(['value' => (string) $cents])->save();
        }

        return $cents;
    }
}
