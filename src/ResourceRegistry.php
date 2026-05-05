<?php

namespace Mercurio\Tables;

use Illuminate\Support\Facades\Log;

final class ResourceRegistry
{
    /** @var array<int, class-string> */
    private array $classes = [];

    public function register(string $resourceClass): void
    {
        if (in_array($resourceClass, $this->classes, true)) {
            return;
        }

        $this->classes[] = $resourceClass;

        if (function_exists('app') && app()->environment('local')) {
            Log::debug('tables.registry.register', ['class' => $resourceClass]);
        }
    }

    /**
     * @return array<int, ListResource>
     */
    public function all(): array
    {
        $instances = [];
        foreach ($this->classes as $class) {
            $instances[] = app($class);
        }

        return $instances;
    }

    /**
     * @return array<int, class-string>
     */
    public function classes(): array
    {
        return $this->classes;
    }
}
