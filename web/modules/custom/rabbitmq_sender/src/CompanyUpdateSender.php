<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes company_update events to CRM (contract §5.10).
 * Used when company details change or members are added/removed.
 * For member-only removal, company_member_removed (§5.8) can also be used.
 *
 * Queue: crm.incoming (direct, durable)
 */
class CompanyUpdateSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'company_update';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/company_update.xsd';

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
     *   members?: list<array{master_uuid: string, action: 'add'|'remove'}>
     * } $data
     */
    public function send(array $data): void
    {
        foreach (['master_uuid', 'name', 'email', 'vat_number'] as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("$field is required");
            }
        }
        $this->assertValidUuid((string) $data['master_uuid'], 'master_uuid');

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
        $masterUuid = (string) $data['master_uuid'];
        $messageId  = $this->generateUuidV4();
        $timestamp  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

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
        $company->appendChild($dom->createElement('name',       htmlspecialchars((string) $data['name'],       ENT_XML1, 'UTF-8')));
        $company->appendChild($dom->createElement('email',      htmlspecialchars((string) $data['email'],      ENT_XML1, 'UTF-8')));
        $company->appendChild($dom->createElement('vat_number', htmlspecialchars((string) $data['vat_number'], ENT_XML1, 'UTF-8')));

        if (!empty($data['members'])) {
            $membersEl = $dom->createElement('members');
            foreach ($data['members'] as $member) {
                $memberEl = $dom->createElement('member');
                $memberEl->appendChild($dom->createElement('master_uuid', htmlspecialchars((string) $member['master_uuid'], ENT_XML1, 'UTF-8')));
                $memberEl->appendChild($dom->createElement('action',      htmlspecialchars((string) $member['action'],      ENT_XML1, 'UTF-8')));
                $membersEl->appendChild($memberEl);
            }
            $company->appendChild($membersEl);
        }

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
