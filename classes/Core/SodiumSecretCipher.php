<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\SecretCipher;
use PKP\config\Config;
use RuntimeException;

/**
 * SecretCipher with libsodium (XSalsa20-Poly1305). The key is derived from
 * the installation's own secret in config.inc.php ([general] app_key, or
 * [security] salt on installations without it), which is not stored in the
 * database. When that secret changes, stored keys cannot be opened and the
 * manager enters them again.
 */
final class SodiumSecretCipher implements SecretCipher
{
    private string $key;

    public function __construct(?string $material = null)
    {
        $material ??= (string) Config::getVar('general', 'app_key', '');
        if ($material === '') {
            $material = (string) Config::getVar('security', 'salt', '');
        }
        if ($material === '') {
            throw new RuntimeException('No app_key or salt in config.inc.php to derive the secrets key from.');
        }
        $this->key = hash_hkdf('sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'conferenceManager secrets v1');
    }

    public function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function open(string $sealed): ?string
    {
        if (!str_starts_with($sealed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        return $plain === false ? null : $plain;
    }
}
