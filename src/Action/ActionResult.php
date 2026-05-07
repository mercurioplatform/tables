<?php

namespace Mercurio\Tables\Action;

final class ActionResult
{
    public function __construct(
        public readonly int $affected,
        public readonly int $missing = 0,
        public readonly ?string $message = null,
        public readonly int $denied = 0,
        public readonly int $skipped = 0,
        public readonly ?int $requested = null,
    ) {}

    /**
     * Все count-поля как ассоциативный массив (для логов и onSuccess callback'ов).
     * Включает только non-zero / non-null значения, чтобы flash и логи не были замусорены нулями.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $out = ['affected' => $this->affected];
        if ($this->missing > 0) {
            $out['missing'] = $this->missing;
        }
        if ($this->denied > 0) {
            $out['denied'] = $this->denied;
        }
        if ($this->skipped > 0) {
            $out['skipped'] = $this->skipped;
        }
        if ($this->requested !== null) {
            $out['requested'] = $this->requested;
        }

        return $out;
    }
}
