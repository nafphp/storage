# Working on naf/storage

Read [composer.json](composer.json), [README.md](README.md), the
[shared workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md) and
[release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the shared documents are in sibling `docs/`.
Preserve other contributors' uncommitted work. Package changes use an RC branch,
are tested, committed and pushed, and are merged by the maintainer. A fix request
does not authorize a release. Prepare version-dependent docs before release and
publish only after Packagist availability is verified.

## Native NAF integration

This Composer `naf-plugin` provides `Naf\Storage\storage(?string $name = null)`.
Start with `src/StorageManager.php`, `src/Filesystem.php`, `src/config.php`,
`src/functions.php` and package-root `bootstrap.php`. NAF discovers these resources
and merges configuration; do not add manual registration or another container,
config system or HTTP mechanism. Manager and default Filesystem bindings are lazy.
The manager uses NAF's `AutoResolvingContainer::make()` with named disk options to
create one adapter per disk; dependencies use normal NAF DI.

`StorageAdapterInterface` accepts relative paths, contents and PHP streams. Upload
validation and application authorization/quotas/metadata do not belong in adapters.
Future public-URL providers can implement `PublicUrlProviderInterface`. Name a future
S3 adapter `S3Adapter`; prefer `naf/client` and PSR-18 for external APIs. Keep Flysystem,
if ever used, behind NAF types.

## Local I/O and verification

The local adapter must be safe without a booted NAF app. Preserve traversal,
absolute-path and symlink rejection, regular-file checks, lazy directory creation,
private default permissions and exception translation. Writes/copies use bounded
buffers and temporary files before replacement. Caller streams stay open and are
consumed from their current position. URLs must not silently publish files.

Run `composer test` and `composer validate --strict`; no `analyse` script is declared.
Tests use PHPUnit, temporary paths and the real NAF host in `tests/host.php`.
The latter also accepts a separate Composer host autoloader as its first argument.
CI covers PHP 8.3–8.5. Keep runtime code PHP 8.3 compatible.

Older `src/LocalStorage.php` and `tests/run.php` are preserved workspace work,
independent of the disk API. Preserve its streamed limits, MIME mismatch, traversal
key, promotion, deletion and orphan-cleanup tests. Its application lifecycle API
must not leak into the provider-neutral adapter interface.
