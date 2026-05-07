<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\BadgeScannedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use Tests\Unit\XmlTestBuilder;

/**
 * Unit tests for badge scanned receiver XML validation.
 */
class BadgeScannedReceiverTest extends TestCase
{
    private BadgeScannedReceiver $receiver;

    protected function setUp(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $this->receiver = new BadgeScannedReceiver($stubClient);
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

    public function test_throws_exception_when_badge_id_is_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['location' => 'entrance', 'scanned_at' => '2026-05-07T00:00:00Z']));
    }

    public function test_valid_xml_is_processed_correctly(): void
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