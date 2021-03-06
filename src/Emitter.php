<?php

declare(strict_types=1);

namespace JustBlackBird\AmpPriorityEmitter;

use Amp\Deferred;
use Amp\Failure;
use Amp\Iterator;
use Amp\Promise;
use Amp\Success;
use JustBlackBird\StablePriorityQueue\Queue as PriorityQueue;
use React\Promise\PromiseInterface as ReactPromise;

/**
 * This emitter works similar to {@link \Amp\Emitter} but allows emitted
 * items to have consumption priority.
 *
 * @template TValue
 */
final class Emitter
{
    /** @var PriorityQueue<TValue>  */
    private PriorityQueue $values;
    /** @var PriorityQueue<Deferred<null>>  */
    private PriorityQueue $backPressure;

    /** @var Iterator<TValue> */
    private Iterator $iterator;

    /** @var Deferred<bool>|null */
    private ?Deferred $waiting = null;
    /** @var Promise<bool>|null */
    private ?Promise $complete = null;

    /** @var TValue|null */
    private $current = null;
    private bool $hasCurrent = false;

    public function __construct()
    {
        $this->values = new PriorityQueue();
        /** @var PriorityQueue<Deferred<null>> */
        $this->backPressure = new PriorityQueue();
        $this->iterator = new CallbackIterator(
            /** @psalm-return Promise<bool> */
            fn(): Promise => $this->advance(),
            /** @psalm-return TValue */
            fn() => $this->getCurrent()
        );
    }

    /**
     * @return Iterator
     * @psalm-return Iterator<TValue>
     */
    public function iterate(): Iterator
    {
        return $this->iterator;
    }

    /**
     * Emits a value to the iterator.
     *
     * @param mixed $value
     * @param int $priority
     *
     * @psalm-param TValue $value
     *
     * @return Promise<null>
     *
     * @throws \Error If the iterator has completed.
     */
    public function emit($value, int $priority = 0): Promise
    {
        if ($this->complete) {
            throw new \Error("Iterators cannot emit values after calling complete");
        }

        if ($value instanceof ReactPromise) {
            $value = Promise\adapt($value);
        }

        if ($value instanceof Promise) {
            /** @var Deferred<null> */
            $deferred = new Deferred();
            /**
             * @psalm-suppress MixedMethodCall
             * @psalm-suppress MissingClosureParamType
             */
            $value->onResolve(function (?\Throwable $e, $v) use ($deferred, $priority): void {
                if ($this->complete) {
                    $deferred->fail(
                        new \Error("The iterator was completed before the promise result could be emitted")
                    );
                    return;
                }

                if ($e) {
                    $this->fail($e);
                    $deferred->fail($e);
                    return;
                }

                $deferred->resolve($this->emit($v, $priority));
            });

            return $deferred->promise();
        }

        $this->values->insert($value, $priority);

        if (null !== $this->waiting) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $this->current = $this->values->extract();
            $this->hasCurrent = true;
            $waiting->resolve(true);

            // No need for back pressure if messages are consumed.
            return new Success();
        }

        /** @var Deferred<null> */
        $deferred = new Deferred();
        $this->backPressure->insert($deferred, $priority);

        return $deferred->promise();
    }

    /**
     * Completes the iterator.
     *
     * @return void
     *
     * @throws \Error If the iterator has already been completed.
     */
    public function complete(): void
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

    /**
     * Fails the iterator with the given reason.
     *
     * @param \Throwable $reason
     *
     * @return void
     */
    public function fail(\Throwable $reason): void
    {
        /** @var Promise<bool> */
        $this->complete = new Failure($reason);

        if (null !== $this->waiting) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $waiting->resolve($this->complete);
        }
    }

    /**
     * Move consumers to the next item.
     *
     * @return Promise<bool>
     */
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

        /** @var Deferred<bool> */
        $this->waiting = new Deferred();

        return $this->waiting->promise();
    }

    /**
     * Retrieves the current item.
     *
     * @return mixed
     *
     * @psalm-return TValue
     */
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
