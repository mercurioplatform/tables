<?php

namespace Mercurio\Tables\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Mercurio\Tables\Tests\Fixtures\Models\TestPost;

class TestPostPolicy
{
    /** Любой авторизованный пользователь может править title. */
    public function editTitle(?Authenticatable $user, TestPost $post): bool
    {
        return $user !== null;
    }

    /** Никто не может править status — для негативного сценария. */
    public function editStatus(?Authenticatable $user, TestPost $post): bool
    {
        return false;
    }
}
