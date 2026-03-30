<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\UserUnregisteredSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

class UserUnregisteredSenderTest extends TestCase
{
    private UserUnregisteredSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new UserUnregisteredSender($mockClient);
    }

    public function test_throws_exception_when_user_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'session_id' => 'session-uuid-001',
        ]);
    }

    public function test_throws_exception_when_session_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'user_id' => 'uuid-v4-hier',
        ]);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml([
            'user_id' => 'uuid-v4-hier',
            'session_id' => 'session-uuid-001',
        ]);

        $this->assertStringContainsString('<type>user.unregistered</type>', $xml);
        $this->assertStringContainsString('<user_id>uuid-v4-hier</user_id>', $xml);
        $this->assertStringContainsString('<session_id>session-uuid-001</session_id>', $xml);
    }
}