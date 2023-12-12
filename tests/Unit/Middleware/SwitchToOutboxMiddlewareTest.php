<?php

declare(strict_types=1);

namespace Creatortsv\Messenger\Outbox\Tests\Unit\Middleware;

use Creatortsv\Messenger\Outbox\Middleware\SwitchToOutboxMiddleware;
use Creatortsv\Messenger\Outbox\Stamp\MessageReceivedFromOutboxStamp;
use Creatortsv\Messenger\Outbox\Stamp\MessageSentToOutboxStamp;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\Builder\InvocationMocker;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvokedCount;
use Psr\Container\ContainerInterface;
use stdClass;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
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
    public function testSentToOutboxTransport(): void
    {
        $outbox = new InMemoryTransport();
        $sender = new SendersLocator([stdClass::class => ['broker']], $this->mockSendersLocator());

        $this->mockSendersLocatorHas()->with('outbox')->willReturn(true);
        $this->mockSendersLocatorGet()->with('outbox')->willReturn($outbox);

        $eventBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox'),
            new SendMessageMiddleware($sender, allowNoSenders: false),
        ]);

        $envelope = $eventBus->dispatch(new stdClass());

        $this->assertCount(1, $outbox->getSent());
        $this->assertCount(1, $envelope->all(SentStamp::class));
        $this->assertCount(1, $envelope->all(TransportNamesStamp::class));
        $this->assertCount(1, $envelope->all(MessageSentToOutboxStamp::class));

        $transportName = $envelope->last(SentStamp::class)?->getSenderAlias();

        $this->assertSame([$transportName], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
    }

    /**
     * @throws Exception
     */
    public function testSentToMultipleOutboxTransports(): void
    {
        $outbox = new InMemoryTransport();
        $stored = new InMemoryTransport();
        $sender = new SendersLocator([stdClass::class => ['broker']], $this->mockSendersLocator());
        $withFn = new Callback(
            static fn (string $name): bool
                => $name === 'outbox'
                || $name === 'stored',
        );

        $this->mockSendersLocatorHas(2)->with($withFn)->willReturn(true);
        $this->mockSendersLocatorGet(2)->with($withFn)->willReturn($outbox, $stored);

        $eventBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox', 'stored'),
            new SendMessageMiddleware($sender, allowNoSenders: false),
        ]);

        $envelope = $eventBus->dispatch(new stdClass());

        $this->assertCount(1, $outbox->getSent());
        $this->assertCount(1, $stored->getSent());
        $this->assertCount(2, $envelope->all(SentStamp::class));
        $this->assertCount(1, $envelope->all(TransportNamesStamp::class));
        $this->assertCount(1, $envelope->all(MessageSentToOutboxStamp::class));
        $this->assertSame(['outbox', 'stored'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
    }

    /**
     * @throws Exception
     */
    public function testSentToOutboxTransportWithChangedOriginalTransport(): void
    {
        $outbox = new InMemoryTransport();
        $sender = new SendersLocator([stdClass::class => ['broker']], $this->mockSendersLocator());

        $this->mockSendersLocatorHas()->with('outbox')->willReturn(true);
        $this->mockSendersLocatorGet()->with('outbox')->willReturn($outbox);

        $eventBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox'),
            new SendMessageMiddleware($sender, allowNoSenders: false),
        ]);

        $envelope = $eventBus->dispatch(new stdClass(), [new TransportNamesStamp('some')]);

        $this->assertSame(['some'], $envelope->last(MessageSentToOutboxStamp::class)?->originalTransportNames);
    }

    /**
     * @throws Exception
     */
    public function testHandlePublish(): void
    {
        $broker = new InMemoryTransport();
        $sender = new SendersLocator([stdClass::class => ['broker']], $this->mockSendersLocator());
        $handle = new HandlersLocator([]);

        $this->mockSendersLocatorHas()->with('broker')->willReturn(true);
        $this->mockSendersLocatorGet()->with('broker')->willReturn($broker);

        $messageBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox'),
            new SendMessageMiddleware($sender),
            new HandleMessageMiddleware($handle, allowNoHandlers: true)
        ]);

        $envelope = $messageBus->dispatch(new stdClass(), [new ReceivedStamp('outbox')]);

        $this->assertCount(1, $broker->getSent());
        $this->assertCount(1, $envelope->all(SentStamp::class));
        $this->assertCount(0, $envelope->all(ReceivedStamp::class));
        $this->assertCount(0, $envelope->all(TransportNamesStamp::class));
        $this->assertCount(0, $envelope->all(MessageSentToOutboxStamp::class));
        $this->assertCount(1, $envelope->all(MessageReceivedFromOutboxStamp::class));
        $this->assertCount(0, current($broker->getSent())->all(MessageReceivedFromOutboxStamp::class));
        $this->assertCount(0, current($broker->getSent())->all(HandledStamp::class));
        $this->assertSame('broker', $envelope->last(SentStamp::class)?->getSenderAlias());
    }

    /**
     * @throws Exception
     */
    public function testHandlePublishFromAdvanceOutboxTransport(): void
    {
        $broker = new InMemoryTransport();
        $sender = new SendersLocator([stdClass::class => ['broker']], $this->mockSendersLocator());
        $handle = new HandlersLocator([]);

        $this->mockSendersLocatorHas()->with('broker')->willReturn(true);
        $this->mockSendersLocatorGet()->with('broker')->willReturn($broker);

        $messageBus = new MessageBus([
            new SwitchToOutboxMiddleware('outbox', 'stored'),
            new SendMessageMiddleware($sender),
            new HandleMessageMiddleware($handle, allowNoHandlers: true)
        ]);

        $messageBus->dispatch(new stdClass(), [new ReceivedStamp('stored')]);

        $this->assertCount(1, $broker->getSent());
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
    private function mockSendersLocatorHas(int $count = 1): InvocationMocker
    {
        return $this->mockSendersLocator()->expects(new InvokedCount($count))->method('has');
    }

    /**
     * @throws Exception
     */
    private function mockSendersLocatorGet(int $count = 1): InvocationMocker
    {
        return $this->mockSendersLocator()->expects(new InvokedCount($count))->method('get');
    }
}
