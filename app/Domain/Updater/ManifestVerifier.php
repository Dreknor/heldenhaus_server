<?php

namespace App\Domain\Updater;

/**
 * Prüft die Ed25519-Signatur des Manifests und den SHA-256 des Pakets.
 * Ohne gültige Signatur wird nichts installiert.
 */
class ManifestVerifier
{
    public function __construct(private readonly ?string $publicKey) {}

    /** @return array<string, mixed> das geprüfte Manifest */
    public function verifyManifest(string $manifestJson, string $signature): array
    {
        if (! $this->publicKey) {
            throw new UpdaterException('Kein öffentlicher Schlüssel konfiguriert (UPDATER_PUBLIC_KEY).');
        }

        $key = base64_decode($this->publicKey, true);
        $sig = base64_decode(trim($signature), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new UpdaterException('Schlüssel oder Signatur haben ein ungültiges Format.');
        }

        if (! sodium_crypto_sign_verify_detached($sig, $manifestJson, $key)) {
            throw new UpdaterException('Die Signatur des Updates ist ungültig – Installation abgebrochen.');
        }

        $manifest = json_decode($manifestJson, true);

        foreach (['version', 'sha256', 'min_php'] as $field) {
            if (empty($manifest[$field])) {
                throw new UpdaterException("Manifest unvollständig: {$field} fehlt.");
            }
        }

        return $manifest;
    }

    public function verifyPackage(string $path, string $expectedSha256): void
    {
        if (! is_file($path) || ! hash_equals(strtolower($expectedSha256), hash_file('sha256', $path))) {
            throw new UpdaterException('Die Prüfsumme des Pakets stimmt nicht – Download beschädigt oder manipuliert.');
        }
    }

    /** Vorabprüfung des Servers gegen die Anforderungen des Manifests. */
    public function checkRequirements(array $manifest): array
    {
        $problems = [];

        if (version_compare(PHP_VERSION, $manifest['min_php'], '<')) {
            $problems[] = "PHP {$manifest['min_php']} nötig, vorhanden ist ".PHP_VERSION.'.';
        }

        foreach ($manifest['extensions'] ?? [] as $extension) {
            if (! extension_loaded($extension)) {
                $problems[] = "PHP-Erweiterung fehlt: {$extension}.";
            }
        }

        return $problems;
    }

    /** @return array{public: string, secret: string} base64 */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    public static function sign(string $manifestJson, string $secretKeyBase64): string
    {
        return base64_encode(sodium_crypto_sign_detached($manifestJson, base64_decode($secretKeyBase64)));
    }
}
