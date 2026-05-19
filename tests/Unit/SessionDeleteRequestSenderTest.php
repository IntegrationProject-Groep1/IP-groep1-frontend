<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\SessionDeleteRequestSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for SessionDeleteRequestSender — XML generation and exchange routing.
 */
class SessionDeleteRequestSenderTest extends TestCase
{
    private SessionDeleteRequestSender $sender;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->sender = new SessionDeleteRequestSender($stub);
    }

    // ─── buildXml: required field validation ─────────────────────────────────

    public function test_buildXml_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');

        $this->sender->buildXml(['session_id' => '']);
    }

    // ─── buildXml: XML structure ──────────────────────────────────────────────

    public function test_buildXml_does_not_contain_namespace(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringNotContainsString('xmlns=', $xml);
    }

    public function test_buildXml_contains_correct_type(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringContainsString('<type>session_delete_request</type>', $xml);
    }

    public function test_buildXml_contains_correct_version(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringContainsString('<version>2.0</version>', $xml);
    }

    public function test_buildXml_contains_source_frontend(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringContainsString('<source>frontend</source>', $xml);
    }

    public function test_buildXml_contains_message_id_uuid_v4(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertMatchesRegularExpression(
            '/<message_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}<\/message_id>/',
            $xml
        );
    }

    public function test_buildXml_contains_timestamp(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringContainsString('<timestamp>', $xml);
    }

    public function test_buildXml_produces_well_formed_xml(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $dom = new \DOMDocument();
        $this->assertNotFalse($dom->loadXML($xml), 'buildXml() must produce well-formed XML');
    }

    // ─── buildXml: body fields ────────────────────────────────────────────────

    public function test_buildXml_contains_session_id(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringContainsString('<session_id>sess-uuid-001</session_id>', $xml);
    }

    public function test_buildXml_includes_reason_when_provided(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001', 'reason' => 'cancelled']);

        $this->assertStringContainsString('<reason>cancelled</reason>', $xml);
    }

    public function test_buildXml_omits_reason_when_not_provided(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringNotContainsString('<reason>', $xml);
    }

    public function test_buildXml_escapes_special_chars_in_session_id(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'id<with>&special']);

        $this->assertStringContainsString('<session_id>id&lt;with&gt;&amp;special</session_id>', $xml);
    }

    public function test_each_call_generates_unique_message_id(): void
    {
        $xml1 = $this->sender->buildXml(['session_id' => 'sess-001']);
        $xml2 = $this->sender->buildXml(['session_id' => 'sess-001']);

        preg_match('/<message_id>([^<]+)<\/message_id>/', $xml1, $m1);
        preg_match('/<message_id>([^<]+)<\/message_id>/', $xml2, $m2);

        $this->assertNotSame($m1[1], $m2[1]);
    }

    // ─── send(): exchange and routing key ────────────────────────────────────

    public function test_send_declares_planning_exchange(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->expects($this->once())
            ->method('declareExchange')
            ->with('planning.exchange', 'topic');
        $mock->method('publishToExchange');

        (new SessionDeleteRequestSender($mock))->send(['session_id' => 'sess-001']);
    }

    public function test_send_publishes_to_correct_routing_key(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with('planning.exchange', 'frontend.to.planning.session.delete', $this->anything());

        (new SessionDeleteRequestSender($mock))->send(['session_id' => 'sess-001']);
    }

    public function test_send_passes_well_formed_xml(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with(
                'planning.exchange',
                'frontend.to.planning.session.delete',
                $this->callback(static function (string $xml): bool {
                    $dom = new \DOMDocument();
                    return $dom->loadXML($xml) !== false;
                })
            );

        (new SessionDeleteRequestSender($mock))->send(['session_id' => 'sess-001']);
    }
}

