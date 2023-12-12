<?php

declare(strict_types=1);

namespace Creatortsv\Messenger\Outbox\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Indicates that the envelope was sent to the outbox transport
 * and this envelope must not be handled or sent to the message broker
 *
 * @author Vladimir Tsaplin <creatortsv@gmail.com>
 * @internal
 */
final readonly class MessageSentToOutboxStamp implements StampInterface
{
    /**
     * @param array<array-key, string> $originalTransportNames
     */
    public function __construct(public array $originalTransportNames = [])
    {
    }
}
