<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** Deterministic input failure after some bytes have already been written. */
final class FailingStream
{
    public mixed $context;

    private int $reads  = 0;
    private bool $stall = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->stall = str_contains($path, 'stall');

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->reads++ === 0) {
            return str_repeat('x', $count);
        }

        if (!$this->stall) {
            trigger_error('Input stream failed', E_USER_WARNING);
        }

        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
