# NAF Storage

Provider-independent file storage for NAF. Composer: **`naf/storage`**; GitHub:
**`nafphp/storage`**. Version **0.1.0 is under development**, not released on Packagist.

```php
use function Naf\Storage\storage;

storage()->put('foo.txt', 'Hello World');
storage('documents')->put('invoices/2026/1234.pdf', $contents);
storage('public')->url('avatars/123.jpg');
storage('documents')->exists($path);
storage('documents')->delete($path);
```

Examples run after the application's normal NAF bootstrap. The helper is namespaced,
just like `Naf\Client\client()` and other plugin helpers.

## Installation and configuration

Requires PHP 8.3+ and `naf/framework ^0.2`. Storage has no additional runtime
libraries or extension requirements of its own.

After the first stable release, install using `composer require naf/storage`.
Until then, contributors can install this checkout in a **disposable development
host** using a Composer `path` repository with a `0.1.0` version override. Keep
that development-only repository out of application release manifests.

NAF discovers the installed `naf-plugin` automatically. It loads `src/config.php`
and `src/functions.php`, then the package-root `bootstrap.php`. No additional plugin
registration, HTTP guard, route or framework change is needed.

The default disk is private, rooted at `BASE_PATH . '/storage'`. Root and parent
directories are created on the first write; boot, disk selection and missing-file
checks do not create them. No manual directory preparation is needed.

Merge this into the array returned by the application's **`app/config.php`**:

```php
<?php

use Naf\Storage\Adapters\LocalAdapter;

return [
    'storage' => [
        'default' => 'local',
        'disks'   => [
            'local' => [
                'adapter' => LocalAdapter::class,
                'root'    => BASE_PATH . '/storage',
            ],
            'documents' => [
                'adapter' => LocalAdapter::class,
                'root'    => BASE_PATH . '/storage/documents',
            ],
            'public' => [
                'adapter' => LocalAdapter::class,
                'root'    => BASE_PATH . '/storage/public',
                'url'     => '/storage',
            ],
        ],
    ],
];
```

Configuring the adapter is enough. Applications call `storage()`; NAF wires the
internal classes automatically:

- `StorageManager` selects and caches the configured disk.
- `Storage` is the returned disk object with `put()`, `get()`, `url()` and stream methods.
- `LocalAdapter` performs the actual local file operations.

`Storage` holds no second storage backend. It validates paths and delegates file
operations to the selected adapter; the adapter validates and consumes input streams.
There is no separate upload-staging service in this package.

Read values through NAF, for example `config('storage:default')`. Plugin and application
configuration merge recursively; the application wins. Set an inherited `url` to
`null` when removing its public prefix. Configuration is not a runtime setter.

## File operations and streams

| Storage method | Result |
| --- | --- |
| `put(string $path, string $contents): void` | Write or replace a file |
| `get(string $path): string` | Read all contents into memory |
| `writeStream(string $path, mixed $stream): void` | Write a readable PHP stream resource |
| `readStream(string $path): mixed` | Return a readable PHP stream resource |
| `exists(string $path): bool` | Check for a file; directories are not files |
| `delete(string $path): void` | Delete; missing files are a successful no-op |
| `move(string $source, string $destination): void` | Move within the disk, replacing the destination |
| `copy(string $source, string $destination): void` | Copy within the disk, replacing the destination |
| `url(string $path): string` | Generate a public URL when supported |

Copy/move create destination parents. Same-path operations succeed only if the source
exists. This version has no directory listing or recursive deletion API.

For large files, use streams. `writeStream()` consumes from the current position,
supports non-seekable streams, and never rewinds or closes the caller's stream.
`readStream()` returns a stream at its beginning, which the caller must close.
Input must produce data until EOF; a stalled/non-blocking stream with no available
data causes a failed write, not a completed file.

```php
use function Naf\Storage\storage;

$input = storage('documents')->readStream('exports/report.csv');
try {
    storage()->writeStream('backups/report.csv', $input);
} finally {
    fclose($input);
}
```

This also copies between disks. Local transfers use bounded chunks. Local writes
and copies create a temporary file in the destination directory, then rename it
into place. Failed writes preserve the existing destination and remove temporary
files. Concurrent writes use last-completed-write semantics. Moves preserve source
permissions; writes/copies apply configured file permissions. Crash recovery and
transactions with application metadata remain application responsibilities.

## Public and private files

Without a prefix or a public-URL-capable adapter, `url()` throws `StorageException`.
Prefixes must be root-relative paths or HTTP(S) URLs without credentials, queries
or fragments. Each file path segment is URL-encoded. Generating a URL does not check
existence, create a symlink, configure a web server or publish a file.

Keep private roots **outside `public/`**. To serve the example public disk, explicitly
map `/storage` to **only** `BASE_PATH . '/storage/public'` in your web server. Never
expose `BASE_PATH . '/storage'`, which also contains private disks. Private downloads
must pass application authorization before streaming.

New directories default to **0700**, files to **0600**. This works when PHP and the
serving process share a Unix user. When your deployment needs other permissions,
configure `directoryMode` and `fileMode` on that disk, for example `0755` and `0644`
for intentionally public files. Existing directories are not chmodded. Permissions
do not configure public URLs or application authorization.

## Path safety and exceptions

Validation runs in the package, including standalone `LocalAdapter` calls from CLI
and workers. It rejects empty paths, absolute Unix/Windows paths, backslashes,
schemes/colons, control characters/null bytes, dot segments, repeated/trailing
slashes, trailing dots/spaces and Windows device names. Traversal is never silently
normalized. `attachments/tickets/42/error.png` and ordinary Unicode file names work.
Percent escapes are literal file-name characters; storage does not URL-decode them.

The local adapter rejects symlinks at the root and within the root, including dangling
and destination links, and special files such as FIFOs. The configured root's ancestors
are trusted deployment paths (system paths such as macOS `/tmp` may resolve through
links). The root and ancestors must not be writable by hostile local processes:
portable PHP cannot eliminate races with attackers replacing directories between
checks and I/O.

All disk errors derive from `Naf\Storage\Exceptions\StorageException`:

- Missing read/copy/move sources: `FileNotFoundException`.
- Write and stream failures: `UnableToWriteException`.
- Invalid paths/configuration, unsupported URLs and other I/O errors: `StorageException`.

Native errors retain their previous exception; PHP warnings do not escape the local
adapter. NAF's container may wrap service-factory failures in `ContainerException`.

## DI and future adapters

`StorageManager` is a lazy shared NAF service. `storage()` delegates to
`disk(?string $name = null): Storage`. Each disk gets an adapter cached for the
manager's lifetime. Inject `Storage` for the default disk or `StorageManager`
for named disks. Register application service overrides before first use.

Adapters implement `Naf\Storage\StorageAdapterInterface`: `write`, `writeStream`,
`read`, `readStream`, `exists`, `delete`, `move`, `copy`. The contract contains only
relative paths, contents and PHP streams. Adapters must preserve the documented
stream ownership, overwrite and missing-file behavior and translate backend errors.

Disk options other than `adapter` and `url` become **named constructor arguments**
for NAF's `AutoResolvingContainer::make()`. Declare option names explicitly in the
constructor; NAF autowires other dependencies and registered interfaces. Each disk
constructs a fresh adapter. Shared adapter-class bindings are intentionally not used,
since disks with the same class can have different roots/credentials. No parallel
container or provider registry is needed.

Optionally implement `PublicUrlProviderInterface::url(string $path): string` when a
backend can supply public URLs. A configured prefix takes precedence. An adapter
must expose that capability only for files that may be public.

A future S3-compatible adapter should be named **`S3Adapter`**, not `AwsAdapter`.
S3, Dropbox and WebDAV are not implemented here. For HTTP adapters, start with
**`naf/client`**, its `Naf\Client\Core\Client` and PSR-18 `ClientInterface`; bind that
interface in the adapter package's bootstrap when needed. Reuse NAF config/DI and
PSR-3 logging instead of introducing parallel infrastructure.

The current NAF client converts request bodies to strings. Before adding large-file
HTTP uploads, verify its streaming behavior and extend the existing client boundary
if necessary; do not assume PSR-18 alone guarantees bounded-memory transfers.

Flysystem was evaluated: its [stream API](https://flysystem.thephpleague.com/docs/usage/filesystem-api/)
and provider adapters could help future backends, but local operations need no extra
library. Its [local symlink setting](https://flysystem.thephpleague.com/docs/adapter/local/)
does not reject links on reads, so the stricter confinement policy would still need
additional work. A future integration belongs behind a NAF adapter; Flysystem types
must not become application-facing APIs.

HTTP upload validation, MIME rules, quotas and metadata are outside the disk contract.
The new facade has no `UploadedFileInterface` overload. Applications convert validated
uploads to stream resources; future PSR-7 convenience support belongs in `Storage`,
never an adapter.

## Development

```sh
composer install
composer validate --strict
composer test
```

Tests use temporary directories and clean up files, streams, wrappers and permissions.
They cover the disk API, security, I/O and stream failures, bounded-memory transfers,
provider independence and real NAF boot/config/DI. Run
`php tests/host.php /path/to/separate-host/vendor/autoload.php` to check a separate
Composer installation. CI covers PHP 8.3–8.5; PHPUnit 11 supports PHP 8.3, while
newer runtimes may resolve PHPUnit 12.
