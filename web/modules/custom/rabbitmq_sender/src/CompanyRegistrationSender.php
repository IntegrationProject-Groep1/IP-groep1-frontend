<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes company_registration events to RabbitMQ (v2.0 contract §5.9).
 *
 * Sent when a company registration is approved by an admin.
 * CRM creates/updates the company profile in Salesforce.
 */
class CompanyRegistrationSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'company_registration';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/company_registration.xsd';

    /** Default Belgian VAT rate (%) — used when no vat_rate is supplied. */
    private const DEFAULT_VAT_RATE = 21;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * @param array{
     *   master_uuid: string,
     *   name: string,
     *   email: string,
     *   vat_number: string,
     *   vat_rate?: int,
     * } $data
     */
    public function send(array $data): void
    {
        if (empty($data['master_uuid'])) {
            throw new \InvalidArgumentException('master_uuid is required for company_registration');
        }
        $this->assertValidUuid((string) $data['master_uuid'], 'master_uuid');

        if (empty($data['name'])) {
            throw new \InvalidArgumentException('name is required for company_registration');
        }

        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required for company_registration');
        }

        if (empty($data['vat_number'])) {
            throw new \InvalidArgumentException('vat_number is required for company_registration');
        }

        $xml = $this->buildXml($data);
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

    public function buildXml(array $data): string
    {
        $messageId  = $this->generateUuidV4();
        $masterUuid = htmlspecialchars((string) $data['master_uuid'], ENT_XML1, 'UTF-8');
        $timestamp  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        // Normalize vat_number to "BE" + 10 digits (strip dots, dashes, spaces).
        $vatNormalized = $this->normalizeVatNumber((string) $data['vat_number']);

        $vatRate = isset($data['vat_rate']) ? (int) $data['vat_rate'] : self::DEFAULT_VAT_RATE;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        // Header — §5.9: master_uuid is required in the header.
        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('timestamp', $timestamp));
        $header->appendChild($dom->createElement('source', self::SOURCE));
        $header->appendChild($dom->createElement('type', self::TYPE));
        $header->appendChild($dom->createElement('version', self::VERSION));
        $header->appendChild($dom->createElement('master_uuid', $masterUuid));
        $message->appendChild($header);

        // Body
        $company = $dom->createElement('company');
        $company->appendChild($dom->createElement('master_uuid', $masterUuid));
        $company->appendChild($dom->createElement('name', htmlspecialchars((string) $data['name'], ENT_XML1, 'UTF-8')));
        $company->appendChild($dom->createElement('email', htmlspecialchars((string) $data['email'], ENT_XML1, 'UTF-8')));
        $company->appendChild($dom->createElement('vat_number', $vatNormalized));
        $company->appendChild($dom->createElement('vat_rate', (string) $vatRate));

        $body = $dom->createElement('body');
        $body->appendChild($company);
        $message->appendChild($body);

        return $dom->saveXML() ?: '';
    }

    /**
     * Normalizes a Belgian VAT number to the XSD-required format: [A-Z]{2}[0-9]{10}.
     * Accepts: BE0123.456.789, 0123456789, BE0123456789, etc.
     */
    private function normalizeVatNumber(string $raw): string
    {
        $cleaned = strtoupper(trim($raw));
        // Strip BE prefix if present.
        if (str_starts_with($cleaned, 'BE')) {
            $cleaned = substr($cleaned, 2);
        }
        // Strip dots, dashes, spaces.
        $cleaned = preg_replace('/[\.\-\s]/', '', $cleaned);

        return 'BE' . $cleaned;
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
}
