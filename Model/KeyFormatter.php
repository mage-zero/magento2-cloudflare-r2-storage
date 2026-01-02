<?php
namespace MageZero\CloudflareR2\Model;

class KeyFormatter
{
    private string $prefix;

    public function __construct(string $prefix = '')
    {
        $this->prefix = trim($prefix, '/');
    }

    public function toKey(string $path): string
    {
        $path = ltrim($path, '/');
        if ($this->prefix === '') {
            return $path;
        }

        return $this->prefix . '/' . $path;
    }

    public function fromKey(string $key): string
    {
        $key = ltrim($key, '/');
        if ($this->prefix === '') {
            return $key;
        }

        $needle = $this->prefix . '/';
        if (strpos($key, $needle) === 0) {
            return substr($key, strlen($needle));
        }

        return $key;
    }
}
