<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\CancelRegistrationSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for CancelRegistrationSender — contract §5.6.
 */
class CancelRegistrationSenderTest extends TestCase
{
    private CancelRegistrationSender $sender;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->sender = new CancelRegistrationSender($stub);
    }

    // ─── validation ──────────────────────────────────────────────────────────

    public function test_throws_when_identity_uuid_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identity_uuid is required');

        $this->sender->buildXml([
            'session_id'     => 'sess-001',
            'correlation_id' => 'c3d4e5f6-a7b8-9012-cdef-012345678901',
        ]);
    }

    public function test_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');

        $this->sender->buildXml([
            'identity_uuid'  => 'e8b27c1d-4f2a-4b3e-9c5f-123456789abc',
            'correlation_id' => 'c3d4e5f6-a7b8-9012-cdef-012345678901',
        ]);
    }

    public function test_throws_when_correlation_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('correlation_id is required');

        $this->sender->buildXml([
            'identity_uuid' => 'e8b27c1d-4f2a-4b3e-9c5f-123456789abc',
            'session_id'    => 'sess-001',
        ]);
    }

    // ─── XML structure ───────────────────────────────────────────────────────

    public function test_produces_well_formed_xml(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $dom = new \DOMDocument();
        $this->assertNotFalse($dom->loadXML($xml), 'buildXml() must produce well-formed XML');
    }

    public function test_contains_type_cancel_registration(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<type>cancel_registration</type>', $xml);
    }

    public function test_contains_source_frontend(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<source>frontend</source>', $xml);
    }

    public function test_contains_version_2_0(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<version>2.0</version>', $xml);
    }

    public function test_contains_correlation_id_in_header(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<correlation_id>c3d4e5f6-a7b8-9012-cdef-012345678901</correlation_id>', $xml);
    }

    public function test_contains_identity_uuid_in_body(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<identity_uuid>e8b27c1d-4f2a-4b3e-9c5f-123456789abc</identity_uuid>', $xml);
    }

    public function test_contains_session_id_in_body(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<session_id>sess-keynote-001</session_id>', $xml);
    }

    public function test_includes_reason_when_provided(): void
    {
        $data = $this->validData();
        $data['reason'] = 'Dubbele boeking per ongeluk';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<reason>Dubbele boeking per ongeluk</reason>', $xml);
    }

    public function test_omits_reason_when_not_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('<reason>', $xml);
    }

    public function test_accepts_user_id_as_fallback_for_identity_uuid(): void
    {
        $data = $this->validData();
        unset($data['identity_uuid']);
        $data['user_id'] = 'user-fallback-uuid';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<identity_uuid>user-fallback-uuid</identity_uuid>', $xml);
    }

    public function test_contains_message_id_uuid_v4(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertMatchesRegularExpression(
            '/<message_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}<\/message_id>/',
            $xml
        );
    }

    public function test_does_not_contain_xmlns(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('xmlns=', $xml);
    }

    // ─── escaping ────────────────────────────────────────────────────────────

    public function test_escapes_special_chars_in_session_id(): void
    {
        $data = $this->validData();
        $data['session_id'] = 'sess-<evil>&bad';

        $xml = $this->sender->buildXml($data);

        $this->assertStringNotContainsString('<evil>', $xml);
        $this->assertStringContainsString('&lt;evil&gt;', $xml);
    }

    public function test_escapes_special_chars_in_reason(): void
    {
        $data = $this->validData();
        $data['reason'] = 'A & B <test>';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('A &amp; B &lt;test&gt;', $xml);
    }

    // ─── send() routing ──────────────────────────────────────────────────────

    public function test_send_publishes_to_crm_incoming(): void
    {
        $mock = $this->createStub(RabbitMQClient::class);

        $channel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->isInstanceOf(\PhpAmqpLib\Message\AMQPMessage::class),
                '',
                'crm.incoming'
            );

        $mock->method('getChannel')->willReturn($channel);

        (new CancelRegistrationSender($mock))->send($this->validData());
    }

    public function test_send_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->validData();
        unset($data['session_id']);
        $this->sender->send($data);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function validData(): array
    {
        return [
            'identity_uuid'  => 'e8b27c1d-4f2a-4b3e-9c5f-123456789abc',
            'session_id'     => 'sess-keynote-001',
            'correlation_id' => 'c3d4e5f6-a7b8-9012-cdef-012345678901',
        ];
    }
}
