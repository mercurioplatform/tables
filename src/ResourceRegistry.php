<?php

namespace Mercurio\Tables;

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
