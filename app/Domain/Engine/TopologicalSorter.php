<?php

namespace App\Domain\Engine;

use RuntimeException;

/**
 * Kahn's algorithm. Keeps the original node order among nodes that are ready at the same time,
 * so execution order is deterministic.
 */
class TopologicalSorter
{
    /**
     * @param  list<string>  $nodeIds
     * @param  list<array{source: string, target: string}>  $edges
     * @return list<string>
     *
     * @throws RuntimeException when the graph has a cycle
     */
    public function sort(array $nodeIds, array $edges): array
    {
        $inDegree = array_fill_keys($nodeIds, 0);
        $children = array_fill_keys($nodeIds, []);

        foreach ($edges as $edge) {
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            if (! isset($inDegree[$source], $inDegree[$target]) || in_array($target, $children[$source], true)) {
                continue; // dangling or duplicate edge
            }
            $children[$source][] = $target;
            $inDegree[$target]++;
        }

        $position = array_flip($nodeIds);
        $ready = array_keys(array_filter($inDegree, fn ($degree) => $degree === 0));
        $sorted = [];

        while ($ready !== []) {
            usort($ready, fn ($a, $b) => $position[$a] <=> $position[$b]);
            $id = array_shift($ready);
            $sorted[] = $id;

            foreach ($children[$id] as $child) {
                if (--$inDegree[$child] === 0) {
                    $ready[] = $child;
                }
            }
        }

        if (count($sorted) !== count($nodeIds)) {
            $stuck = implode(', ', array_keys(array_filter($inDegree, fn ($degree) => $degree > 0)));
            throw new RuntimeException("The workflow contains a loop involving: {$stuck}.");
        }

        return $sorted;
    }
}
