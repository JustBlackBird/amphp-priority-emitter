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

    private int $timestamp = 0;
    private int $offset = 0;

    public function __construct()
    {
        $this->values = new \SplPriorityQueue();
        $this->backPressure = new \SplPriorityQueue();
        $this->iterator = $this->createIterator();
    }

    public function iterate(): Iterator
    {
        return $this->iterator;
    }

    public function emit($value, int $priority = 0): Promise
    {
        $uniquePriority = $this->normalizePriority($priority);

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
            throw new \Error('Iterator has already been completed');
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

    private function createIterator(): Iterator
    {
        $advance = function (): Promise {
            return $this->advance();
        };

        $getCurrent = function () {
            return $this->getCurrent();
        };

        return new class ($advance, $getCurrent) implements Iterator {
            private $advance;
            private $getCurrent;

            public function __construct(callable $advance, callable $getCurrent)
            {
                $this->advance = $advance;
                $this->getCurrent = $getCurrent;
            }

            public function advance(): Promise
            {
                return ($this->advance)();
            }

            public function getCurrent()
            {
                return ($this->getCurrent)();
            }
        };
    }

    private function normalizePriority(int $priority): string
    {
        $now = time();
        if ($this->timestamp !== $now) {
            // Offset is unique within each second.
            $this->timestamp = $now;
            $this->offset = 0;
        }

        // Build unique priority key in form of "XXXXXXXXYYYYZZZZ", where:
        // - "XXXXXXXX" is a 64 bit long binary string which build from
        //   priority used by client code.
        // - "YYYY" is a 32 bit long binary string which is built from current
        //   timestamp. This value is used to split value with same priority
        //   came in different moment of time.
        // - "ZZZZ" is a 32 bit long binary string which is built from unique
        //   offset each message got within time bucket.
        //
        // The resulting value is a binary string which can be used as priority
        // for SplPriorityQueue. Segments of the string make the order
        // functions (like strcmp) sort value by client's priority first, then
        // by timestamp of insertion and by unique index in time bucket at
        // last.
        return pack(
            'JNN',
            // Swap negative and positive blocks of integers to make
            // correct order when integers are compared by bites
            // in big-endian order.
            $priority ^ 1 << 63,
            // Invert timestamps to use lower priority for later timestamps.
            PHP_INT_MAX - $this->timestamp,
            // Invert offset to use lower priority for values that was
            // emitted later.
            PHP_INT_MAX - $this->offset++
        );
    }
}
