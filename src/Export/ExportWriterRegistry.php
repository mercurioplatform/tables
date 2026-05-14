<?php

namespace Mercurio\Tables\Export;

use InvalidArgumentException;

/**
 * Registry of available export writers keyed by format slug ("csv",
 * "json", "xlsx", ...).
 *
 * Registered through `TablesServiceProvider::register()` as singleton;
 * formats come from `config('tables.export.writers')`. CSV is always
 * pre-registered.
 *
 * `make($format)` resolves the class via the Laravel container, so writer
 * implementations may declare constructor dependencies.
 */
final class ExportWriterRegistry
{
    /** @var array<string, class-string<ExportWriter>> */
    private array $map = [];

    public function __construct()
    {
        $this->map = ['csv' => CsvStreamWriter::class];
    }

    /**
     * @param  class-string<ExportWriter>  $class
     */
    public function register(string $format, string $class): void
    {
        if (! is_subclass_of($class, ExportWriter::class)) {
            throw new InvalidArgumentException(
                "Export writer [$class] must implement ".ExportWriter::class
            );
        }
        $this->map[$format] = $class;
    }

    public function has(string $format): bool
    {
        return isset($this->map[$format]);
    }

    public function make(string $format): ExportWriter
    {
        if (! isset($this->map[$format])) {
            throw new InvalidArgumentException("Unknown export format [$format]");
        }

        /** @var ExportWriter $writer */
        $writer = app($this->map[$format]);

        return $writer;
    }

    /**
     * @return array<int, string>
     */
    public function formats(): array
    {
        return array_keys($this->map);
    }

    /**
     * @return array<string, class-string<ExportWriter>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
