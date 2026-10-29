# Encrypting secrets

- [Introduction](#introduction)
- [The app key](#the-app-key)
- [Encrypting and decrypting](#encrypting-and-decrypting)
    - [Contexts](#contexts)
    - [When decrypting fails](#when-decrypting-fails)
- [Method reference](#method-reference)

## Introduction

`Sapling\Crypto` encrypts secrets the app stores and later sends on, such as a tenant's API key for another service. It uses XChaCha20-Poly1305 from PHP's sodium extension, keyed with the app's `app_key`.

## The app key

`Crypto` uses one key, `app_key`, which is 32 random bytes in base64. A new app from the skeleton gets one in `.env`, and `config.php` passes it on:

```php
'app_key' => $env['APP_KEY'] ?? '',
```

A value only decrypts with the key it was encrypted with, so every server that shares a database needs the same key. To make a new key:

```sh
php -r 'echo "APP_KEY=" . base64_encode(random_bytes(32)), PHP_EOL;'
```

A key that isn't 32 bytes in base64 throws an `InvalidArgumentException` the first time `Crypto` is used. Once the key is lost, nothing encrypted with it can be read again.

## Encrypting and decrypting

Ask for `Crypto` in a constructor. `encrypt()` turns a secret into base64 text that fits in a database column, and `decrypt()` turns it back:

```php
$stored = $this->crypto->encrypt($apiKey, "tenant:{$tenantId}:evernote");

// later
$apiKey = $this->crypto->decrypt($stored, "tenant:{$tenantId}:evernote");
```

Encrypting the same secret twice gives different text, since each value gets a fresh random nonce.

### Contexts

The second argument is a context, a string that ties the value to the place it's stored, such as the tenant and the service above. A value only decrypts with the context it was encrypted with, and one copied into another tenant's row gives `null` instead of the first tenant's secret.

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
