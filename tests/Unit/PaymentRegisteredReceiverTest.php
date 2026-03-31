<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\PaymentRegisteredReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

class PaymentRegisteredReceiverTest extends TestCase
{
    private PaymentRegisteredReceiver $receiver;

    protected function setUp(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $this->receiver = new PaymentRegisteredReceiver($stubClient);
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
        $xml .= '<message><body>';
        $xml .= '<status>paid</status>';
        $xml .= '</body></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_exception_when_status_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<user_id>uuid-v4-hier</user_id>';
        $xml .= '</body></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_valid_xml_is_processed_correctly(): void
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<user_id>uuid-v4-hier</user_id>';
        $xml .= '<status>paid</status>';
        $xml .= '</body></message>';
        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}