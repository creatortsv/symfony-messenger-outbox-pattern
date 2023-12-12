<?php

declare(strict_types=1);

namespace Creatortsv\Messenger\Outbox\Tests\Unit\Middleware;

use Creatortsv\Messenger\Outbox\Middleware\SwitchToOutboxMiddleware;
use Creatortsv\Messenger\Outbox\Stamp\MessageReceivedFromOutboxStamp;
use Creatortsv\Messenger\Outbox\Stamp\MessageSentToOutboxStamp;
use PHPUnit\Framework\MockObject\Builder\InvocationMocker;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvokedCount;
use Psr\Container\ContainerInterface;
use stdClass;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Test\Middleware\MiddlewareTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

class SwitchToOutboxMiddlewareTest extends MiddlewareTestCase
{
    private ?ContainerInterface $sendersLocator = null;

    /**
     * @throws Exception
     */
    public function testHandleSwitchedToOutbox(): void
    {
        $outbox = new InMemoryTransport();
        $broker = new InMemoryTransport();
        $sender = new SendersLocator([], $this->mockSendersLocator());

        $this->mockSendersLocatorHas()->with('outbox')->willReturn(true);
        $this->mockSendersLocatorGet()->with('outbox')->willReturn($outbox);

        $messageBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox'),
            new SendMessageMiddleware($sender),
        ]);

        $envelope = $messageBus->dispatch(new stdClass(), [new TransportNamesStamp('broker')]);

        $this->assertCount(0, $broker->getSent());
        $this->assertCount(1, $outbox->getSent());
        $this->assertCount(1, $envelope->all(SentStamp::class));
        $this->assertCount(1, $envelope->all(TransportNamesStamp::class));
        $this->assertCount(1, $envelope->all(MessageSentToOutboxStamp::class));

        $this->assertSame('outbox', $envelope->last(SentStamp::class)?->getSenderAlias());
        $this->assertSame(['outbox'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
        $this->assertSame(['broker'], $envelope->last(MessageSentToOutboxStamp::class)?->originalTransportNames);
    }

    /**
     * @throws Exception
     */
    public function testHandleSwitchedToOutboxWithoutOriginalTransportNames(): void
    {
        $outbox = new InMemoryTransport();
        $broker = new InMemoryTransport();
        $sender = new SendersLocator([], $this->mockSendersLocator());

        $this->mockSendersLocatorHas()->with('outbox')->willReturn(true);
        $this->mockSendersLocatorGet()->with('outbox')->willReturn($outbox);

        $messageBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox'),
            new SendMessageMiddleware($sender),
        ]);

        $envelope = $messageBus->dispatch(new stdClass());

        $this->assertCount(0, $broker->getSent());
        $this->assertCount(1, $outbox->getSent());
        $this->assertCount(1, $envelope->all(SentStamp::class));
        $this->assertCount(1, $envelope->all(TransportNamesStamp::class));
        $this->assertCount(1, $envelope->all(MessageSentToOutboxStamp::class));

        $this->assertSame('outbox', $envelope->last(SentStamp::class)?->getSenderAlias());
        $this->assertSame(['outbox'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
        $this->assertSame([], $envelope->last(MessageSentToOutboxStamp::class)?->originalTransportNames);
    }

    /**
     * @throws Exception
     */
    public function testHandlePublish(): void
    {
        $outbox = new InMemoryTransport();
        $broker = new InMemoryTransport();
        $sender = new SendersLocator([], $this->mockSendersLocator());

        $this->mockSendersLocatorHas()->with('broker')->willReturn(true);
        $this->mockSendersLocatorGet()->with('broker')->willReturn($broker);

        $messageBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox'),
            new SendMessageMiddleware($sender),
        ]);

        $envelope = $messageBus->dispatch(new stdClass(), [
            new ReceivedStamp('outbox'),
            new MessageSentToOutboxStamp(['broker']),
        ]);

        $this->assertCount(0, $outbox->getSent());
        $this->assertCount(1, $broker->getSent());
        $this->assertCount(1, $envelope->all(SentStamp::class));
        $this->assertCount(1, $envelope->all(TransportNamesStamp::class));
        $this->assertCount(1, $envelope->all(MessageReceivedFromOutboxStamp::class));
        $this->assertCount(1, $envelope->all(HandledStamp::class));

        $this->assertSame('none', $envelope->last(HandledStamp::class)?->getHandlerName());
        $this->assertSame('broker', $envelope->last(SentStamp::class)?->getSenderAlias());
        $this->assertSame('outbox', $envelope->last(MessageReceivedFromOutboxStamp::class)?->outboxTransportName);
        $this->assertSame(['broker'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
        $this->assertNull($envelope->last(HandledStamp::class)?->getResult());

        $receivedStamp = current($broker->getSent())->last(MessageReceivedFromOutboxStamp::class);

        $this->assertNull($receivedStamp, 'Published envelope has no specific received stamp');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->sendersLocator = null;
    }

    /**
     * @throws Exception
     */
    private function mockSendersLocator(): ContainerInterface|MockObject
    {
        return $this->sendersLocator ??= $this->createMock(ContainerInterface::class);
    }

    /**
     * @throws Exception
     */
    private function mockSendersLocatorHas(InvokedCount $count = new InvokedCount(1)): InvocationMocker
    {
        return $this->mockSendersLocator()->expects($count)->method('has');
    }

    /**
     * @throws Exception
     */
    private function mockSendersLocatorGet(InvokedCount $count = new InvokedCount(1)): InvocationMocker
    {
        return $this->mockSendersLocator()->expects($count)->method('get');
    }
}
