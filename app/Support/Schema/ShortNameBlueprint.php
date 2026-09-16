<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema blueprint that keeps generated index/key names within MySQL's
 * 64-character identifier limit.
 *
 * Several module migrations call `$table->index([...])` / `$table->unique([...])`
 * with enough columns that the auto-generated
 * "<table>_<col1>_<col2>_..._{index|unique}" name exceeds 64 characters. MySQL
 * rejects those with `SQLSTATE[42000] ... 1059 Identifier name ... is too long`,
 * which aborts `php artisan migrate` and leaves the app container dead on boot.
 *
 * Laravel resolves its Blueprint through the container
 * (`Container::make(Blueprint::class, [...])`), so binding this subclass in
 * AppServiceProvider routes every schema operation here without touching any
 * individual migration. Names longer than 64 chars are truncated to 55 and get
 * a deterministic 8-char md5 suffix — still unique, still stable across runs.
 */
class ShortNameBlueprint extends Blueprint
{
    /** MySQL's hard limit for identifiers. */
    private const MAX_LENGTH = 64;

    /** Length of the deterministic suffix appended to truncated names. */
    private const SUFFIX_LENGTH = 8;

    /**
     * {@inheritDoc}
     */
    protected function createIndexName($type, array $columns)
    {
        $name = parent::createIndexName($type, $columns);

        if (strlen($name) > self::MAX_LENGTH) {
            $name = substr($name, 0, self::MAX_LENGTH - self::SUFFIX_LENGTH - 1)
                .'_'
                .substr(md5($name), 0, self::SUFFIX_LENGTH);
        }

        return $name;
    }
}