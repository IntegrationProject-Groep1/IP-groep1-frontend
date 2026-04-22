<?php
declare(strict_types=1);

use Drupal\rabbitmq_sender\IdentityServiceClient;
use Drupal\rabbitmq_sender\RabbitMQClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IdentityServiceClient XML building and response parsing.
 */
class IdentityServiceClientTest extends TestCase
{
    private IdentityServiceClient $client;

    protected function setUp(): void
    {
        $mockRabbit = $this->createStub(RabbitMQClient::class);
        $this->client = new IdentityServiceClient($mockRabbit);
    }

    // ── buildRequestXml ──────────────────────────────────────────────────────

    public function test_build_request_xml_contains_email(): void
    {
        $xml = $this->client->buildRequestXml('Jan@Example.COM');

        $this->assertStringContainsString('<email>jan@example.com</email>', $xml);
    }

    public function test_build_request_xml_normalizes_email_to_lowercase(): void
    {
        $xml = $this->client->buildRequestXml('USER@EXAMPLE.COM');

        $this->assertStringContainsString('<email>user@example.com</email>', $xml);
    }

    public function test_build_request_xml_contains_source_system(): void
    {
        $xml = $this->client->buildRequestXml('user@example.com');

        $this->assertStringContainsString('<source_system>frontend</source_system>', $xml);
    }

    public function test_build_request_xml_is_valid_xml(): void
    {
        $xml = $this->client->buildRequestXml('test@example.com');

        $doc = @simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'buildRequestXml must produce valid XML');
        $this->assertSame('identity_request', $doc->getName());
    }

    public function test_build_request_xml_escapes_special_characters(): void
    {
        $xml = $this->client->buildRequestXml('test+label@example.com');

        $doc = @simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $this->assertSame('test+label@example.com', (string) $doc->email);
    }

    // ── parseMasterUuid ──────────────────────────────────────────────────────

    public function test_parse_master_uuid_returns_uuid_from_valid_response(): void
    {
        $xml = <<<XML
<identity_response>
  <status>ok</status>
  <user>
    <master_uuid>01890a5d-ac96-7ab2-80e2-4536629c90de</master_uuid>
    <email>user@example.com</email>
    <created_by>frontend</created_by>
    <created_at>2026-04-05T12:00:00+00:00</created_at>
  </user>
</identity_response>
XML;

        $masterUuid = $this->client->parseMasterUuid($xml);

        $this->assertSame('01890a5d-ac96-7ab2-80e2-4536629c90de', $masterUuid);
    }

    public function test_parse_master_uuid_throws_on_invalid_xml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML response');

        $this->client->parseMasterUuid('not xml at all {{}}');
    }

    public function test_parse_master_uuid_throws_on_non_ok_status(): void
    {
        $xml = <<<XML
<identity_response>
  <status>error</status>
</identity_response>
XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-ok status: error');

        $this->client->parseMasterUuid($xml);
    }

    public function test_parse_master_uuid_throws_when_uuid_missing(): void
    {
        $xml = <<<XML
<identity_response>
  <status>ok</status>
  <user>
    <email>user@example.com</email>
  </user>
</identity_response>
XML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('master_uuid missing');

        $this->client->parseMasterUuid($xml);
    }

    public function test_parse_master_uuid_trims_whitespace(): void
    {
        $xml = <<<XML
<identity_response>
  <status>ok</status>
  <user>
    <master_uuid>  01890a5d-ac96-7ab2-80e2-4536629c90de  </master_uuid>
  </user>
</identity_response>
XML;

        $masterUuid = $this->client->parseMasterUuid($xml);

        $this->assertSame('01890a5d-ac96-7ab2-80e2-4536629c90de', $masterUuid);
    }

    // ── createOrGet validation ───────────────────────────────────────────────

    public function test_create_or_get_throws_when_email_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('email is required');

        $this->client->createOrGet('');
    }

    public function test_create_or_get_throws_when_email_is_whitespace_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client->createOrGet('   ');
    }
}
