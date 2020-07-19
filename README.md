# AMP priority emitter

> In-memory implementation of async emitter with prioritized messages

## Why

Implementation of AMP Emitter is backed by a queue. It covers many cases but
sometimes a priority queue is needed.

For example, you're building a bot for a social network or a messenger. Assume
that the bot can react for users' commands and broadcast information to all its
subscribers. These two types of messages have different priorities. Commands'
responses must be sent as soon as possible to keep UX responsive but
broadcasting messages can wait for a while.

You can build the app around a message bus. There is some code that pushes
messages to the bus, and some code that pull them out and transfer to social
network API.

You cannot use Emitter shipped with AMP because in such case broadcasting
messages will block command ones because there is no way to set priority
with AMP Emitter.

This library adds and Emitter with API similar to AMP Emitter but with
priority support. 

## Installation

```
composer require justblackbird/amphp-priority-emitter
```

## Usage

```php
use Amp\Loop;
use JustBlackBird\AmpPriorityEmitter\Emitter;

Loop::run(static function() {
    $emitter = new Emitter();

    $emitter->emit('message one', 0);
    $emitter->emit('message two', 0);
    $emitter->emit('important message', 5);
    $emitter->complete();

    $iterator = $emitter->iterate();
    while(yield $iterator->advance()) {
        echo "- " . $iterator->getCurrent() . "\n";        
    }
});

// Will output:
// - important message
// - message one
// - message two
```

## License

[MIT](http://opensource.org/licenses/MIT) (c) Dmitry Simushev

The implementation is based on [AMP Emitter](https://amphp.org/amp/iterators/#emitter).
