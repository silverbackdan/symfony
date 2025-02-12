<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Tests;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class MailerTest extends TestCase
{
    public function testSendingRawMessages()
    {
        $this->expectException(LogicException::class);

        $transport = new Mailer($this->createMock(TransportInterface::class), $this->createMock(MessageBusInterface::class), $this->createMock(EventDispatcherInterface::class));
        $transport->send(new RawMessage('Some raw email message'));
    }

    private static function createDumyMailerBus()
    {
        return new class() implements MessageBusInterface {
            public $messages = [];
            public $envelopes = [];
            public $stamps = [];

            public function dispatch($message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;
                $this->stamps = $stamps;
                $envelope = new Envelope($message, $stamps);
                $this->envelopes[] = $envelope;
                return $envelope;
            }
        };
    }

    private function createMockDispatcher(StampInterface $stamp)
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(static function (MessageEvent $event) use ($stamp) {
                $event->addStamp($stamp);

                return 'Time for Symfony Mailer!' === $event->getMessage()->getSubject();
            }))
            ->willReturnArgument(0)
        ;
        return $dispatcher;
    }

    public function testSendMessageToBus()
    {
        $bus = self::createDumyMailerBus();
        $stamp = $this->createMock(StampInterface::class);
        $dispatcher = $this->createMockDispatcher($stamp);

        $mailer = new Mailer(new NullTransport($dispatcher), $bus, $dispatcher);

        $email = (new Email())
            ->from('hello@example.com')
            ->to('you@example.com')
            ->subject('Time for Symfony Mailer!')
            ->text('Sending emails is fun again!')
            ->html('<p>See Twig integration for better HTML integration!</p>');

        $mailer->send($email);

        self::assertCount(1, $bus->messages);
        self::assertSame($email, $bus->messages[0]->getMessage());
        self::assertCount(1, $bus->stamps);
        self::assertSame([$stamp], $bus->stamps);
    }

    public function testSendTemplatedEmailWithUnserializableContextToBus()
    {
        $bus = self::createDumyMailerBus();
        $stamp = $this->createMock(StampInterface::class);
        $dispatcher = $this->createMockDispatcher($stamp);

        $mailer = new Mailer(new NullTransport($dispatcher), $bus, $dispatcher);

        $email = (new TemplatedEmail())
            ->from('hello@example.com')
            ->to('you@example.com')
            ->subject('Time for Symfony Mailer!')
            ->text('Sending emails is fun again!')
            ->context([
                'unserializable' => new File('/anything.jpg', false)
            ]);

        $mailer->send($email);

        $serializer = new PhpSerializer();

        self::assertCount(1, $bus->messages);
        self::assertCount(1, $bus->envelopes);

        $templatedEmail = $bus->messages[0]->getMessage();
        self::assertInstanceOf(TemplatedEmail::class, $templatedEmail);
        self::assertSame($email, $templatedEmail);
        self::assertArrayHasKey('body', $serializer->encode($bus->envelopes[0]));
        self::assertSame([], $templatedEmail->getContext());
    }
}
