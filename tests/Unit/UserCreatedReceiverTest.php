<?php
declare(strict_types=1);

use Drupal\rabbitmq_receiver\UserCreatedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserCreatedReceiver XML parsing.
 */
class UserCreatedReceiverTest extends TestCase
{
    private UserCreatedReceiver $receiver;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->receiver = new UserCreatedReceiver($mockClient);
    }

    // ── processMessageFromXml ────────────────────────────────────────────────

    public function test_returns_expected_data_from_valid_event(): void
    {
        $xml = <<<XML
<user_event>
  <event>UserCreated</event>
  <master_uuid>01890a5d-ac96-7ab2-80e2-4536629c90de</master_uuid>
  <email>user@example.com</email>
  <source_system>crm</source_system>
  <timestamp>2026-04-05T12:00:00+00:00</timestamp>
</user_event>
XML;

        $data = $this->receiver->processMessageFromXml($xml);

        $this->assertSame('UserCreated', $data['event']);
        $this->assertSame('01890a5d-ac96-7ab2-80e2-4536629c90de', $data['master_uuid']);
        $this->assertSame('user@example.com', $data['email']);
        $this->assertSame('crm', $data['source_system']);
        $this->assertSame('2026-04-05T12:00:00+00:00', $data['timestamp']);
    }

    public function test_normalizes_email_to_lowercase(): void
    {
        $xml = <<<XML
<user_event>
  <event>UserCreated</event>
  <master_uuid>01890a5d-ac96-7ab2-80e2-4536629c90de</master_uuid>
  <email>USER@EXAMPLE.COM</email>
  <source_system>frontend</source_system>
  <timestamp>2026-04-05T12:00:00+00:00</timestamp>
</user_event>
XML;

        $data = $this->receiver->processMessageFromXml($xml);

        $this->assertSame('user@example.com', $data['email']);
    }

    public function test_throws_on_invalid_xml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML received');

        $this->receiver->processMessageFromXml('not valid xml {{}}');
    }

    public function test_throws_on_wrong_event_type(): void
    {
        $xml = <<<XML
<user_event>
  <event>UserDeleted</event>
  <master_uuid>01890a5d-ac96-7ab2-80e2-4536629c90de</master_uuid>
  <email>user@example.com</email>
  <source_system>crm</source_system>
  <timestamp>2026-04-05T12:00:00+00:00</timestamp>
</user_event>
XML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected event type: UserDeleted');

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_master_uuid_is_missing(): void
    {
        $xml = <<<XML
<user_event>
  <event>UserCreated</event>
  <email>user@example.com</email>
  <source_system>crm</source_system>
  <timestamp>2026-04-05T12:00:00+00:00</timestamp>
</user_event>
XML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('master_uuid is required');

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_email_is_missing(): void
    {
        $xml = <<<XML
<user_event>
  <event>UserCreated</event>
  <master_uuid>01890a5d-ac96-7ab2-80e2-4536629c90de</master_uuid>
  <source_system>crm</source_system>
  <timestamp>2026-04-05T12:00:00+00:00</timestamp>
</user_event>
XML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('email is required');

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_master_uuid_is_empty_string(): void
    {
        $xml = <<<XML
<user_event>
  <event>UserCreated</event>
  <master_uuid>   </master_uuid>
  <email>user@example.com</email>
  <source_system>crm</source_system>
  <timestamp>2026-04-05T12:00:00+00:00</timestamp>
</user_event>
XML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('master_uuid is required');

        $this->receiver->processMessageFromXml($xml);
    }
}
