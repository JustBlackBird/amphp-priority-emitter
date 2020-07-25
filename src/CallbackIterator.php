<?php

declare(strict_types=1);

namespace JustBlackBird\AmpPriorityEmitter;

use Amp\Iterator;
use Amp\Promise;

/**
 * An iterator implementation that hides emitter's API from consumer code.
 *
 * @internal
 * @template TValue
 */
final class CallbackIterator implements Iterator
{
    /**
     * @var callable(): Promise<bool>
     */
    private $advance;

    /**
     * @var callable(): TValue
     */
    private $getCurrent;

    /**
     * @param callable(): Promise<bool> $advance
     * @param callable(): TValue $getCurrent
     */
    public function __construct(callable $advance, callable $getCurrent)
    {
        $this->advance = $advance;
        $this->getCurrent = $getCurrent;
    }

    /**
     * {@inheritDoc}
     */
    public function advance(): Promise
    {
        return ($this->advance)();
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrent()
    {
        return ($this->getCurrent)();
    }
}
