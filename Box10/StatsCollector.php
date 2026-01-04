<?php

declare(strict_types=1);

/**
 * StatsCollector
 *
 * Tracks ISR cache metrics in memory for monitoring and optimization.
 * Simple counters and calculations - no persistence, just data collection.
 */
class StatsCollector
{
    /** @var int Total cache hits */
    private int $hits = 0;

    /** @var int Total cache misses */
    private int $misses = 0;

    /** @var int Total stale serves (ISR pattern) */
    private int $staleServes = 0;

    /** @var int Total generation events recorded */
    private int $generations = 0;

    /** @var float Total generation time in seconds */
    private float $totalGenerationTime = 0.0;

    /** @var float Minimum generation time in seconds */
    private float $minGenerationTime = PHP_FLOAT_MAX;

    /** @var float Maximum generation time in seconds */
    private float $maxGenerationTime = 0.0;

    /**
     * Record an event
     *
     * @param array $event Event data with 'event' type and optional metadata
     * @return array Result with 'recorded' status and current stats
     */
    public function record(array $event): array
    {
        $eventType = $event["event"] ?? "";

        switch ($eventType) {
            case "cache_hit":
                $this->hits++;
                break;

            case "cache_miss":
                $this->misses++;
                break;

            case "stale_serve":
                $this->staleServes++;
                break;

            case "generation":
                $this->recordGeneration($event);
                break;

            default:
                // Unknown event type - don't record
                return [
                    "recorded" => false,
                    "current_stats" => $this->getStats(),
                ];
        }

        return [
            "recorded" => true,
            "current_stats" => $this->getStats(),
        ];
    }

    /**
     * Record a generation event with timing
     *
     * @param array $event Event with 'duration' in metadata
     */
    private function recordGeneration(array $event): void
    {
        $duration = $event["metadata"]["duration"] ?? 0.0;

        if ($duration <= 0) {
            return; // Invalid duration, skip
        }

        $this->generations++;
        $this->totalGenerationTime += $duration;

        if ($duration < $this->minGenerationTime) {
            $this->minGenerationTime = $duration;
        }

        if ($duration > $this->maxGenerationTime) {
            $this->maxGenerationTime = $duration;
        }
    }

    /**
     * Get current statistics
     *
     * @return array Current stats with calculated metrics
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;

        $stats = [
            "hits" => $this->hits,
            "misses" => $this->misses,
            "stale_serves" => $this->staleServes,
            "hit_rate" =>
                $total > 0 ? round(($this->hits / $total) * 100, 1) : 0.0,
            "total_requests" => $total,
        ];

        // Add generation stats if we have any
        if ($this->generations > 0) {
            $stats["generation"] = [
                "count" => $this->generations,
                "total_time" => round($this->totalGenerationTime, 4),
                "avg_time" => round(
                    $this->totalGenerationTime / $this->generations,
                    4,
                ),
                "min_time" => round($this->minGenerationTime, 4),
                "max_time" => round($this->maxGenerationTime, 4),
            ];
        }

        return $stats;
    }

    /**
     * Reset all statistics
     *
     * Useful for testing or periodic resets
     */
    public function reset(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->staleServes = 0;
        $this->generations = 0;
        $this->totalGenerationTime = 0.0;
        $this->minGenerationTime = PHP_FLOAT_MAX;
        $this->maxGenerationTime = 0.0;
    }

    /**
     * Record multiple events in batch
     *
     * @param array $events Array of event arrays
     * @return array Summary of batch recording
     */
    public function recordBatch(array $events): array
    {
        $recorded = 0;
        $failed = 0;

        foreach ($events as $event) {
            $result = $this->record($event);
            if ($result["recorded"]) {
                $recorded++;
            } else {
                $failed++;
            }
        }

        return [
            "recorded" => $recorded,
            "failed" => $failed,
            "current_stats" => $this->getStats(),
        ];
    }

    /**
     * Get simple summary for quick monitoring
     *
     * @return array Simplified stats for dashboards
     */
    public function getSummary(): array
    {
        $total = $this->hits + $this->misses;

        return [
            "hit_rate" =>
                $total > 0 ? round(($this->hits / $total) * 100, 1) : 0.0,
            "total_requests" => $total,
            "stale_serves" => $this->staleServes,
            "avg_generation_time" =>
                $this->generations > 0
                    ? round($this->totalGenerationTime / $this->generations, 4)
                    : 0.0,
        ];
    }

    /**
     * Export raw data for external logging/persistence
     *
     * @return array Raw counter values
     */
    public function exportRaw(): array
    {
        return [
            "hits" => $this->hits,
            "misses" => $this->misses,
            "stale_serves" => $this->staleServes,
            "generations" => $this->generations,
            "total_generation_time" => $this->totalGenerationTime,
            "min_generation_time" =>
                $this->minGenerationTime !== PHP_FLOAT_MAX
                    ? $this->minGenerationTime
                    : 0.0,
            "max_generation_time" => $this->maxGenerationTime,
        ];
    }

    /**
     * Import raw data (for restoration from external storage)
     *
     * @param array $data Raw counter values from exportRaw()
     */
    public function importRaw(array $data): void
    {
        $this->hits = $data["hits"] ?? 0;
        $this->misses = $data["misses"] ?? 0;
        $this->staleServes = $data["stale_serves"] ?? 0;
        $this->generations = $data["generations"] ?? 0;
        $this->totalGenerationTime = $data["total_generation_time"] ?? 0.0;
        $this->minGenerationTime =
            $data["min_generation_time"] ?? PHP_FLOAT_MAX;
        $this->maxGenerationTime = $data["max_generation_time"] ?? 0.0;
    }
}
