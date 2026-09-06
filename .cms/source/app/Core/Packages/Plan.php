<?php

declare(strict_types=1);

namespace App\Core\Packages;

use RuntimeException;

final class Plan
{
    /** Return dependency-first identities; this method never executes package code. */
    public static function resolve(array $selected, array $installed, string $core, string $php): array
    {
        $available = []; $pending = [];
        foreach ([$installed, $selected] as $index => $manifests) {
            $seen = [];
            foreach ($manifests as $manifest) {
                if (!is_array($manifest)) throw new RuntimeException('Invalid package plan input.');
                Manifest::validate($manifest, true);
                $id = Manifest::identity($manifest);
                if (isset($seen[$id])) throw new RuntimeException('Duplicate package identity in plan.');
                $seen[$id] = true;
                if ($index === 1) {
                    if (isset($available[$id]) && version_compare($manifest['version'], $available[$id]['version'], '<=')) throw new RuntimeException('Package plan cannot reinstall or downgrade a release.');
                    $pending[$id] = true;
                }
                $available[$id] = $manifest;
            }
        }
        $versions = array_map(static fn(array $manifest): string => $manifest['version'], $available);
        // Validate existing dependants too: an upgrade must not break them.
        foreach ($available as $manifest) Manifest::compatible($manifest, $core, $php, $versions);
        ksort($available);
        $visiting = []; $visited = []; $order = [];
        $visit = static function (string $id) use (&$visit, &$visiting, &$visited, &$order, $available, $pending): void {
            if (isset($visiting[$id])) throw new RuntimeException('Package dependency cycle.');
            if (isset($visited[$id])) return;
            $visiting[$id] = true;
            $dependencies = array_keys($available[$id]['dependencies'] ?? []);
            sort($dependencies);
            foreach ($dependencies as $dependency) $visit($dependency);
            unset($visiting[$id]); $visited[$id] = true;
            if (isset($pending[$id])) $order[] = $id;
        };
        foreach (array_keys($available) as $id) $visit($id);
        return $order;
    }
}
