<?php

namespace App\Tenancy;

use Illuminate\Container\Container;

final class Slug
{
    /** @var list<string> */
    public const RESERVED = [
        'www', 'admin', 'api', 'app', 'mail', 'static', 'assets',
        'status', 'docs', 'blog', 'suporte', 'help',
    ];

    private const PATTERN = '/^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])\z/';

    /** @return list<string> Lista da configuração (`tenancy.reserved_slugs`) quando há aplicação; senão a padrão. */
    public static function reserved(): array
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return self::RESERVED;
        }

        /** @var list<string> */
        return $container->make('config')->get('tenancy.reserved_slugs', self::RESERVED);
    }

    /**
     * @param  list<string>|null  $reserved
     */
    public static function isValid(string $slug, ?array $reserved = null): bool
    {
        if (preg_match(self::PATTERN, $slug) !== 1) {
            return false;
        }

        return ! in_array($slug, $reserved ?? self::reserved(), true);
    }
}
