<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mercurio\Tables\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
