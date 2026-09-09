<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ManifestRegistry
{
    public function __construct(private readonly string $path, private readonly string $manifest, private readonly string $kind = '', private readonly ?string $activePath = null) {}

    public function all(): array
    {
        $items = [];
        $files = glob($this->path . '/*/' . $this->manifest) ?: [];
        if ($this->activePath !== null && is_file($this->activePath . '/' . $this->manifest)) $files[] = $this->activePath . '/' . $this->manifest;
        foreach (array_unique($files) as $file) {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if(!is_array($data)||!isset($data['slug']))throw new RuntimeException('Extension manifest has no valid slug.');
            $data['_path'] = dirname($file);$slug=(string)$data['slug'];if(isset($items[$slug]))throw new RuntimeException("Duplicate extension slug: {$slug}");$items[$slug] = $data;
        }
        return $this->kind==='theme'?ThemeContract::validateAll($items):$items;
    }

    public function find(string $slug): array
    {
        $items = $this->all();
        if (!isset($items[$slug])) {
            throw new RuntimeException("Unknown extension: {$slug}");
        }
        return $items[$slug];
    }
}
