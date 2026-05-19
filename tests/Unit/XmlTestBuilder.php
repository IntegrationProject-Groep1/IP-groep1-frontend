<?php
declare(strict_types=1);

namespace Tests\Unit;

/**
 * Helper to generate valid XML compliant with current XSD contracts.
 */
class XmlTestBuilder
{
    public static function build(string $type, array $headerFields, array $bodyFields, string $root = 'message', bool $useBody = true): string
    {
        $header = '<header>'
            . '<message_id>' . ($headerFields['message_id'] ?? '550e8400-e29b-41d4-a716-446655440001') . '</message_id>'
            . '<timestamp>' . ($headerFields['timestamp'] ?? '2026-05-07T00:00:00Z') . '</timestamp>'
            . '<source>' . ($headerFields['source'] ?? 'planning') . '</source>'
            . '<type>' . $type . '</type>'
            . '<version>' . ($headerFields['version'] ?? '2.0') . '</version>'
            . '</header>';

        $bodyContent = '';
        foreach ($bodyFields as $key => $value) {
            $bodyContent .= "<{$key}>" . htmlspecialchars((string)$value, ENT_XML1, 'UTF-8') . "</{$key}>";
        }

        $body = $useBody ? "<body>{$bodyContent}</body>" : $bodyContent;

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . "<{$root}>"
            . ($root === 'message' ? $header : '')
            . $body
            . "</{$root}>";
    }
}
