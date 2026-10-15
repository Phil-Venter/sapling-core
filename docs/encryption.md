# Encrypting secrets

- [Introduction](#introduction)
- [The app key](#the-app-key)
- [Encrypting and decrypting](#encrypting-and-decrypting)
    - [Contexts](#contexts)
    - [When decrypting fails](#when-decrypting-fails)
- [What not to encrypt](#what-not-to-encrypt)
- [Why XChaCha20-Poly1305](#why-xchacha20-poly1305)
- [Method reference](#method-reference)

## Introduction

`Sapling\Crypto` encrypts secrets the app stores and needs back, such as an API key for another service. It uses XChaCha20-Poly1305 from PHP's sodium extension, keyed with `app_key`. A copy of the database alone reveals nothing.

## The app key

`app_key` is 32 random bytes in base64. `vendor/bin/sapling init` writes one into `.env`, and `config.php` reads it:

```php
'app_key' => $env['APP_KEY'] ?? '',
```

To generate another:

```sh
php -r 'echo "APP_KEY=" . base64_encode(random_bytes(32)), PHP_EOL;'
```

An invalid key throws an `InvalidArgumentException` when `Crypto` is first used.

> [!WARNING]
> Don't back up `app_key` with the database. Together they reveal every secret. Lose the key, and nobody can decrypt them, including you.

## Encrypting and decrypting

`encrypt()` returns base64 text for a database column, and `decrypt()` returns the secret:

```php
$stored = $this->crypto->encrypt($apiKey, "tenant:{$tenantId}:evernote");

// later
$apiKey = $this->crypto->decrypt($stored, "tenant:{$tenantId}:evernote");
```

Encrypting the same secret twice gives different text.

### Contexts

The second argument ties the value to where it's stored. A value only decrypts with the same context, so one copied into another tenant's row gives `null`.

### When decrypting fails

`decrypt()` returns `null` when the value was changed, the context differs or the key is wrong:

```php
$apiKey = $this->crypto->decrypt($stored, "tenant:{$tenantId}:evernote");

if ($apiKey === null) {
    return Response::redirect('/settings/evernote/connect');
}
```

> [!TIP]
> Treat `null` as "connect again", not as a crash. Changing `app_key` makes every stored secret unreadable.

## What not to encrypt

Passwords. Hash them with `password_hash()` instead.

## Why XChaCha20-Poly1305

- **It's authenticated.** A tampered value fails to decrypt. The context is authenticated but not stored.
- **Its nonce is 24 bytes,** so each value can safely get a random one, with no counter to keep.
- **It's everywhere.** It ships with sodium and runs in constant time without special CPU support.

## Method reference

| Method | What it does |
| --- | --- |
| `encrypt($secret, $context)` | Returns the secret encrypted, as base64 text for a database column |
| `decrypt($stored, $context)` | Returns the secret, or `null` when the value was changed, the context differs or the key is wrong |
| `new Crypto($key)` | Takes `app_key`. Throws `InvalidArgumentException` when it isn't 32 bytes in base64. `App::boot()` registers it |
