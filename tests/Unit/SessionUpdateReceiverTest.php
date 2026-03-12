<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SessionUpdateReceiverTest extends TestCase
{
    public function test_throws_exception_when_xml_is_invalid(): void
    {
        $mockClient = $this->createMock(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($mockClient);

        $this->expectException(\InvalidArgumentException::class);
        $receiver->processMessageFromXml('invalid xml');
    }

    public function test_throws_exception_when_session_id_is_missing(): void
    {
        $mockClient = $this->createMock(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($mockClient);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><payload>';
        $xml .= '<new_time>14:00</new_time>';
        $xml .= '<location>Zaal A</location>';
        $xml .= '</payload></message>';

        $this->expectException(\InvalidArgumentException::class);
        $receiver->processMessageFromXml($xml);
    }

    public function test_valid_xml_is_processed_correctly(): void
    {
        $mockClient = $this->createMock(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($mockClient);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><payload>';
        $xml .= '<session_id>session-uuid-001</session_id>';
        $xml .= '<new_time>14:00</new_time>';
        $xml .= '<location>Zaal A</location>';
        $xml .= '</payload></message>';

        $result = $receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}