<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes wallet_topup_request events to CRM (contract §26.5).
 * Triggered after a successful online payment (Bancontact/Creditcard).
 *
 * Exchange: frontend.exchange
 * Routing key: frontend.to.crm.wallet_topup_request
 */
class WalletTopupRequestSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const EXCHANGE     = 'frontend.exchange';
    private const ROUTING_KEY  = 'frontend.to.crm.wallet_topup_request';
    private const EXCHANGE_TYPE = 'topic';
    private const SOURCE       = 'frontend';
    private const TYPE         = 'wallet_topup_request';
    private const VERSION      = '2.0';
    private const XSD_PATH     = __DIR__ . '/../../../../../xsd/wallet_topup_request.xsd';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(string $identityUuid, float $topupAmount, string $transactionId): void
    {
        $this->assertValidUuid($identityUuid, 'identity_uuid');
        if ($topupAmount <= 0) {
            throw new \InvalidArgumentException('topup_amount must be greater than 0');
        }
        if ($transactionId === '') {
            throw new \InvalidArgumentException('transaction_id is required');
        }

        $xml = $this->buildXml($identityUuid, $topupAmount, $transactionId);
        $this->validateXml($xml, self::XSD_PATH);

        $this->sendWithRetry(function () use ($xml): void {
            $channel = $this->resolveClient()->getChannel();
            $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type'  => 'application/xml',
            ]);
            $channel->basic_publish($msg, self::EXCHANGE, self::ROUTING_KEY);
            $this->logOutboundSuccess(self::TYPE, self::ROUTING_KEY, $xml);
        });
    }

    public function buildXml(string $identityUuid, float $topupAmount, string $transactionId): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('timestamp',  $timestamp));
        $header->appendChild($dom->createElement('source',     self::SOURCE));
        $header->appendChild($dom->createElement('type',       self::TYPE));
        $header->appendChild($dom->createElement('version',    self::VERSION));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('identity_uuid', htmlspecialchars($identityUuid, ENT_XML1, 'UTF-8')));

        $amountEl = $dom->createElement('topup_amount', number_format($topupAmount, 2, '.', ''));
        $amountEl->setAttribute('currency', 'eur');
        $body->appendChild($amountEl);

        $body->appendChild($dom->createElement('transaction_id', htmlspecialchars($transactionId, ENT_XML1, 'UTF-8')));
        $message->appendChild($body);

        return $dom->saveXML() ?: '';
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
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
