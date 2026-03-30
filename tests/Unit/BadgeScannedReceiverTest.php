<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\BadgeScannedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

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
        $xml .= '<message><payload>';
        $xml .= '<badge_id>nfc-badge-abc123</badge_id>';
        $xml .= '</payload></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_exception_when_badge_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><payload>';
        $xml .= '<user_id>uuid-v4-hier</user_id>';
        $xml .= '</payload></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_valid_xml_is_processed_correctly(): void
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><payload>';
        $xml .= '<user_id>uuid-v4-hier</user_id>';
        $xml .= '<badge_id>nfc-badge-abc123</badge_id>';
        $xml .= '</payload></message>';
        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}