<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Sends calendar invite messages to Planning via the calendar.exchange topic exchange.
 */
class CalendarInviteSender
{
    use RetryTrait;

    private const EXCHANGE      = 'calendar.exchange';
    private const ROUTING_KEY   = 'frontend.to.planning.calendar.invite';
    private const EXCHANGE_TYPE = 'topic';
    private const SOURCE        = 'frontend';
    private const TYPE          = 'calendar_invite';
    private const VERSION       = '2.0';

    private ?RabbitMQClient $client;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('title is required');
        }
        if (empty($data['start_datetime'])) {
            throw new \InvalidArgumentException('start_datetime is required');
        }
        if (empty($data['end_datetime'])) {
            throw new \InvalidArgumentException('end_datetime is required');
        }
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['attendee_email'])) {
            throw new \InvalidArgumentException('attendee_email is required');
        }

        // ✅ FIX: safe logging
        $this->log('info', 'Sending calendar invite', [
            'session_id' => $data['session_id'],
        ]);

        $xml = $this->buildXml($data);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareExchange(self::EXCHANGE, self::EXCHANGE_TYPE);
            $this->resolveClient()->publishToExchange(self::EXCHANGE, self::ROUTING_KEY, $xml);
        });
    }

    public function buildXml(array $data): string
    {
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('title is required');
        }
        if (empty($data['start_datetime'])) {
            throw new \InvalidArgumentException('start_datetime is required');
        }
        if (empty($data['end_datetime'])) {
            throw new \InvalidArgumentException('end_datetime is required');
        }
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['attendee_email'])) {
            throw new \InvalidArgumentException('attendee_email is required');
        }

        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('timestamp', $timestamp));
        $header->appendChild($dom->createElement('source', self::SOURCE));
        $header->appendChild($dom->createElement('type', self::TYPE));
        $header->appendChild($dom->createElement('version', self::VERSION));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('user_id', htmlspecialchars((string) $data['user_id'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('session_id', htmlspecialchars((string) $data['session_id'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('title', htmlspecialchars((string) $data['title'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('start_datetime', htmlspecialchars((string) $data['start_datetime'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('end_datetime', htmlspecialchars((string) $data['end_datetime'], ENT_XML1, 'UTF-8')));

        if (array_key_exists('location', $data)) {
            $body->appendChild($dom->createElement('location', htmlspecialchars((string) $data['location'], ENT_XML1, 'UTF-8')));
        }

        $body->appendChild($dom->createElement('attendee_email', htmlspecialchars((string) $data['attendee_email'], ENT_XML1, 'UTF-8')));
        $message->appendChild($body);

        return $dom->saveXML() ?: '';
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new RabbitMQClient(
            (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker'),
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
            (string) (getenv('RABBITMQ_USER') ?: 'guest'),
            (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
            (string) (getenv('RABBITMQ_VHOST') ?: '/')
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

    /**
     * ✅ Safe logger (werkt in Drupal + PHPUnit)
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (class_exists('\Drupal')) {
            \Drupal::logger('rabbitmq_sender')->{$level}($message, $context);
        }
    }
}