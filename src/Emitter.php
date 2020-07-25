<?php

declare(strict_types=1);

namespace JustBlackBird\AmpPriorityEmitter;

use Amp\Deferred;
use Amp\Failure;
use Amp\Iterator;
use Amp\Promise;
use Amp\Success;

final class Emitter
{
    private \SplPriorityQueue $values;
    private \SplPriorityQueue $backPressure;
    private Iterator $iterator;
    private $current;
    private bool $hasCurrent = false;
    private ?Deferred $waiting = null;
    private ?Promise $complete = null;

    private PrioritySequence $sequence;

    public function __construct()
    {
        $this->values = new \SplPriorityQueue();
        $this->backPressure = new \SplPriorityQueue();
        $this->sequence = new PrioritySequence();
        $this->iterator = new CallbackIterator(
            fn(): Promise => $this->advance(),
            fn() => $this->getCurrent()
        );
    }

    public function iterate(): Iterator
    {
        return $this->iterator;
    }

    public function emit($value, int $priority = 0): Promise
    {
        $uniquePriority = $this->sequence->next($priority);

        $this->values->insert($value, $uniquePriority);

        if ($this->waiting) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $this->current = $this->values->extract();
            $this->hasCurrent = true;
            $waiting->resolve(true);

            // No need for back pressure if messages are consumed.
            return new Success();
        }

        $deferred = new Deferred();
        $this->backPressure->insert($deferred, $uniquePriority);

        return $deferred->promise();
    }

    public function complete()
    {
        if ($this->complete) {
            throw new \Error('CallbackIterator has already been completed');
        }

        $this->complete = new Success(false);

        if (null !== $this->waiting) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $waiting->resolve($this->complete);
        }
    }

    public function fail(\Throwable $reason)
    {
        $this->complete = new Failure($reason);

        if ($this->waiting !== null) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $waiting->resolve($this->complete);
        }
    }

    private function advance(): Promise
    {
        if (null !== $this->waiting) {
            throw new \Error('The prior promise returned must resolve before invoking this method again');
        }

        if (!$this->values->isEmpty()) {
            $this->current = $this->values->extract();
            $this->hasCurrent = true;

            if (!$this->backPressure->isEmpty()) {
                // Unpause producers with the most valuable messages first.
                $this->backPressure->extract()->resolve();
            }

            return new Success(true);
        }

        if ($this->complete) {
            return $this->complete;
        }

        $this->waiting = new Deferred();

        return $this->waiting->promise();
    }

    private function getCurrent()
    {
        if (!$this->hasCurrent && $this->complete) {
            throw new \Error('The iterator has completed with no items');
        }

        if (!$this->hasCurrent) {
            throw new \Error('Promise returned from advance() must resolve before calling this method');
        }

        return $this->current;
    }
}
