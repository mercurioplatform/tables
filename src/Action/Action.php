<?php

namespace Mercurio\Tables\Action;

interface Action
{
    public function execute(mixed $subject, array $payload): ActionResult;
}
