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

Requires PHP 8.3+ and `naf/framework ^0.2`. Local storage has no additional runtime
libraries or extension requirements. Remote adapters use optional dependencies described below.

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

The included backends are `LocalAdapter`, `S3Adapter` and `WebDavAdapter`. Remote
adapters reuse **`naf/client ^0.2.2`**, its lazy shared service,
and PSR-18. A registered `ClientInterface` binding is injected by normal NAF DI;
otherwise the adapters resolve the NAF client themselves. There is no provider
registry, manual bootstrap or second HTTP transport.

A custom PSR-18 client must support bounded-memory bodies/responses, preserve encoded
file bytes, and disable automatic redirects and mutation retries. NAF's client is
configured accordingly on a clone; its shared settings remain unchanged.

Flysystem was evaluated again for remote storage. The official AWS SDK already supplies
S3 signing, error parsing, multipart uploads and server-side copies. Adding Flysystem
would introduce another wrapper without removing those dependencies. WebDAV uses the
standard HTTP methods through NAF's client and needs no provider library. Provider SDK
types are kept out of the application-facing storage contract.

HTTP upload validation, MIME rules, quotas and metadata are outside the disk contract.
The new facade has no `UploadedFileInterface` overload. Applications convert validated
uploads to stream resources; future PSR-7 convenience support belongs in `Storage`,
never an adapter.

## S3 and compatible services

After `naf/client 0.2.2` and `naf/storage 0.1.0` are released:

```sh
composer require naf/storage 'naf/client:^0.2.2' 'aws/aws-sdk-php:^3.395.2'
```

The SDK is optional for local/WebDAV installations. It brings transitive Guzzle packages,
but all S3 requests use the NAF HTTP client through an internal SDK handler. There is no
second active HTTP stack. Configuring S3 without its dependencies fails with a clear exception.

Merge this disk into **`app/config.php`**, keeping credentials in environment variables:

```php
use Naf\Storage\Adapters\S3Adapter;

return [
    'storage' => [
        'default' => 'documents',
        'disks'   => [
            'documents' => [
                'adapter'   => S3Adapter::class,
                'bucket'    => 'application-documents',
                'region'    => 'eu-central-1',
                'accessKey' => (string) getenv('STORAGE_S3_ACCESS_KEY'),
                'secretKey' => (string) getenv('STORAGE_S3_SECRET_KEY'),
                'prefix'    => 'documents',
            ],
        ],
    ],
];
```

`endpoint` optionally selects an S3-compatible service. Set `pathStyle => true` when
it requires `/bucket/key` addressing, for example a MinIO installation. Standard AWS S3
uses its regional endpoint and virtual-host addressing by default. `prefix` is optional
and must be a relative path without a trailing slash. An optional `sessionToken` supports
explicit temporary credentials; this version does not discover/refresh IAM roles or use
an ambient AWS credential chain. Recreate affected disks when temporary credentials rotate.
Use HTTPS for remote services; HTTP is supported for isolated local endpoints.

The bucket must already exist. Use a private bucket policy and grant the credentials only
the necessary object read/write/delete and multipart permissions. Missing-object checks
can return 403 without permission to distinguish absent keys; this is an error, never
silently interpreted as `false`. A missing bucket's HEAD response may be indistinguishable
from a missing key, so bucket provisioning/validation belongs to deployment.

Uploads do not set public ACLs or change bucket policy. `url()` requires an explicit `url`
prefix, for example a deliberately public CDN endpoint. That prefix must include the disk's
configured key prefix. It does not expose private objects or generate signed download URLs.

S3 uploads use multipart automatically for large files. Copies remain server-side and the
SDK selects multipart copy when required. A move is **copy then delete**, so it is not
atomic: a delete failure may leave both copies. Concurrent changes to a source are not
locked. Failed multipart transfers are aborted where possible; abort failure is reported.
Configure an incomplete-upload lifecycle rule for interrupted processes that cannot run cleanup.

## WebDAV

After the releases above, install `naf/storage` and `naf/client ^0.2.2`. WebDAV additionally
requires PHP's **DOM extension** (`ext-dom`), but no AWS SDK or WebDAV-specific library.

```php
use Naf\Storage\Adapters\WebDavAdapter;

return [
    'storage' => [
        'disks' => [
            'documents' => [
                'adapter'  => WebDavAdapter::class,
                'endpoint' => 'https://cloud.example.com/remote.php/dav/files/alice/',
                'username' => (string) getenv('STORAGE_DAV_USERNAME'),
                'password' => (string) getenv('STORAGE_DAV_APP_PASSWORD'),
            ],
        ],
    ],
];
```

Use the existing authenticated files/collection endpoint, for example a Nextcloud user's
files endpoint, with HTTPS and an application password. The endpoint collection must exist;
file parent collections beneath it are created automatically. A dedicated account or restricted
collection is recommended for confinement. The server must implement RFC 4918 `PROPFIND`
(Depth 0, `resourcetype`), `MKCOL`, `GET`, `PUT`, `DELETE`, `COPY` and `MOVE`.

Properties are checked before operations that could otherwise act recursively on collections.
Collections cannot be read, overwritten, copied, moved or deleted through this file API.
Malformed, oversized or entity-bearing property XML fails closed. Authentication/permission
errors and partial `207` mutation responses are failures. An endpoint is never a public URL;
configure a public `url` separately only if the server actually offers public file access.

WebDAV atomicity and overwrite behavior on interrupted network transfers depend on the server.
The adapter does not implement WebDAV locks; protect the collection against hostile concurrent
writers that could replace a checked file with a collection between requests.

## Remote streams and temporary space

The application API is identical for all three adapters:

```php
use function Naf\Storage\storage;

storage('documents')->put('invoices/2026/1234.pdf', $contents);
$stream = storage('documents')->readStream('invoices/2026/1234.pdf');
try {
    storage()->writeStream('backups/1234.pdf', $stream);
} finally {
    fclose($stream);
}
```

Remote uploads snapshot the remaining input into an automatically deleted temporary file.
This supports non-seekable streams, detects input failures before writing remotely and allows
SDK hashing/retries without rewinding caller streams. NAF's HTTP client spools responses to
temporary disk; the adapter returns a native PHP stream at position zero. Download conversion
can temporarily require a second file-sized buffer on disk. Memory stays bounded, but provision
enough temporary disk space and allow the request timeout to cover large transfers. These are
synchronous transfers: the download is complete before `readStream()` returns. `get()` still
loads the whole result into memory. No automatic content decompression changes stored bytes.

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

Remote unit tests inject PSR-18 fakes and exercise signed requests, SDK failures, multipart
abort, WebDAV collections, hostile XML, configuration and stream errors. Run the opt-in
integration suite with Docker and a checked-out streaming NAF client:

```sh
sh tests/remotes.sh /absolute/path/to/nafphp/client
```

It starts disposable MinIO and rclone WebDAV containers bound only to loopback, checks both
adapters with 32 MiB files and removes containers/data even on failure. It never uses AWS or
Nextcloud credentials. The test-only client checkout is not a production Composer repository.

## PHP code style

Source, tests and PHP templates follow the shared [NAF code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
(PER Coding Style 3.0 with the Nafinity readability rules). After `composer install`, run
`composer style:check` to verify formatting or `composer style:fix` to apply it. The formatter
is a development dependency. Review template output and run the package checks after changes.
