<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Tests\Unit\XmlTestBuilder;

class BadgeScannedReceiver
{
    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    private function buildXml(array $fields): string
    {
        return XmlTestBuilder::build('badge_scanned', ['source' => 'iot_gateway'], $fields);
    }

    public function test_throws_exception_when_xml_is_invalid(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('invalid xml');
    }

    public function test_throws_exception_when_location_and_scanned_at_are_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['badge_id' => 'nfc-badge-abc123']));
    }

    private function handleMessage(AMQPMessage $msg): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['location' => 'entrance', 'scanned_at' => '2026-05-07T00:00:00Z']));
    }

    private function parseXml(string $xmlString): \SimpleXMLElement
    {
        $xml = $this->buildXml([
            'badge_id'   => 'nfc-badge-abc123',
            'location'   => 'entrance',
            'scanned_at' => '2026-05-07T00:00:00Z'
        ]);

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}