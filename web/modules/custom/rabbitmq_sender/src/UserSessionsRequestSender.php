<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Sends user_sessions_request messages to Planning to fetch a user's enrolled sessions.
 *
 * Frontend publishes on:
 *   Exchange:    planning.exchange      (topic, durable)
 *   Routing key: frontend.to.planning.user_sessions_request
 *
 * Planning responds via:
 *   Exchange:    planning.exchange
 *   Routing key: planning.to.frontend.user_sessions_response
 *   Queue:       frontend.planning.user_sessions_response  (handled by UserSessionsResponseReceiver)
 *
 * Required body field: identity_uuid — the master_uuid of the visitor.
 * The correlation_id in the header is returned in the response header so the
 * caller can match the response to this specific request.
 *
 * @return string $correlationId so the caller can match the async response.
 */
class UserSessionsRequestSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private const EXCHANGE      = 'planning.exchange';
    private const ROUTING_KEY   = 'frontend.to.planning.user_sessions_request';
    private const EXCHANGE_TYPE = 'topic';
    private const SOURCE        = 'frontend';
    private const TYPE          = 'user_sessions_request';
    private const VERSION       = '2.0';
    private const XSD_PATH      = __DIR__ . '/../../../../../xsd/user_sessions_request.xsd';

    private ?RabbitMQClient $client;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Sends a user_sessions_request for the given identity UUID.
     *
     * @param string $identityUuid The master_uuid of the user.
     *
     * @return string The correlation_id used in this request, for matching the response.
     */
    public function send(string $identityUuid): string
    {
        $correlationId = $this->generateUuidV4();
        $xml = $this->buildXml($identityUuid, $correlationId);
        $this->validateXml($xml, self::XSD_PATH);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareExchange(self::EXCHANGE, self::EXCHANGE_TYPE);
            $this->resolveClient()->publishToExchange(self::EXCHANGE, self::ROUTING_KEY, $xml);
            $this->logOutboundSuccess(self::TYPE, self::ROUTING_KEY, $xml);
        });

        return $correlationId;
    }

    public function buildXml(string $identityUuid, string $correlationId): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id',     $messageId));
        $header->appendChild($dom->createElement('timestamp',      $timestamp));
        $header->appendChild($dom->createElement('source',         self::SOURCE));
        $header->appendChild($dom->createElement('type',           self::TYPE));
        $header->appendChild($dom->createElement('version',        self::VERSION));
        $header->appendChild($dom->createElement('correlation_id', $correlationId));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('identity_uuid', htmlspecialchars($identityUuid, ENT_XML1, 'UTF-8')));
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
            (int)    (getenv('RABBITMQ_PORT') ?: 5672),
            (string) (getenv('RABBITMQ_USER') ?: 'guest'),
            (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
            (string) (getenv('RABBITMQ_VHOST') ?: '/')
        );

        return $this->client;
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function logOutboundSuccess(string $type, string $routingKey, string $xml): void
    {
        \Drupal::logger('rabbitmq_sender')->info(
            'Sent @type to @key',
            ['@type' => $type, '@key' => $routingKey]
        );
    }
}
