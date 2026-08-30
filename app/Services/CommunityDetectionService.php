<?php

namespace App\Services;

/**
 * Deterministic Leiden-style community detection with hierarchical
 * condensation: local moving, a refinement pass that guarantees every
 * community is internally connected, then graph condensation — repeated
 * per hierarchy level. Dependency-free and cache-free.
 *
 * Determinism contract: for a given node/edge input the produced
 * hierarchy is always identical — nodes are processed in ascending index
 * order and candidate communities in ascending label order, so runs are
 * reproducible and community rebuilds stay idempotent.
 *
 * Disconnected components can never merge (they share no edges, so they
 * are never move candidates), which keeps the behaviour predictable for
 * isolated clusters while upgrading connected graphs to modularity-based
 * splits.
 */
class CommunityDetectionService
{
    private const MAX_PASSES = 16;

    private const EPSILON = 1e-12;

    /**
     * Compute one partition per hierarchy level (finest first).
     *
     * @param  list<int>  $nodeIds  original entity ids
     * @param  list<array{source:int, target:int, weight:float}>  $edges
     *                                                                    undirected edges; duplicate pairs are aggregated, weights
     *                                                                    <= 0 and unknown nodes are ignored
     * @param  int  $maxLevels  upper bound on returned levels
     * @return list<array<int, int>> each entry maps original node id to a
     *                               community label (stable within that level)
     */
    public function hierarchicalPartitions(array $nodeIds, array $edges, int $maxLevels = 2): array
    {
        $maxLevels = max(1, $maxLevels);
        $count = count($nodeIds);
        if ($count === 0) {
            return [];
        }

        $indexOf = [];
        foreach ($nodeIds as $i => $nodeId) {
            $indexOf[$nodeId] = $i;
        }

        $adjacency = array_fill(0, $count, []);
        $loops = array_fill(0, $count, 0.0);
        foreach ($edges as $edge) {
            $u = $indexOf[$edge['source']] ?? null;
            $v = $indexOf[$edge['target']] ?? null;
            $weight = (float) ($edge['weight'] ?? 0);
            if ($u === null || $v === null || $weight <= 0) {
                continue;
            }
            if ($u === $v) {
                $loops[$u] += $weight;

                continue;
            }
            $adjacency[$u][$v] = ($adjacency[$u][$v] ?? 0.0) + $weight;
            $adjacency[$v][$u] = ($adjacency[$v][$u] ?? 0.0) + $weight;
        }

        $degrees = [];
        for ($i = 0; $i < $count; $i++) {
            $degrees[$i] = array_sum($adjacency[$i] ?? []) + 2 * $loops[$i];
        }

        // superMembers[i] holds the original node ids represented by the
        // current graph's node i (condensation merges these lists).
        $superMembers = [];
        for ($i = 0; $i < $count; $i++) {
            $superMembers[$i] = [$nodeIds[$i]];
        }

        $partitions = [];
        $previousSignature = null;

        for ($level = 0; $level < $maxLevels; $level++) {
            $partition = $this->localMoving($adjacency, $degrees);
            // Leiden refinement: re-run local moving with node moves
            // restricted to the community they were just assigned to.
            // This guarantees every stored community is internally
            // connected, which plain Louvain does not.
            $partition = $this->localMoving($adjacency, $degrees, $partition);

            // Group current nodes by community label, in label order.
            $groupedNodes = [];
            foreach ($partition as $i => $label) {
                $groupedNodes[$label][] = $i;
            }
            ksort($groupedNodes);

            // Project onto original ids for a stable, comparable grouping.
            $groupedOriginals = [];
            foreach ($groupedNodes as $label => $nodeIndexes) {
                $members = [];
                foreach ($nodeIndexes as $i) {
                    foreach ($superMembers[$i] as $original) {
                        $members[] = $original;
                    }
                }
                sort($members);
                $groupedOriginals[$label] = $members;
            }

            $signature = serialize($groupedOriginals);
            if ($signature === $previousSignature) {
                // Condensation did not produce a new grouping — the
                // hierarchy has converged.
                break;
            }
            $previousSignature = $signature;

            $mapped = [];
            foreach ($groupedOriginals as $label => $members) {
                foreach ($members as $original) {
                    $mapped[$original] = $label;
                }
            }
            $partitions[] = $mapped;

            $communityCount = count($groupedOriginals);
            if ($communityCount <= 1 || $communityCount === $count) {
                // Either everything merged into one community, or nothing
                // merged at all — no further condensation is possible.
                break;
            }

            [$adjacency, $loops] = $this->condense($adjacency, $loops, $partition);
            $count = $communityCount;
            $degrees = [];
            for ($i = 0; $i < $count; $i++) {
                $degrees[$i] = array_sum($adjacency[$i] ?? []) + 2 * $loops[$i];
            }

            $nextMembers = [];
            foreach ($groupedNodes as $label => $nodeIndexes) {
                $members = [];
                foreach ($nodeIndexes as $i) {
                    foreach ($superMembers[$i] as $original) {
                        $members[] = $original;
                    }
                }
                $nextMembers[$label] = $members;
            }
            ksort($nextMembers);
            $superMembers = array_values($nextMembers);
        }

        return $partitions;
    }

    /**
     * Local moving: repeatedly move nodes to the neighbouring community
     * with the highest modularity gain (computed against the full graph)
     * until a full pass moves nothing. Returns per-node community labels
     * renumbered 0..k-1 in first-seen node order.
     *
     * When `$restriction` is given (Leiden refinement), a node may only
     * move to communities containing neighbours that share its
     * restriction label — used to split each community into internally
     * connected sub-communities. The gain function still uses the full
     * graph's degrees, so refinement only splits a community when the
     * split is modularity-neutral or better, never on a whim.
     *
     * @param  array<int, array<int, float>>  $adjacency
     * @param  array<int, float>  $degrees
     * @param  array<int, int>|null  $restriction
     * @return array<int, int>
     */
    private function localMoving(array $adjacency, array $degrees, ?array $restriction = null): array
    {
        $count = count($degrees);
        $community = range(0, $count - 1);
        $totalDegree = $degrees;
        $m2 = array_sum($degrees);

        if ($m2 <= 0) {
            return $community;
        }

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $moved = false;

            for ($i = 0; $i < $count; $i++) {
                $kI = $degrees[$i];
                $old = $community[$i];

                // Aggregate edge weights from i to each eligible
                // neighbouring community; candidate labels iterate in
                // ascending order.
                $weightsTo = [];
                foreach ($adjacency[$i] ?? [] as $neighbour => $weight) {
                    if ($restriction !== null && $restriction[$neighbour] !== $restriction[$i]) {
                        continue;
                    }
                    $label = $community[$neighbour];
                    $weightsTo[$label] = ($weightsTo[$label] ?? 0.0) + $weight;
                }
                ksort($weightsTo);

                $bestLabel = $old;
                $bestGain = ($weightsTo[$old] ?? 0.0)
                    - (($totalDegree[$old] - $kI) * $kI / $m2);

                foreach ($weightsTo as $label => $weightSum) {
                    // Only i's own community needs correcting: i was not
                    // physically removed, every other candidate excludes it.
                    $totalSum = $label === $old
                        ? $totalDegree[$label] - $kI
                        : $totalDegree[$label];
                    $gain = $weightSum - ($totalSum * $kI / $m2);

                    if ($gain > $bestGain + self::EPSILON) {
                        $bestGain = $gain;
                        $bestLabel = $label;
                    }
                }

                if ($bestLabel !== $old) {
                    $community[$i] = $bestLabel;
                    $totalDegree[$old] -= $kI;
                    $totalDegree[$bestLabel] += $kI;
                    $moved = true;
                }
            }

            if (! $moved) {
                break;
            }
        }

        // Renumber labels 0..k-1 in first-seen node order.
        $renumber = [];
        $next = 0;
        for ($i = 0; $i < $count; $i++) {
            if (! isset($renumber[$community[$i]])) {
                $renumber[$community[$i]] = $next++;
            }
        }

        return array_map(fn ($label) => $renumber[$label], $community);
    }

    /**
     * Louvain phase 2: collapse each community into a super-node.
     * External edge weights are summed; internal edge weights become the
     * community's self-loop so the total degree (2m) is conserved.
     *
     * @param  array<int, array<int, float>>  $adjacency
     * @param  array<int, float>  $loops
     * @param  array<int, int>  $partition
     * @return array{0: array<int, array<int, float>>, 1: array<int, float>}
     */
    private function condense(array $adjacency, array $loops, array $partition): array
    {
        $newAdjacency = [];
        $newLoops = [];

        foreach ($partition as $i => $label) {
            $newLoops[$label] = ($newLoops[$label] ?? 0.0) + ($loops[$i] ?? 0.0);
        }

        foreach ($adjacency as $u => $neighbours) {
            $cu = $partition[$u];
            foreach ($neighbours as $v => $weight) {
                $cv = $partition[$v];
                if ($cu === $cv) {
                    // Internal edges appear twice (symmetric storage);
                    // count each once by only visiting the u < v side.
                    if ($u < $v) {
                        $newLoops[$cu] += $weight;
                    }

                    continue;
                }
                $newAdjacency[$cu][$cv] = ($newAdjacency[$cu][$cv] ?? 0.0) + $weight;
            }
        }

        return [$newAdjacency, $newLoops];
    }
}
