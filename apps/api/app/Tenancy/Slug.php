<?php

namespace App\Tenancy;

final class Slug
{
    /** @var list<string> */
    public const RESERVED = [
        'www', 'admin', 'api', 'app', 'mail', 'static', 'assets',
        'status', 'docs', 'blog', 'suporte', 'help',
    ];

    private const PATTERN = '/^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/';

    /**
     * @param  list<string>|null  $reserved
     */
    public static function isValid(string $slug, ?array $reserved = null): bool
    {
        if (preg_match(self::PATTERN, $slug) !== 1) {
            return false;
        }

        return ! in_array($slug, $reserved ?? self::RESERVED, true);
    }
}
