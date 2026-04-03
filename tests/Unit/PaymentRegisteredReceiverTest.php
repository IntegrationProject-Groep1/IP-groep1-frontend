<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\PaymentRegisteredReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for payment registered receiver XML validation.
 */
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

    public function test_throws_exception_when_payment_context_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<invoice><id>inv-001</id><status>paid</status><amount_paid>99.00</amount_paid><due_date>2026-05-01</due_date></invoice>';
        $xml .= '</body></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_exception_when_invoice_status_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<payment_context>registration</payment_context>';
        $xml .= '<invoice><id>inv-001</id><amount_paid>99.00</amount_paid><due_date>2026-05-01</due_date></invoice>';
        $xml .= '</body></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_valid_xml_is_processed_correctly(): void
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<payment_context>registration</payment_context>';
        $xml .= '<invoice><id>inv-001</id><status>paid</status><amount_paid>99.00</amount_paid><due_date>2026-05-01</due_date></invoice>';
        $xml .= '</body></message>';
        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}
