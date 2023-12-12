<?php

declare(strict_types=1);

namespace Creatortsv\Messenger\Outbox\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Indicates that the envelope was received from the outbox transport
 * and this envelope is ready to be sent to the message broker
 *
 * @author Vladimir Tsaplin <creatortsv@gmail.com>
 * @internal
 */
final readonly class MessageReceivedFromOutboxStamp implements NonSendableStampInterface
{
    public function __construct(public string $outboxTransportName)
    {
    }
}
