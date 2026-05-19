<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Illuminate\Http\Request;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\ApiQueryParser;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\FormatMode;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class ApiQueryParserTest extends TestCase
{
    private ApiQueryParser $parser;

    private TestOrdersResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ApiQueryParser;
        $this->resource = new TestOrdersResource;
    }

    private function parse(array $params)
    {
        $request = Request::create('/test', 'GET', $params);

        return $this->parser->parse($request, $this->resource->resolveApiConfig(), $this->resource);
    }

    private function parsePostJson(array $body, array $queryParams = [])
    {
        $url = '/test'.($queryParams === [] ? '' : '?'.http_build_query($queryParams));
        $request = Request::create(
            $url,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) json_encode($body),
        );

        return $this->parser->parse($request, $this->resource->resolveApiConfig(), $this->resource);
    }

    // ------------ Structural params ------------

    public function test_default_includes_are_data_and_page(): void
    {
        $parsed = $this->parse([]);
        $this->assertSame(['data', 'page'], $parsed->includes);
    }

    public function test_include_csv_is_parsed(): void
    {
        $parsed = $this->parse(['include' => 'summary,savedViews']);
        $this->assertContains('summary', $parsed->includes);
        $this->assertContains('savedViews', $parsed->includes);
        $this->assertContains('data', $parsed->includes);
        $this->assertContains('page', $parsed->includes);
    }

    public function test_unknown_include_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['include' => 'xyz']);
    }

    public function test_default_fields_equal_allow_fields(): void
    {
        $parsed = $this->parse([]);
        $this->assertSame(['id', 'number', 'status', 'total', 'customer'], $parsed->fields);
    }

    public function test_sparse_fields_subset(): void
    {
        $parsed = $this->parse(['fields' => 'id,number']);
        $this->assertSame(['id', 'number'], $parsed->fields);
    }

    public function test_unknown_field_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['fields' => 'id,secret']);
    }

    public function test_default_format_is_raw(): void
    {
        $this->assertSame(FormatMode::Raw, $this->parse([])->format);
    }

    public function test_format_explicit(): void
    {
        $this->assertSame(FormatMode::Both, $this->parse(['format' => 'both'])->format);
        $this->assertSame(FormatMode::Formatted, $this->parse(['format' => 'formatted'])->format);
    }

    public function test_invalid_format_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['format' => 'xml']);
    }

    // ------------ Per-field format ------------

    public function test_format_string_keeps_legacy_shape(): void
    {
        $parsed = $this->parse(['format' => 'both']);

        $this->assertSame(FormatMode::Both, $parsed->format);
        $this->assertSame([], $parsed->perFieldFormats);
    }

    public function test_format_array_per_field_uses_default_base(): void
    {
        $parsed = $this->parse(['format' => ['total' => 'both']]);

        $this->assertSame(FormatMode::Raw, $parsed->format);
        $this->assertSame(['total' => FormatMode::Both], $parsed->perFieldFormats);
    }

    public function test_format_array_star_sets_base(): void
    {
        $parsed = $this->parse(['format' => ['*' => 'both', 'total' => 'raw']]);

        $this->assertSame(FormatMode::Both, $parsed->format);
        $this->assertSame(['total' => FormatMode::Raw], $parsed->perFieldFormats);
    }

    public function test_format_array_only_star_no_per_field(): void
    {
        $parsed = $this->parse(['format' => ['*' => 'formatted']]);

        $this->assertSame(FormatMode::Formatted, $parsed->format);
        $this->assertSame([], $parsed->perFieldFormats);
    }

    public function test_format_array_unknown_field_throws_422(): void
    {
        try {
            $this->parse(['format' => ['secret' => 'both']]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('secret', $e->details['field']);
            $this->assertContains('id', $e->details['allowed']);
        }
    }

    public function test_format_array_invalid_mode_throws_422(): void
    {
        try {
            $this->parse(['format' => ['total' => 'xml']]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('xml', $e->details['format']);
            $this->assertSame('total', $e->details['field']);
            $this->assertSame(['raw', 'formatted', 'both'], $e->details['allowed']);
        }
    }

    public function test_format_array_invalid_star_mode_throws_422(): void
    {
        try {
            $this->parse(['format' => ['*' => 'xml']]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('xml', $e->details['format']);
            $this->assertSame('*', $e->details['field']);
        }
    }

    public function test_format_numeric_array_falls_through_to_unknown_field(): void
    {
        try {
            $this->parse(['format' => ['foo']]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('0', $e->details['field']);
        }
    }

    public function test_post_body_format_object_equivalent_to_get(): void
    {
        $getParsed = $this->parse(['format' => ['total' => 'both']]);
        $postParsed = $this->parsePostJson(['format' => ['total' => 'both']]);

        $this->assertSame($getParsed->format, $postParsed->format);
        $this->assertEquals($getParsed->perFieldFormats, $postParsed->perFieldFormats);
    }

    public function test_post_body_format_string_still_works(): void
    {
        $parsed = $this->parsePostJson(['format' => 'both']);

        $this->assertSame(FormatMode::Both, $parsed->format);
        $this->assertSame([], $parsed->perFieldFormats);
    }

    public function test_default_per_page_from_config(): void
    {
        $this->assertSame(2, $this->parse([])->perPage);
    }

    public function test_per_page_out_of_range_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['per_page' => 999]);
    }

    public function test_per_page_zero_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['per_page' => 0]);
    }

    public function test_per_page_non_numeric_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['per_page' => 'big']);
    }

    public function test_page_default_one(): void
    {
        $this->assertSame(1, $this->parse([])->page);
    }

    public function test_page_explicit(): void
    {
        $this->assertSame(3, $this->parse(['page' => 3])->page);
    }

    public function test_page_zero_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['page' => 0]);
    }

    public function test_sort_asc(): void
    {
        $parsed = $this->parse(['sort' => 'total']);
        $this->assertSame('total', $parsed->query->sortField);
        $this->assertSame('asc', $parsed->query->sortDirection);
    }

    public function test_sort_desc(): void
    {
        $parsed = $this->parse(['sort' => '-total']);
        $this->assertSame('total', $parsed->query->sortField);
        $this->assertSame('desc', $parsed->query->sortDirection);
    }

    public function test_sort_unknown_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['sort' => 'secret']);
    }

    public function test_search_pass_through(): void
    {
        $parsed = $this->parse(['q' => 'Alice']);
        $this->assertSame('Alice', $parsed->query->search);
    }

    public function test_saved_view_resolved(): void
    {
        $parsed = $this->parse(['savedView' => 'paid']);
        $this->assertSame('paid', $parsed->query->savedViewKey);
        $this->assertCount(1, $parsed->query->conditions);
        $this->assertSame('status', $parsed->query->conditions[0]->field);
        $this->assertSame(Operator::Eq, $parsed->query->conditions[0]->operator);
        $this->assertSame('paid', $parsed->query->conditions[0]->value);
    }

    public function test_saved_view_unknown_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['savedView' => 'hidden']);
    }

    // ------------ Flat filters ------------

    public function test_filter_eq_shortcut(): void
    {
        $parsed = $this->parse(['filter' => ['status' => 'paid']]);
        $this->assertCount(1, $parsed->query->conditions);
        $this->assertSame(Operator::Eq, $parsed->query->conditions[0]->operator);
        $this->assertSame('paid', $parsed->query->conditions[0]->value);
    }

    public function test_filter_explicit_operator(): void
    {
        $parsed = $this->parse(['filter' => ['total' => ['gte' => '1000']]]);
        $this->assertSame(Operator::Gte, $parsed->query->conditions[0]->operator);
        $this->assertSame('1000', $parsed->query->conditions[0]->value);
    }

    public function test_filter_in_array(): void
    {
        $parsed = $this->parse(['filter' => ['status' => ['in' => ['paid', 'pending']]]]);
        $this->assertSame(Operator::In, $parsed->query->conditions[0]->operator);
        $this->assertSame(['paid', 'pending'], $parsed->query->conditions[0]->value);
    }

    public function test_filter_between(): void
    {
        $parsed = $this->parse(['filter' => ['total' => ['between' => ['100', '500']]]]);
        $this->assertSame(Operator::Between, $parsed->query->conditions[0]->operator);
        $this->assertSame(['100', '500'], $parsed->query->conditions[0]->value);
    }

    public function test_filter_between_wrong_arity_throws(): void
    {
        $this->expectException(ApiValidationException::class);
        $this->parse(['filter' => ['total' => ['between' => ['100']]]]);
    }

    public function test_filter_empty(): void
    {
        $parsed = $this->parse(['filter' => ['status' => ['empty' => 'true']]]);
        $this->assertSame(Operator::Empty_, $parsed->query->conditions[0]->operator);
        $this->assertNull($parsed->query->conditions[0]->value);
    }

    public function test_filter_unknown_field_throws(): void
    {
        try {
            $this->parse(['filter' => ['secret' => 'x']]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('secret', $e->details['field']);
        }
    }

    public function test_filter_disallowed_operator_throws(): void
    {
        // `number` allows only Eq/Contains — gte should fail
        try {
            $this->parse(['filter' => ['number' => ['gte' => 'A-1001']]]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame('number', $e->details['field']);
            $this->assertSame('gte', $e->details['operator']);
        }
    }

    // ------------ Saved view merge ------------

    public function test_saved_view_conditions_merged_with_user_filter(): void
    {
        $parsed = $this->parse([
            'savedView' => 'paid',
            'filter' => ['total' => ['gt' => '500']],
        ]);

        $this->assertCount(2, $parsed->query->conditions);
        $byField = [];
        foreach ($parsed->query->conditions as $c) {
            $byField[$c->field] = $c;
        }
        $this->assertSame(Operator::Eq, $byField['status']->operator);
        $this->assertSame('paid', $byField['status']->value);
        $this->assertSame(Operator::Gt, $byField['total']->operator);
        $this->assertSame('500', $byField['total']->value);
    }

    // ------------ QB-tree через GET ?qb= ------------

    public function test_qb_get_parses_into_qb_root_and_clears_conditions(): void
    {
        $tree = ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'];
        $parsed = $this->parse(['qb' => base64_encode((string) json_encode($tree))]);

        $this->assertNotNull($parsed->query->qbRoot);
        $this->assertSame([], $parsed->query->conditions);
        $this->assertInstanceOf(AtomGroup::class, $parsed->query->qbRoot);
        $this->assertSame('AND', $parsed->query->qbRoot->op);
    }

    public function test_qb_get_or_group(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
            ],
        ];
        $parsed = $this->parse(['qb' => base64_encode((string) json_encode($tree))]);

        $this->assertNotNull($parsed->query->qbRoot);
        // top-level OR без NOT и без savedView/flat → не оборачивается
        $this->assertSame('OR', $parsed->query->qbRoot->op);
        $this->assertCount(2, $parsed->query->qbRoot->children);
    }

    // ------------ QB-tree через POST body ------------

    public function test_post_body_qb_equivalent_to_get_qb(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
            ],
        ];

        $getParsed = $this->parse(['qb' => base64_encode((string) json_encode($tree))]);
        $postParsed = $this->parsePostJson(['qb' => $tree]);

        $this->assertNotNull($getParsed->query->qbRoot);
        $this->assertNotNull($postParsed->query->qbRoot);
        $this->assertSame($getParsed->query->qbRoot->op, $postParsed->query->qbRoot->op);
        $this->assertCount(
            count($getParsed->query->qbRoot->children),
            $postParsed->query->qbRoot->children,
        );
    }

    public function test_post_body_carries_other_keys_like_query(): void
    {
        $parsed = $this->parsePostJson([
            'filter' => ['status' => 'paid'],
            'sort' => '-total',
            'q' => 'Alice',
            'page' => 2,
            'per_page' => 5,
        ]);

        $this->assertSame(2, $parsed->page);
        $this->assertSame(5, $parsed->perPage);
        $this->assertSame('Alice', $parsed->query->search);
        $this->assertSame('total', $parsed->query->sortField);
        $this->assertSame('desc', $parsed->query->sortDirection);
        $this->assertCount(1, $parsed->query->conditions);
        $this->assertSame('status', $parsed->query->conditions[0]->field);
    }

    public function test_post_with_non_json_content_type_returns_malformed_query(): void
    {
        $request = Request::create(
            '/test',
            'POST',
            ['qb' => 'whatever'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        );

        try {
            $this->parser->parse($request, $this->resource->resolveApiConfig(), $this->resource);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::MalformedQuery, $e->errorCode);
            $this->assertSame('unsupported_media_type', $e->details['reason']);
        }
    }

    public function test_post_with_qb_in_body_and_query_returns_400(): void
    {
        $tree = ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'];

        $request = Request::create(
            '/test?qb='.urlencode(base64_encode((string) json_encode($tree))),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) json_encode(['qb' => $tree]),
        );

        try {
            $this->parser->parse($request, $this->resource->resolveApiConfig(), $this->resource);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::MalformedQuery, $e->errorCode);
            $this->assertSame('qb_specified_twice', $e->details['reason']);
        }
    }

    // ------------ Error mapping ------------

    public function test_qb_unknown_field_maps_to_422(): void
    {
        $tree = ['type' => 'cond', 'field' => 'nonexistent', 'operator' => 'eq', 'value' => 'x'];

        try {
            $this->parse(['qb' => base64_encode((string) json_encode($tree))]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('unknown_field', $e->details['kind']);
            $this->assertSame('nonexistent', $e->details['field']);
        }
    }

    public function test_qb_disallowed_operator_maps_to_422(): void
    {
        $tree = ['type' => 'cond', 'field' => 'number', 'operator' => 'gte', 'value' => 'A-1'];

        try {
            $this->parse(['qb' => base64_encode((string) json_encode($tree))]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::ValidationFailed, $e->errorCode);
            $this->assertSame('operator_not_allowed', $e->details['kind']);
            $this->assertSame('number', $e->details['field']);
            $this->assertSame('gte', $e->details['operator']);
        }
    }

    public function test_qb_malformed_base64_maps_to_400(): void
    {
        try {
            $this->parse(['qb' => 'definitely_not_base64@@@']);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::MalformedQuery, $e->errorCode);
            $this->assertSame('malformed_base64', $e->details['kind']);
        }
    }

    public function test_qb_depth_exceeded_maps_to_400(): void
    {
        config(['tables.qb_max_depth' => 1]);

        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [[
                'type' => 'group',
                'op' => 'AND',
                'children' => [[
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                    ],
                ]],
            ]],
        ];

        try {
            $this->parse(['qb' => base64_encode((string) json_encode($tree))]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::MalformedQuery, $e->errorCode);
            $this->assertSame('depth_exceeded', $e->details['kind']);
        }
    }

    public function test_qb_post_body_depth_exceeded_maps_to_400(): void
    {
        config(['tables.qb_max_depth' => 1]);

        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [[
                'type' => 'group',
                'op' => 'AND',
                'children' => [[
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                    ],
                ]],
            ]],
        ];

        try {
            $this->parsePostJson(['qb' => $tree]);
            $this->fail('Expected ApiValidationException');
        } catch (ApiValidationException $e) {
            $this->assertSame(ApiErrorCode::MalformedQuery, $e->errorCode);
            $this->assertSame('depth_exceeded', $e->details['kind']);
        }
    }

    // ------------ Merge savedView + flat + qb ------------

    public function test_qb_and_root_merges_flat_filter_and_saved_view_children(): void
    {
        // root = AND-group, plain — flat/savedView просто append'ятся к children
        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                ['type' => 'cond', 'field' => 'total', 'operator' => 'gte', 'value' => '500'],
            ],
        ];

        $parsed = $this->parse([
            'qb' => base64_encode((string) json_encode($tree)),
            'savedView' => 'paid', // adds status=paid
            'filter' => ['number' => ['contains' => 'A']],
        ]);

        $this->assertNotNull($parsed->query->qbRoot);
        $this->assertSame([], $parsed->query->conditions);

        $root = $parsed->query->qbRoot;
        $this->assertSame('AND', $root->op);
        // 1 от qb + 1 от savedView + 1 от flat = 3
        $this->assertCount(3, $root->children);

        $fieldsByPos = array_map(static fn ($c) => $c->field, $root->children);
        $this->assertContains('total', $fieldsByPos);
        $this->assertContains('status', $fieldsByPos);
        $this->assertContains('number', $fieldsByPos);
    }

    public function test_qb_or_root_wrapped_in_and_with_flat_filter(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
            ],
        ];

        $parsed = $this->parse([
            'qb' => base64_encode((string) json_encode($tree)),
            'filter' => ['total' => ['gte' => '500']],
        ]);

        $root = $parsed->query->qbRoot;
        $this->assertNotNull($root);
        // wrapped: AND-root, children = [OR-group, AtomCondition(total)]
        $this->assertSame('AND', $root->op);
        $this->assertFalse($root->not);
        $this->assertCount(2, $root->children);
        $this->assertInstanceOf(AtomGroup::class, $root->children[0]);
        $this->assertSame('OR', $root->children[0]->op);
        $this->assertInstanceOf(AtomCondition::class, $root->children[1]);
        $this->assertSame('total', $root->children[1]->field);
    }
}
