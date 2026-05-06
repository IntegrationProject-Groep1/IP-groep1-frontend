<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes event_ended messages to RabbitMQ when an admin manually stops a session.
 */
class EventEndedSender
{
    use RetryTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'event.ended';
    private const TYPE       = 'event_ended';
    private const VERSION    = '2.0';
    private const SOURCE     = 'frontend';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }

        $xml = $this->buildXml($data);

        $this->sendWithRetry(function () use ($xml): void {
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type'  => 'application/xml',
            ]);
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
        });
    }

    public function buildXml(array $data): string
    {
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }

        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message>';
        $xml .= '<header>';
        $xml .= "<message_id>{$messageId}</message_id>";
        $xml .= "<timestamp>{$timestamp}</timestamp>";
        $xml .= '<source>' . self::SOURCE . '</source>';
        $xml .= '<type>' . self::TYPE . '</type>';
        $xml .= '<version>' . self::VERSION . '</version>';
        $xml .= '</header>';
        $xml .= '<body>';
        $xml .= '<session_id>' . htmlspecialchars((string) $data['session_id'], ENT_XML1, 'UTF-8') . '</session_id>';
        $xml .= "<ended_at>{$timestamp}</ended_at>";
        $xml .= '</body>';
        $xml .= '</message>';

        return $xml;
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new RabbitMQClient(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker',
            (int) (getenv('RABBITMQ_PORT') ?: '5672'),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASS') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        return $this->client;
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
