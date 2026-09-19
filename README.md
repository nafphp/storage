# NAF Storage

> **Provider-independent file storage for NAF.**

One API over local disks, S3-compatible services and WebDAV. Named disks keep public assets
and private documents apart, streams keep large files out of memory, and the helper is
namespaced like every other NAF plugin helper.

```php
use function Naf\Storage\storage;

storage()->put('foo.txt', 'Hello World');
storage('documents')->put('invoices/2026/1234.pdf', $contents);
storage('public')->url('avatars/123.jpg');
```

> 🧩 Part of the official NAF plugin collection.
> Install it when your application handles files, and nothing else.

## Documentation

**[Read the documentation →](https://nafphp.github.io/docs/)**

What this package does, how the disks are configured and what each adapter needs lives in
the [NAF documentation](https://nafphp.github.io/docs/). Not sure which packages you need?
[Start here](https://nafphp.github.io/docs/choosing-packages/).

## Install

```bash
composer require naf/storage
```

## License

MIT. Part of [NAF](https://github.com/nafphp/framework).
