<?php

declare(strict_types=1);

namespace Creatortsv\Messenger\Outbox\Middleware;

use Creatortsv\Messenger\Outbox\Stamp\MessageReceivedFromOutboxStamp;
use Creatortsv\Messenger\Outbox\Stamp\MessageSentToOutboxStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * This middleware is used to navigate the envelope
 * from the source to message brokers through the outbox transport
 *
 * @note Logic of this middleware is not working without default messenger middlewares
 *
 * @author Vladimir Tsaplin <creatortsv@gmail.com>
 */
readonly class SwitchToOutboxMiddleware implements MiddlewareInterface
{
    public function __construct(private string $outboxTransportName)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return match (true) {
            $this->isPublishedFirstTime($envelope) => $this->switchToOutboxTransport($envelope, $stack),
            $this->isReceivedFromOutbox($envelope) => $this->publish($envelope, $stack),
            default => $stack->next()->handle($envelope, $stack),
        };
    }

    private function isPublishedFirstTime(Envelope $envelope): bool
    {
        return empty($envelope->all(ReceivedStamp::class));
    }

    private function isReceivedFromOutbox(Envelope $envelope): bool
    {
        $outboxTransportName = $envelope->last(ReceivedStamp::class)?->getTransportName();

        return $outboxTransportName === $this->outboxTransportName;
    }

    private function switchToOutboxTransport(Envelope $envelope, StackInterface $stack): Envelope
    {
        $originalTransportNames = array_merge([], ...$this->getTransportNames($envelope));

        $envelope = $envelope->withoutAll(TransportNamesStamp::class);
        $envelope = $envelope->with(
            new TransportNamesStamp($this->outboxTransportName),
            new MessageSentToOutboxStamp($originalTransportNames),
        );

        return $stack->next()->handle($envelope, $stack);
    }

    private function publish(Envelope $envelope, StackInterface $stack): Envelope
    {
        $originalTransportNames = $envelope->last(MessageSentToOutboxStamp::class)?->originalTransportNames;

        $envelope = $envelope
        /** Indicates that {@see SendMessageMiddleware} can try to send this envelope */
            ->withoutAll(ReceivedStamp::class)
            ->withoutAll(MessageSentToOutboxStamp::class)
            ->withoutAll(TransportNamesStamp::class);

        if ($originalTransportNames) {
            $envelope = $envelope->with(new TransportNamesStamp($originalTransportNames));
        }

        return $stack->next()->handle($envelope, $stack)->with(
        /** Indicates that {@see HandleMessageMiddleware} do not need to handle the message */
            new HandledStamp(null, 'none'),
            new MessageReceivedFromOutboxStamp($this->outboxTransportName),
        );
    }

    /**
     * @return array<array-key, array<array-key, string>>
     */
    private function getTransportNames(Envelope $envelope): array
    {
        $map = static fn (TransportNamesStamp $stamp): array => $stamp->getTransportNames();

        return array_map($map, $envelope->all(TransportNamesStamp::class));
    }
}
