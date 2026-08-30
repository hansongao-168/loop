<?php

namespace Tests\Unit;

use App\Services\CommunityDetectionService;
use PHPUnit\Framework\TestCase;

class CommunityDetectionServiceTest extends TestCase
{
    private CommunityDetectionService $detector;

    protected function setUp(): void
    {
        $this->detector = new CommunityDetectionService;
    }

    public function test_two_bridgeless_clusters_stay_separate_on_a_single_level(): void
    {
        // Two triangles joined by one weak bridge: modularity keeps them
        // apart, and since the bridge is too weak to ever justify a merge
        // the hierarchy converges after level 0.
        $edges = [
            ...$this->triangle(1, 2, 3),
            ...$this->triangle(4, 5, 6),
            ['source' => 3, 'target' => 4, 'weight' => 0.1],
        ];

        $partitions = $this->detector->hierarchicalPartitions(range(1, 6), $edges, 3);

        $this->assertCount(1, $partitions);
        $this->assertSame(
            [[1, 2, 3], [4, 5, 6]],
            $this->groupByLabel($partitions[0]),
        );
    }

    public function test_hierarchical_chain_produces_two_levels(): void
    {
        // Four pairs chained by strong bridges and one weak diagonal:
        // level 0 finds the four pairs, level 1 merges the pairs into
        // the two halves separated by the weak edge (4-5).
        //
        // 1-2 = 1-3 = 3-4 = 5-6 = 6-7 = 7-8 = 1.0, 2-3 = 6-7 bridge 1.0,
        // 4-5 = 0.4 (weak).
        $edges = [
            ['source' => 1, 'target' => 2, 'weight' => 1.0],
            ['source' => 3, 'target' => 4, 'weight' => 1.0],
            ['source' => 5, 'target' => 6, 'weight' => 1.0],
            ['source' => 7, 'target' => 8, 'weight' => 1.0],
            ['source' => 2, 'target' => 3, 'weight' => 1.0],
            ['source' => 6, 'target' => 7, 'weight' => 1.0],
            ['source' => 4, 'target' => 5, 'weight' => 0.4],
        ];

        $partitions = $this->detector->hierarchicalPartitions(range(1, 8), $edges, 3);

        $this->assertCount(2, $partitions);
        $this->assertSame(
            [[1, 2], [3, 4], [5, 6], [7, 8]],
            $this->groupByLabel($partitions[0]),
        );
        $this->assertSame(
            [[1, 2, 3, 4], [5, 6, 7, 8]],
            $this->groupByLabel($partitions[1]),
        );
    }

    public function test_disconnected_components_never_merge_at_any_level(): void
    {
        $edges = [
            ['source' => 1, 'target' => 2, 'weight' => 1.0],
            ['source' => 3, 'target' => 4, 'weight' => 1.0],
        ];

        $partitions = $this->detector->hierarchicalPartitions(range(1, 4), $edges, 4);

        $this->assertCount(1, $partitions);
        $this->assertSame([[1, 2], [3, 4]], $this->groupByLabel($partitions[0]));
    }

    public function test_isolated_nodes_become_singleton_communities(): void
    {
        $partitions = $this->detector->hierarchicalPartitions([7, 9, 11], [], 2);

        $this->assertCount(1, $partitions);
        $this->assertSame([[7], [9], [11]], $this->groupByLabel($partitions[0]));
        $this->assertSame([7 => 0, 9 => 1, 11 => 2], $partitions[0]);
    }

    public function test_empty_graph_yields_no_partitions(): void
    {
        $this->assertSame([], $this->detector->hierarchicalPartitions([], [], 2));
    }

    public function test_detection_is_deterministic_across_runs(): void
    {
        $edges = [
            ...$this->triangle(1, 2, 3),
            ...$this->triangle(4, 5, 6),
            ['source' => 3, 'target' => 4, 'weight' => 0.6],
            ['source' => 2, 'target' => 5, 'weight' => 0.6],
            ['source' => 1, 'target' => 6, 'weight' => 0.05],
        ];

        $first = $this->detector->hierarchicalPartitions(range(1, 6), $edges, 3);
        $second = $this->detector->hierarchicalPartitions(range(1, 6), $edges, 3);

        $this->assertSame($first, $second);
    }

    public function test_duplicate_edges_are_aggregated_and_unknown_nodes_ignored(): void
    {
        $edges = [
            ['source' => 1, 'target' => 2, 'weight' => 1.0],
            ['source' => 2, 'target' => 1, 'weight' => 1.0],
            ['source' => 99, 'target' => 100, 'weight' => 5.0],
        ];

        $partitions = $this->detector->hierarchicalPartitions([1, 2], $edges, 2);

        $this->assertCount(1, $partitions);
        $this->assertSame([[1, 2]], $this->groupByLabel($partitions[0]));
    }

    /**
     * Leiden guarantee: every stored community must be internally
     * connected. Plain Louvain can lump unrelated clusters together; the
     * refinement phase must never let such a grouping survive to a
     * stored level.
     */
    public function test_every_stored_community_is_internally_connected(): void
    {
        $graphs = [
            'two squares bridged' => [
                [1, 2, 1.0], [2, 3, 1.0], [3, 4, 1.0], [4, 1, 1.0],
                [5, 6, 1.0], [6, 7, 1.0], [7, 8, 1.0], [8, 5, 1.0],
                [4, 5, 0.9],
            ],
            'ring of cliques' => [
                [1, 2, 3.0], [3, 4, 3.0], [5, 6, 3.0],
                [2, 3, 2.0], [4, 5, 2.0], [6, 1, 2.0],
            ],
            'chained pairs' => [
                [1, 2, 1.0], [3, 4, 1.0], [5, 6, 1.0], [7, 8, 1.0],
                [2, 3, 1.0], [6, 7, 1.0], [4, 5, 0.4],
            ],
            'two triangles plus weak bridges' => [
                [1, 2, 1.0], [2, 3, 1.0], [1, 3, 1.0],
                [4, 5, 1.0], [5, 6, 1.0], [4, 6, 1.0],
                [3, 4, 0.6], [2, 5, 0.6],
            ],
        ];

        foreach ($graphs as $name => $edgeList) {
            $nodes = range(1, max(array_map(fn (array $edge) => max($edge[0], $edge[1]), $edgeList)));
            $edges = array_map(fn (array $edge) => ['source' => $edge[0], 'target' => $edge[1], 'weight' => $edge[2]], $edgeList);

            $partitions = $this->detector->hierarchicalPartitions($nodes, $edges, 4);
            $this->assertNotSame([], $partitions, "{$name}: expected at least one level");

            foreach ($partitions as $level => $partition) {
                foreach ($this->groupByLabel($partition) as $members) {
                    $this->assertTrue(
                        $this->isConnected($members, $edgeList),
                        "{$name}: level {$level} community [".implode(',', $members).'] is not internally connected.',
                    );
                }
            }
        }
    }

    /**
     * Breadth-first connectivity check over the subgraph induced by
     * $members.
     *
     * @param  list<int>  $members
     * @param  list<array{0:int, 1:int, 2:float}>  $edgeList
     */
    private function isConnected(array $members, array $edgeList): bool
    {
        $memberSet = array_fill_keys($members, true);
        $adjacency = [];
        foreach ($edgeList as [$u, $v, $weight]) {
            if ($weight <= 0 || ! isset($memberSet[$u]) || ! isset($memberSet[$v])) {
                continue;
            }
            $adjacency[$u][] = $v;
            $adjacency[$v][] = $u;
        }

        $visited = [$members[0] => true];
        $queue = [$members[0]];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $neighbour) {
                if (! isset($visited[$neighbour])) {
                    $visited[$neighbour] = true;
                    $queue[] = $neighbour;
                }
            }
        }

        return count($visited) === count($members);
    }

    /**
     * Convert a node => label map into sorted member groups, ordered by
     * the smallest member id, so assertions can compare pure groupings.
     *
     * @param  array<int, int>  $partition
     * @return list<list<int>>
     */
    private function groupByLabel(array $partition): array
    {
        $grouped = [];
        foreach ($partition as $node => $label) {
            $grouped[$label][] = $node;
        }

        $groups = array_values($grouped);
        foreach ($groups as &$group) {
            sort($group);
        }
        usort($groups, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $groups;
    }

    /** @return list<array{source:int, target:int, weight:float}> */
    private function triangle(int $a, int $b, int $c): array
    {
        return [
            ['source' => $a, 'target' => $b, 'weight' => 1.0],
            ['source' => $b, 'target' => $c, 'weight' => 1.0],
            ['source' => $a, 'target' => $c, 'weight' => 1.0],
        ];
    }
}
