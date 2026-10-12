<?php

namespace Tests\Unit;

use App\Domain\Engine\TopologicalSorter;
use App\Domain\Integrations\Handlers\LogicHandler;
use App\Domain\Integrations\VariableResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EngineLogicTest extends TestCase
{
    private array $context = ['steps' => [
        'trigger' => ['data' => ['email' => 'ada@example.com', 'tags' => ['a', 'b'], 'count' => 3, 'items' => [['id' => 1], ['id' => 2]]]],
    ]];

    public function test_resolver_keeps_types_for_exact_placeholders_and_interpolates_text(): void
    {
        $resolver = new VariableResolver;

        $this->assertSame(['a', 'b'], $resolver->resolve('{{ steps.trigger.data.tags }}', $this->context));
        $this->assertSame(3, $resolver->resolve(' {{steps.trigger.data.count}} ', $this->context));
        $this->assertSame('To: ada@example.com (3)', $resolver->resolve('To: {{ steps.trigger.data.email }} ({{ steps.trigger.data.count }})', $this->context));
        $this->assertSame('tags=["a","b"] missing=', $resolver->resolve('tags={{ steps.trigger.data.tags }} missing={{ steps.nope.x }}', $this->context));
        $this->assertSame(2, $resolver->resolve('{{ steps.trigger.data.items[1].id }}', $this->context));
        $this->assertSame([1, 2], $resolver->resolve('{{ steps.trigger.data.items.*.id }}', $this->context));
        $this->assertSame(['to' => 'ada@example.com', 'n' => [3]], $resolver->resolve(['to' => '{{ steps.trigger.data.email }}', 'n' => ['{{ steps.trigger.data.count }}']], $this->context));
        $this->assertSame('/users/a%20b%2Fc', $resolver->resolve('/users/{{ inputs.id }}', ['inputs' => ['id' => 'a b/c']], 'rawurlencode'));
    }

    public function test_topological_sort_orders_dag_and_rejects_cycles(): void
    {
        $sorter = new TopologicalSorter;
        $edges = [['source' => 'trigger', 'target' => 'b'], ['source' => 'trigger', 'target' => 'a'], ['source' => 'a', 'target' => 'c'], ['source' => 'b', 'target' => 'c']];

        $this->assertSame(['trigger', 'a', 'b', 'c'], $sorter->sort(['trigger', 'a', 'b', 'c'], $edges));

        $this->expectException(RuntimeException::class);
        $sorter->sort(['trigger', 'a', 'b'], [['source' => 'trigger', 'target' => 'a'], ['source' => 'a', 'target' => 'b'], ['source' => 'b', 'target' => 'a']]);
    }

    public function test_condition_operators(): void
    {
        $logic = new LogicHandler;

        $this->assertTrue($logic->compare('10', 'greater_than', '9'));          // numeric, not string compare
        $this->assertTrue($logic->compare('ada@example.com', 'ends_with', '.com'));
        $this->assertTrue($logic->compare(['a', 'b'], 'contains', 'b'));
        $this->assertTrue($logic->compare('', 'is_empty', null));
        $this->assertTrue($logic->compare('INV-2041', 'matches_regex', '^INV-\d+$'));
        $this->assertFalse($logic->compare('pro', 'equals', 'free'));
        $this->assertTrue($logic->evaluate([['left' => 1, 'operator' => 'equals', 'right' => 2], ['left' => 1, 'operator' => 'equals', 'right' => 1]], 'any'));
        $this->assertFalse($logic->evaluate([['left' => 1, 'operator' => 'equals', 'right' => 2], ['left' => 1, 'operator' => 'equals', 'right' => 1]], 'all'));
    }
}
