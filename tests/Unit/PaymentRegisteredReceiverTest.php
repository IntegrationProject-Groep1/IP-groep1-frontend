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
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('invalid xml');
    }

    public function test_throws_exception_when_user_id_is_missing(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildXml(['identity_uuid' => 'invalid-uuid']);

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_exception_when_status_is_missing(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildXml(['status' => 'invalid-status']);

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_valid_xml_is_processed_correctly(): void
    {
        $xml = $this->buildXml(['identity_uuid' => '550e8400-e29b-41d4-a716-446655440001', 'status' => 'paid']);

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }

    private function buildXml(array $data = []): string
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440001';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>' . $uuid . '</message_id>'
            . '<timestamp>2026-05-07T00:00:00Z</timestamp>'
            . '<source>frontend</source>'
            . '<type>payment_registered</type>'
            . '<version>2.0</version>'
            . '</header><body>'
            . (isset($data['identity_uuid']) ? '<identity_uuid>' . $data['identity_uuid'] . '</identity_uuid>' : '')
            . '<invoice><amount_paid currency="eur">10.00</amount_paid>'
            . '<status>' . ($data['status'] ?? 'paid') . '</status></invoice>'
            . '<payment_context>registration</payment_context>'
            . '</body></message>';
        return $xml;
    }

}