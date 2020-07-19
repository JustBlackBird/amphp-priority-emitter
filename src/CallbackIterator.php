<?php

declare(strict_types=1);

namespace JustBlackBird\AmpPriorityEmitter;

use Amp\Iterator;
use Amp\Promise;

/**
 * An iterator implementation that hides emitter's API from consumer code.
 */
final class CallbackIterator implements Iterator
{
    /**
     * @var callable
     */
    private $advance;

    /**
     * @var callable
     */
    private $getCurrent;

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
