<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\EventEndedSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for EventEndedSender validation and XML generation.
 */
class EventEndedSenderTest extends TestCase
{
    private EventEndedSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new EventEndedSender($mockClient);
    }

    public function test_throws_exception_when_session_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([]);
    }

    public function test_throws_exception_when_session_id_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send(['session_id' => '']);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'session-uuid-001']);

        $this->assertStringContainsString('<type>event_ended</type>', $xml);
        $this->assertStringContainsString('<version>2.0</version>', $xml);
        $this->assertStringContainsString('<source>frontend</source>', $xml);
        $this->assertStringContainsString('<session_id>session-uuid-001</session_id>', $xml);
        $this->assertStringContainsString('<ended_at>', $xml);
        $this->assertStringContainsString('<message_id>', $xml);
        $this->assertStringContainsString('<timestamp>', $xml);
        $this->assertStringContainsString('<message>', $xml);
        $this->assertStringNotContainsString('xmlns=', $xml);
    }

    public function test_message_id_is_valid_uuid_v4(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'session-uuid-001']);

        preg_match('/<message_id>([^<]+)<\/message_id>/', $xml, $matches);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $matches[1]
        );
    }

    public function test_timestamp_and_ended_at_are_iso8601(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'session-uuid-001']);

        preg_match('/<timestamp>([^<]+)<\/timestamp>/', $xml, $tsMatches);
        preg_match('/<ended_at>([^<]+)<\/ended_at>/', $xml, $eaMatches);

        $this->assertNotEmpty($tsMatches[1]);
        $this->assertNotEmpty($eaMatches[1]);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $tsMatches[1]));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $eaMatches[1]));
    }

    public function test_session_id_is_xml_escaped(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'id<with>&special']);

        $this->assertStringContainsString('<session_id>id&lt;with&gt;&amp;special</session_id>', $xml);
    }

    public function test_each_call_generates_unique_message_id(): void
    {
        $xml1 = $this->sender->buildXml(['session_id' => 'session-uuid-001']);
        $xml2 = $this->sender->buildXml(['session_id' => 'session-uuid-001']);

        preg_match('/<message_id>([^<]+)<\/message_id>/', $xml1, $m1);
        preg_match('/<message_id>([^<]+)<\/message_id>/', $xml2, $m2);

        $this->assertNotSame($m1[1], $m2[1]);
    }
}
