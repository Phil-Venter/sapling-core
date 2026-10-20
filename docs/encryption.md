# Encrypting secrets

- [Introduction](#introduction)
- [The app key](#the-app-key)
- [Encrypting and decrypting](#encrypting-and-decrypting)
    - [Contexts](#contexts)
    - [When decrypting fails](#when-decrypting-fails)
- [Method reference](#method-reference)

## Introduction

Some secrets can't be hashed, because the app has to send the original somewhere, such as a tenant's API key for another service. `Sapling\Crypto` encrypts those before they're stored, so a copy of the database without the key reveals nothing. It uses XChaCha20-Poly1305 from PHP's sodium extension, keyed with the app's `app_key`.

A password only needs checking, so hash it with `password_hash()` instead.

## The app key

`Crypto` uses one key, `app_key`, which is 32 random bytes in base64. A new app from the skeleton gets one in `.env`, and `config.php` passes it on:

```php
'app_key' => $env['APP_KEY'] ?? '',
```

Each environment, such as your laptop and production, needs its own key. Every server in one environment uses the same key, or it can't decrypt what the others stored. To generate a key:

```sh
php -r 'echo "APP_KEY=" . base64_encode(random_bytes(32)), PHP_EOL;'
```

A key that isn't 32 bytes in base64 throws an `InvalidArgumentException` the first time `Crypto` is used. Keep the key out of database backups, or anyone with a backup can decrypt the secrets. Back it up somewhere else, because without it nothing can be decrypted.

## Encrypting and decrypting

Ask for `Crypto` in a constructor. `encrypt()` turns a secret into base64 text that fits in a database column, and `decrypt()` turns it back:

```php
$stored = $this->crypto->encrypt($apiKey, "tenant:{$tenantId}:evernote");

// later
$apiKey = $this->crypto->decrypt($stored, "tenant:{$tenantId}:evernote");
```

Each value gets a fresh random nonce, so encrypting the same secret twice gives different text.

### Contexts

The second argument is a context, a string that ties the value to the place it's stored, such as the tenant and the service above. A value only decrypts with the context it was encrypted with, so a value copied into another tenant's row gives `null` rather than the other tenant's secret.

### When decrypting fails

`decrypt()` never throws. It returns `null` when the value was changed, the context differs or the key is wrong:

```php
$apiKey = $this->crypto->decrypt($stored, "tenant:{$tenantId}:evernote");

if ($apiKey === null) {
    return Response::redirect('/settings/evernote/connect');
}
```

## Method reference

| Method | What it does |
| --- | --- |
| `encrypt($secret, $context)` | Returns the secret encrypted, as base64 text for a database column |
| `decrypt($stored, $context)` | Returns the secret, or `null` when the value was changed, the context differs or the key is wrong |
| `new Crypto($key)` | Takes `app_key`. Throws `InvalidArgumentException` when it isn't 32 bytes in base64. `App::boot()` registers it |
