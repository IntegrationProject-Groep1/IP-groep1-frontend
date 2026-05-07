<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\BadgeScannedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

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

    public function test_throws_exception_when_xml_is_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->receiver->processMessageFromXml('invalid xml');
    }

    public function test_throws_exception_when_user_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><header><message_id>550e8400-e29b-41d4-a716-446655440001</message_id><timestamp>2026-05-07T00:00:00Z</timestamp><source>iot_gateway</source><type>badge_scanned</type><version>2.0</version></header><body>';
        $xml .= '<badge_id>nfc-badge-abc123</badge_id>';
        $xml .= '</body></message>';

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_exception_when_badge_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><header><message_id>550e8400-e29b-41d4-a716-446655440001</message_id><timestamp>2026-05-07T00:00:00Z</timestamp><source>iot_gateway</source><type>badge_scanned</type><version>2.0</version></header><body>';
        $xml .= '<location>entrance</location><scanned_at>2026-05-07T00:00:00Z</scanned_at>';
        $xml .= '</body></message>';

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_valid_xml_is_processed_correctly(): void
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header><message_id>550e8400-e29b-41d4-a716-446655440001</message_id><timestamp>2026-05-07T00:00:00Z</timestamp><source>iot_gateway</source><type>badge_scanned</type><version>2.0</version></header><body>';
        $xml .= '<badge_id>nfc-badge-abc123</badge_id><location>entrance</location><scanned_at>2026-05-07T00:00:00Z</scanned_at>';
        $xml .= '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}