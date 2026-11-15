<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Where preset files come from (DST-07): the package folder and the
 * folder a site administrator fills without changing the package.
 */
interface PresetSource
{
    /**
     * @return list<array{file: string, source: string, data: mixed, error: ?string}>
     *   data is the decoded JSON; error is set when the file cannot be read or decoded
     */
    public function files(): array;
}
