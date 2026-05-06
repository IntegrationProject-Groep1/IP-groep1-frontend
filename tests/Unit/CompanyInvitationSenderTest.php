<?php
declare(strict_types=1);

use Drupal\rabbitmq_sender\CompanyInvitationSender;
use Drupal\rabbitmq_sender\RabbitMQClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for company invitation sender validation and XML output.
 */
class CompanyInvitationSenderTest extends TestCase
{
    private CompanyInvitationSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new CompanyInvitationSender($mockClient);
    }

    public function test_send_throws_exception_when_email_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'inviter_user_id' => '42',
        ]);
    }

    public function test_send_throws_exception_when_email_is_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'invitee_email' => 'not-an-email',
            'inviter_user_id' => '42',
        ]);
    }

    public function test_valid_data_builds_expected_xml(): void
    {
        $xml = $this->sender->buildXml([
            'invitee_email' => 'new.user@example.com',
            'inviter_user_id' => '42',
            'company_id' => 'comp-1001',
            'company_name' => 'Acme BV',
        ]);

        $this->assertStringContainsString('<type>company_invite</type>', $xml);
        $this->assertStringContainsString('<invitee_email>new.user@example.com</invitee_email>', $xml);
        $this->assertStringContainsString('<inviter_user_id>42</inviter_user_id>', $xml);
        $this->assertStringContainsString('<company_id>comp-1001</company_id>', $xml);
        $this->assertStringContainsString('<company_name>Acme BV</company_name>', $xml);
    }
}
