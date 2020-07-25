<?php

declare(strict_types=1);

namespace JustBlackBird\AmpPriorityEmitter;

/**
 * Priority queue implementation which keeps stable order for items with
 * equal priority.
 *
 * @internal
 */
final class StablePriorityQueue
{
    private PrioritySequence $sequence;
    private \SplPriorityQueue $queue;

    public function __construct()
    {
        $this->sequence = new PrioritySequence();
        $this->queue = new \SplPriorityQueue();
    }

    /**
     * Inserts an item into the queue.
     *
     * @param mixed $value
     * @param int $priority
     */
    public function insert($value, int $priority): void
    {
        $this->queue->insert($value, $this->sequence->next($priority));
    }

    /**
     * Extracts an item with maximum priority.
     *
     * @return mixed
     */
    public function extract()
    {
        return $this->queue->extract();
    }

    /**
     * Checks if the queue is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }
}
