<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Encrypts secrets (payment gateway keys) before they are stored in the
 * database, so a database dump or backup alone does not reveal them.
 */
interface SecretCipher
{
    /** Marks a stored value as sealed by this plugin. */
    public const PREFIX = 'cmenc1:';

    public function seal(string $plain): string;

    /** The plain value, or null when the value cannot be opened (key changed, damaged). */
    public function open(string $sealed): ?string;
}
