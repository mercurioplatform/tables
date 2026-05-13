<?php

use Illuminate\Support\Facades\Schema;
use Mercurio\Tables\Tests\Fixtures\Models\TestPost;

it('boots TablesServiceProvider on sqlite memory', function () {
    expect(true)->toBeTrue();
});

it('runs all engine migrations + fixtures on sqlite', function () {
    expect(Schema::hasTable('tables_saved_views'))->toBeTrue();
    expect(Schema::hasTable('tables_user_table_prefs'))->toBeTrue();
    expect(Schema::hasTable('tables_action_log'))->toBeTrue();
    expect(Schema::hasTable('tables_action_progress'))->toBeTrue();
    expect(Schema::hasTable('test_posts'))->toBeTrue();
});

it('inserts a TestPost row through Eloquent', function () {
    $post = TestPost::create([
        'title' => 'hello',
        'status' => 'draft',
    ]);

    expect($post->id)->toBeInt();
    expect(TestPost::count())->toBe(1);
});
