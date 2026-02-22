<?php
namespace MageZero\CloudflareR2\Model;

class DownloadedFileTracker
{
    private array $files = [];

    public function track(string $relativePath): void
    {
        $this->files[] = $relativePath;
    }

    public function getAndClear(): array
    {
        $files = $this->files;
        $this->files = [];
        return $files;
    }
}
