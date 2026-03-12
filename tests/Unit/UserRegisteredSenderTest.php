<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
            'session_name' => 'Workshop AI',
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
            'session_name' => 'Workshop AI',
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
            'session_name' => 'Workshop AI',
            'is_company' => true,
            'company_name' => 'Bedrijf NV',
            // vat_number ontbreekt
        ]);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'session_id' => 'session-uuid-001',
            'session_name' => 'Workshop AI',
            'is_company' => false,
        ]);

        $this->assertStringContainsString('<event_type>user.registered</event_type>', $xml);
        $this->assertStringContainsString('<email>jan@test.be</email>', $xml);
        $this->assertStringContainsString('<payment_status>pending</payment_status>', $xml);
    }
}