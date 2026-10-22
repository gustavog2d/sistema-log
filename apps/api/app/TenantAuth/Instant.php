<?php

namespace App\TenantAuth;

/**
 * Instante com microssegundos para as colunas `timestamp(6)` de tokens e cortes de validade. O construtor de
 * consultas do Laravel grava datas só até o segundo; sem isso, um token criado no mesmo segundo do corte
 * (trocar a senha, sair de todos os acessos) não seria distinguido dele.
 */
final class Instant
{
    public static function now(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }
}
