<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes company_delete events to CRM (contract §5.11).
 * CRM performs a soft delete in Salesforce.
 *
 * Queue: crm.incoming (direct, durable)
 */
class CompanyDeleteSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'company_delete';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/company_delete.xsd';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(string $masterUuid): void
    {
        $this->assertValidUuid($masterUuid, 'master_uuid');

        $xml = $this->buildXml($masterUuid);
        $this->validateXml($xml, self::XSD_PATH);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type'  => 'application/xml',
            ]);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
            $this->logOutboundSuccess(self::TYPE, self::QUEUE_NAME, $xml);
        });
    }

    public function buildXml(string $masterUuid): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id',  $messageId));
        $header->appendChild($dom->createElement('timestamp',   $timestamp));
        $header->appendChild($dom->createElement('source',      self::SOURCE));
        $header->appendChild($dom->createElement('type',        self::TYPE));
        $header->appendChild($dom->createElement('version',     self::VERSION));
        $header->appendChild($dom->createElement('master_uuid', htmlspecialchars($masterUuid, ENT_XML1, 'UTF-8')));
        $message->appendChild($header);

        $body    = $dom->createElement('body');
        $company = $dom->createElement('company');
        $company->appendChild($dom->createElement('master_uuid', htmlspecialchars($masterUuid, ENT_XML1, 'UTF-8')));
        $body->appendChild($company);
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
