<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\UserRegisteredSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for user registered sender validation and XML generation.
 */
class UserRegisteredSenderTest extends TestCase
{
    private UserRegisteredSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new UserRegisteredSender($mockClient);
    }

    public function test_throws_exception_when_email_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'session_id' => 'session-uuid-001',
            'is_company' => false,
        ]);
    }

    public function test_throws_exception_when_session_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'is_company' => false,
        ]);
    }

    public function test_throws_exception_when_company_has_no_vat_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'session_id' => 'session-uuid-001',
            'is_company' => true,
            'company_name' => 'Bedrijf NV',
        ]);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'session_id' => 'session-uuid-001',
            'is_company' => false,
        ]);

        $this->assertStringContainsString('<type>new_registration</type>', $xml);
        $this->assertStringContainsString('<version>2.0</version>', $xml);
        $this->assertStringContainsString('<source>frontend.drupal</source>', $xml);
        $this->assertStringContainsString('<customer>', $xml);
        $this->assertStringContainsString('<email>jan@test.be</email>', $xml);
        $this->assertStringContainsString('<type>private</type>', $xml);
        $this->assertStringContainsString('<session_id>session-uuid-001</session_id>', $xml);
        $this->assertStringContainsString('<payment_due>', $xml);
        $this->assertStringContainsString('<amount>0.00</amount>', $xml);
        $this->assertStringContainsString('<status>unpaid</status>', $xml);
        $this->assertStringNotContainsString('xmlns', $xml);
        $this->assertStringNotContainsString('<receiver>', $xml);
    }
}
