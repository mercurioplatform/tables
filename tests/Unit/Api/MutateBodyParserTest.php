<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Illuminate\Http\Request;
use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\MutateBodyParser;
use Mercurio\Tables\Api\ParsedMutate;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class MutateBodyParserTest extends TestCase
{
    private MutateBodyParser $parser;

    private MutableOrdersResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MutateBodyParser;
        MutableOrdersResource::reset();
        $this->resource = new MutableOrdersResource;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $query
     */
    private function parse(array $body, array $query = [], ?ApiConfig $configOverride = null): ParsedMutate
    {
        $request = Request::create('/orders/mutate?'.http_build_query($query), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body) ?: '{}');

        $config = $configOverride ?? $this->resource->resolveApiConfig();

        return $this->parser->parse($request, $config, $this->resource);
    }

    public function test_happy_cell(): void
    {
        $p = $this->parse(['op' => 'cell', 'id' => 1, 'field' => 'status', 'value' => 'paid']);
        $this->assertSame('cell', $p->op);
        $this->assertSame(1, $p->id);
        $this->assertSame('status', $p->field);
        $this->assertSame('paid', $p->value);
    }

    public function test_happy_row(): void
    {
        $p = $this->parse(['op' => 'row', 'id' => 1, 'action' => 'approve']);
        $this->assertSame('row', $p->op);
        $this->assertSame('approve', $p->action);
        $this->assertSame([], $p->payload);
    }

    public function test_happy_bulk(): void
    {
        $p = $this->parse(['op' => 'bulk', 'action' => 'delete', 'ids' => [1, 2, 3]]);
        $this->assertSame('bulk', $p->op);
        $this->assertSame('delete', $p->action);
        $this->assertSame([1, 2, 3], $p->ids);
    }

    public function test_missing_op_throws_validation_failed(): void
    {
        try {
            $this->parse([]);
            $this->fail('expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('missing_op', $e->details['reason']);
        }
    }

    public function test_unknown_op_returns_allowed_list(): void
    {
        try {
            $this->parse(['op' => 'flush']);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('unknown_op', $e->details['reason']);
            $this->assertSame(['cell', 'row', 'bulk'], $e->details['allowed']);
        }
    }

    public function test_cell_without_field_fails(): void
    {
        try {
            $this->parse(['op' => 'cell', 'id' => 1, 'value' => 'paid']);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame('missing_field', $e->details['reason']);
        }
    }

    public function test_cell_field_not_in_allow_fields(): void
    {
        $configOverride = $this->resource->resolveApiConfig()->allowFields(['id', 'total']);
        try {
            $this->parse(['op' => 'cell', 'id' => 1, 'field' => 'status', 'value' => 'paid'], configOverride: $configOverride);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame('field_not_allowed', $e->details['reason']);
            $this->assertSame('status', $e->details['field']);
            $this->assertSame(['id', 'total'], $e->details['allowed']);
        }
    }

    public function test_row_without_id_fails(): void
    {
        try {
            $this->parse(['op' => 'row', 'action' => 'approve']);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame('missing_id', $e->details['reason']);
        }
    }

    public function test_bulk_empty_ids_fails(): void
    {
        try {
            $this->parse(['op' => 'bulk', 'action' => 'delete', 'ids' => []]);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame('empty_ids', $e->details['reason']);
        }
    }

    public function test_bulk_too_many_ids_returns_max_and_given(): void
    {
        $configOverride = $this->resource->resolveApiConfig()->maxBulkIds(2);
        try {
            $this->parse(['op' => 'bulk', 'action' => 'delete', 'ids' => [1, 2, 3]], configOverride: $configOverride);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame('too_many_ids', $e->details['reason']);
            $this->assertSame(2, $e->details['max']);
            $this->assertSame(3, $e->details['given']);
        }
    }

    public function test_invalid_payload_type_fails(): void
    {
        try {
            $this->parse(['op' => 'row', 'id' => 1, 'action' => 'approve', 'payload' => 'not-an-object']);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame('invalid_payload', $e->details['reason']);
        }
    }

    public function test_include_undo_token_parsed(): void
    {
        $p = $this->parse(
            ['op' => 'row', 'id' => 1, 'action' => 'approve'],
            ['include' => 'undoToken,foo'],
        );
        $this->assertTrue($p->wantsInclude('undoToken'));
        $this->assertFalse($p->wantsInclude('foo'));
    }

    public function test_cell_value_null_allowed(): void
    {
        $p = $this->parse(['op' => 'cell', 'id' => 1, 'field' => 'status', 'value' => null]);
        $this->assertNull($p->value);
    }

    public function test_cell_missing_value_key_fails(): void
    {
        try {
            $this->parse(['op' => 'cell', 'id' => 1, 'field' => 'status']);
            $this->fail();
        } catch (ApiValidationException $e) {
            $this->assertSame('missing_value', $e->details['reason']);
        }
    }
}
