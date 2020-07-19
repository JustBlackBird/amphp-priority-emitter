<?php

declare(strict_types=1);

namespace JustBlackBird\AmpPriorityEmitter\Tests;

use Amp\Deferred;
use Amp\Delayed;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use JustBlackBird\AmpPriorityEmitter\Emitter;

class EmitterTest extends AsyncTestCase
{
    private Emitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emitter = new Emitter();
    }

    public function testOrderForSamePriorityItems(): \Generator
    {
        Loop::defer(function () {
            $this->emitter->emit(1, 0);
            $this->emitter->emit(2, 0);
            $this->emitter->emit(3, 0);
            $this->emitter->emit(4, 0);
            $this->emitter->emit(5, 0);
            $this->emitter->complete();
        });

        $done = new Deferred();

        // The second defer is used to push all the items to the queue
        // before consuming.
        Loop::defer(function () use ($done) {
            $items = [];
            $iterator = $this->emitter->iterate();
            while (yield $iterator->advance()) {
                $items[] = $iterator->getCurrent();
            }

            $this->assertSame([1, 2, 3, 4, 5], $items);

            $done->resolve();
        });

        yield $done->promise();
    }

    public function testOrderForDifferentPriorityItems(): \Generator
    {
        Loop::defer(function () {
            $this->emitter->emit(1, 1);
            $this->emitter->emit(2, 1);
            $this->emitter->emit(3, 2);
            $this->emitter->emit(4, 3);
            $this->emitter->emit(5, 3);
            $this->emitter->complete();
        });

        $done = new Deferred();

        // The second defer is used to push all the items to the queue
        // before consuming.
        Loop::defer(function () use ($done) {
            $items = [];
            $iterator = $this->emitter->iterate();
            while (yield $iterator->advance()) {
                $items[] = $iterator->getCurrent();
            }

            $this->assertSame([4, 5, 3, 1, 2], $items);

            $done->resolve();
        });

        yield $done->promise();
    }

    public function testConsumerIsWaitingForMessagesToBeEmitted(): \Generator
    {
        $delay = 350;
        $start = microtime(true);

        Loop::delay($delay, function () {
            $this->emitter->emit(1);
            $this->emitter->complete();
        });

        $iterator = $this->emitter->iterate();
        while (yield $iterator->advance()) {
            $this->assertSame(1, $iterator->getCurrent());
        }

        $this->assertGreaterThan($delay, (microtime(true) - $start) * 1000);
    }

    public function testBackPressure(): \Generator
    {
        $delay = 350;
        Loop::defer(function () use ($delay) {
            yield new Delayed($delay);
            $iterator = $this->emitter->iterate();
            while (yield $iterator->advance()) {
                // Just consume messages. The values has no sense so
                // they are ignored.
                $iterator->getCurrent();
            }
        });

        $start = microtime(true);
        yield $this->emitter->emit(1);
        yield $this->emitter->emit(1);
        $this->emitter->complete();
        $end = microtime(true);

        // Producer waited while the first message was consumed.
        $this->assertGreaterThan($delay, ($end - $start) * 1000);
    }

    public function testDoubleComplete(): void
    {
        $this->expectException(\Error::class);

        $this->emitter->complete();
        $this->emitter->complete();
    }

    public function testGetCurrentBeforeAdvance(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Promise returned from advance() must resolve before calling this method');

        $this->emitter->iterate()->getCurrent();
    }

    public function testDoubleAdvance(): void
    {
        $this->expectException(\Error::class);

        $iterator = $this->emitter->iterate();

        $iterator->advance();
        $iterator->advance();
    }

    public function testAdvanceFailWithException(): \Generator
    {
        Loop::defer(function () {
            $this->emitter->fail(new \RuntimeException('foo'));
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('foo');

        yield $this->emitter->iterate()->advance();
    }

    public function testGetCurrentFailsOnCompletedIterator(): void
    {
        $this->emitter->complete();

        $this->expectException(\Error::class);

        $this->emitter->iterate()->getCurrent();
    }
}
