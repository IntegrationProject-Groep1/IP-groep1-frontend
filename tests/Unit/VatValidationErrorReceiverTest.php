<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\VatValidationErrorReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for VAT validation error receiver XML validation.
 */
class VatValidationErrorReceiverTest extends TestCase
{
    private VatValidationErrorReceiver $receiver;

    protected function setUp(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $this->receiver = new VatValidationErrorReceiver($stubClient);
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
        $xml .= '<vat_number>BE0123456789</vat_number>';
        $xml .= '</body></message>';

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_exception_when_vat_number_is_missing(): void
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
        $xml .= '<message>';
        $xml .= '<header>';
        $xml .= '<message_id>01890a5d-ac96-7ab2-80e2-4536629c90de</message_id>';
        $xml .= '<timestamp>2026-04-05T12:00:00Z</timestamp>';
        $xml .= '<source>crm</source>';
        $xml .= '<type>vat_validation_error</type>';
        $xml .= '<version>2.0</version>';
        $xml .= '</header>';
        $xml .= '<body>';
        $xml .= '<identity_uuid>01890a5d-ac96-7ab2-80e2-4536629c90de</identity_uuid>';
        $xml .= '<vat_number>BE0123456789</vat_number>';
        $xml .= '<error_message>Invalid VAT number</error_message>';
        $xml .= '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}