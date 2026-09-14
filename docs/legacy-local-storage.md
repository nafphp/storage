# naf/storage (unreleased)

A small optional NAF Composer plugin. Bind `Naf\Storage\LocalStorage` with a private absolute root **outside public/**. No public route or automatic upload endpoint is installed.

`stage(UploadedFileInterface, maxBytes)` validates actual streamed bytes, extension and detected MIME; returns an opaque key, sanitized display name, byte size and SHA-256. The fixed initial allow-list covers text/Markdown/CSV, PDF, PNG/JPEG/WebP and ZIP. Archives are never extracted. `promote(key)` is idempotent, `open(key)` returns a resource, and `delete(key)` handles either stage. Deliver as an attachment with `nosniff` after application authorization. This validates type/size; it is not a malware scanner.

The application owns quotas, authorization and its database state machine. Persist metadata and a finalize job transactionally, then promote and mark ready; retry interrupted work. `cleanup(isReferenced, olderThan)` removes only old unreferenced files. The root must be exclusively writable by the application; this backend does not coordinate hostile local filesystem writers.
