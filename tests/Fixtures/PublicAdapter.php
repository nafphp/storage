<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Storage\PublicUrlProviderInterface;

final class PublicAdapter extends RecordingAdapter implements PublicUrlProviderInterface
{
    public function url(string $path): string
    {
        return 'https://files.example/' . $this->bucket . '/' . rawurlencode($path);
    }
}
