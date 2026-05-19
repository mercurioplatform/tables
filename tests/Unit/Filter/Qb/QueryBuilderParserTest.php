<?php

namespace Mercurio\Tables\Tests\Unit\Filter\Qb;

use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Filter\Qb\Exceptions\QueryBuilderValidationException;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

/**
 * Unit-тесты на `QueryBuilderParser`. Покрывает оба режима:
 * - `strict=false` (UI back-compat) — невалидные ноды silently dropped;
 * - `strict=true` (API-путь) — throw {@see QueryBuilderValidationException}.
 *
 * Round-trip `parse(base64) ↔ parseArray(array)` гарантирует, что оба
 * entry-point'а дают идентичный `AtomGroup` для семантически одинакового дерева.
 */
final class QueryBuilderParserTest extends TestCase
{
    private TestOrdersResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resource = new TestOrdersResource;
    }

    // -------- strict=false (UI back-compat) --------

    public function test_strict_false_silently_drops_unknown_field(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                ['type' => 'cond', 'field' => 'nonexistent', 'operator' => 'eq', 'value' => 'x'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
            ],
        ];

        $root = QueryBuilderParser::parseArray($tree, $this->resource);

        $this->assertInstanceOf(AtomGroup::class, $root);
        $this->assertCount(1, $root->children);
        $atom = $root->children[0];
        $this->assertInstanceOf(AtomCondition::class, $atom);
        $this->assertSame('status', $atom->field);
    }

    public function test_strict_false_silently_drops_invalid_operator(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                ['type' => 'cond', 'field' => 'number', 'operator' => 'gte', 'value' => 'A-1'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
            ],
        ];

        $root = QueryBuilderParser::parseArray($tree, $this->resource);
        $this->assertInstanceOf(AtomGroup::class, $root);
        $this->assertCount(1, $root->children);
        $this->assertSame('status', $root->children[0]->field);
    }

    public function test_strict_false_returns_null_for_root_without_valid_children(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                ['type' => 'cond', 'field' => 'nonexistent', 'operator' => 'eq', 'value' => 'x'],
            ],
        ];

        $root = QueryBuilderParser::parseArray($tree, $this->resource);
        $this->assertNull($root);
    }

    // -------- strict=true happy path --------

    public function test_strict_true_simple_atom_becomes_and_group(): void
    {
        $tree = ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'];

        $root = QueryBuilderParser::parseArray($tree, $this->resource, strict: true);

        $this->assertInstanceOf(AtomGroup::class, $root);
        $this->assertSame('AND', $root->op);
        $this->assertFalse($root->not);
        $this->assertCount(1, $root->children);
        $this->assertInstanceOf(AtomCondition::class, $root->children[0]);
        $this->assertSame('status', $root->children[0]->field);
        $this->assertSame(Operator::Eq, $root->children[0]->operator);
        $this->assertSame('paid', $root->children[0]->value);
    }

    public function test_strict_true_or_group_with_two_atoms(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
            ],
        ];

        $root = QueryBuilderParser::parseArray($tree, $this->resource, strict: true);

        $this->assertInstanceOf(AtomGroup::class, $root);
        $this->assertSame('OR', $root->op);
        $this->assertCount(2, $root->children);
    }

    public function test_strict_true_nested_group_within_depth_limit(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                [
                    'type' => 'group',
                    'op' => 'OR',
                    'children' => [
                        ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                        ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
                    ],
                ],
                ['type' => 'cond', 'field' => 'total', 'operator' => 'gte', 'value' => '500'],
            ],
        ];

        $root = QueryBuilderParser::parseArray($tree, $this->resource, strict: true);

        $this->assertInstanceOf(AtomGroup::class, $root);
        $this->assertSame('AND', $root->op);
        $this->assertCount(2, $root->children);
        $this->assertInstanceOf(AtomGroup::class, $root->children[0]);
        $this->assertSame('OR', $root->children[0]->op);
    }

    // -------- strict=true negative cases --------

    public function test_strict_true_unknown_field_throws(): void
    {
        $tree = ['type' => 'cond', 'field' => 'nonexistent', 'operator' => 'eq', 'value' => 'x'];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_UNKNOWN_FIELD, $e->kind);
            $this->assertSame('nonexistent', $e->field);
        }
    }

    public function test_strict_true_unfilterable_field_throws_as_unknown(): void
    {
        // `id` объявлен в TestOrdersResource через ->sortable() без ->filterable(...);
        // QueryBuilderParser считает не-filterable поля unknown_field (single kind).
        $tree = ['type' => 'cond', 'field' => 'id', 'operator' => 'eq', 'value' => '1'];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_UNKNOWN_FIELD, $e->kind);
            $this->assertSame('id', $e->field);
        }
    }

    public function test_strict_true_operator_not_allowed_throws(): void
    {
        // number разрешает только [Eq, Contains], gte отклоняется
        $tree = ['type' => 'cond', 'field' => 'number', 'operator' => 'gte', 'value' => 'A-1'];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_OPERATOR_NOT_ALLOWED, $e->kind);
            $this->assertSame('number', $e->field);
            $this->assertSame('gte', $e->operator);
        }
    }

    public function test_strict_true_unknown_operator_throws(): void
    {
        $tree = ['type' => 'cond', 'field' => 'status', 'operator' => 'matches_regex', 'value' => '.*'];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_OPERATOR_NOT_ALLOWED, $e->kind);
            $this->assertSame('matches_regex', $e->operator);
        }
    }

    public function test_strict_true_invalid_op_throws(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'NAND',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
            ],
        ];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_INVALID_OP, $e->kind);
        }
    }

    public function test_strict_true_unknown_node_type_throws(): void
    {
        $tree = ['type' => 'weird', 'field' => 'status'];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_UNKNOWN_NODE_TYPE, $e->kind);
        }
    }

    public function test_strict_true_depth_exceeded_throws(): void
    {
        config(['tables.qb_max_depth' => 2]);

        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                [
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        [
                            'type' => 'group',
                            'op' => 'AND',
                            'children' => [
                                [
                                    'type' => 'group',
                                    'op' => 'AND',
                                    'children' => [
                                        ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_DEPTH_EXCEEDED, $e->kind);
        }
    }

    public function test_strict_true_atoms_exceeded_throws(): void
    {
        config(['tables.qb_max_atoms' => 2]);

        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'cancelled'],
            ],
        ];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_ATOMS_EXCEEDED, $e->kind);
        }
    }

    public function test_strict_true_empty_value_throws(): void
    {
        // Operator::Eq + пустая строка → KIND_EMPTY_VALUE (см. normalizeValue → scalarOrNull)
        $tree = ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => ''];

        try {
            QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_EMPTY_VALUE, $e->kind);
            $this->assertSame('status', $e->field);
        }
    }

    public function test_strict_true_malformed_base64_throws(): void
    {
        try {
            QueryBuilderParser::parse('not_valid_base64@@@', $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_MALFORMED_BASE64, $e->kind);
        }
    }

    public function test_strict_true_malformed_json_throws(): void
    {
        // base64 от строки «hello» (не-JSON)
        $b64 = base64_encode('hello not json');

        try {
            QueryBuilderParser::parse($b64, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_MALFORMED_JSON, $e->kind);
        }
    }

    public function test_strict_true_payload_too_large_throws(): void
    {
        config(['tables.qb_max_payload_size' => 32]);

        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid_with_lots_of_text'],
            ],
        ];
        $b64 = base64_encode((string) json_encode($tree));
        $this->assertGreaterThan(32, strlen($b64));

        try {
            QueryBuilderParser::parse($b64, $this->resource, strict: true);
            $this->fail('Expected QueryBuilderValidationException');
        } catch (QueryBuilderValidationException $e) {
            $this->assertSame(QueryBuilderValidationException::KIND_PAYLOAD_TOO_LARGE, $e->kind);
        }
    }

    // -------- round-trip --------

    public function test_round_trip_parse_and_parse_array_are_equivalent(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'total', 'operator' => 'gte', 'value' => '1000'],
            ],
        ];

        $fromArray = QueryBuilderParser::parseArray($tree, $this->resource, strict: true);
        $fromB64 = QueryBuilderParser::parse(base64_encode((string) json_encode($tree)), $this->resource, strict: true);

        $this->assertNotNull($fromArray);
        $this->assertNotNull($fromB64);
        $this->assertSame($fromArray->op, $fromB64->op);
        $this->assertSame($fromArray->not, $fromB64->not);
        $this->assertCount(count($fromArray->children), $fromB64->children);

        foreach ($fromArray->children as $i => $child) {
            /** @var AtomCondition $child */
            $other = $fromB64->children[$i];
            $this->assertInstanceOf(AtomCondition::class, $other);
            $this->assertSame($child->field, $other->field);
            $this->assertSame($child->operator, $other->operator);
            $this->assertSame($child->value, $other->value);
        }
    }
}
