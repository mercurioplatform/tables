<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\ActionResult;

/**
 * Handler-stub для queued-bulk-action в {@see MutableOrdersResource}.
 *
 * В тестах сам метод execute() не должен запускаться (Queue::fake блокирует
 * выполнение job'a). Реализация — минимальная, на случай если sync-mode
 * через ниже-threshold всё-таки попадёт сюда.
 */
final class QueuedFlushHandler implements Action
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(mixed $subject, array $payload): ActionResult
    {
        return new ActionResult(affected: count((array) $subject));
    }
}
