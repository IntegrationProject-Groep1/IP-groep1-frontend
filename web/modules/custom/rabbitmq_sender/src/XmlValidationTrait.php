<?php

namespace Drupal\rabbitmq_sender;

/**
 * Trait XmlValidationTrait
 *
 * Provides methods for validating XML against an XSD schema.
 */
trait XmlValidationTrait {

  /**
   * Validates XML content against an XSD schema.
   *
   * @param string $xmlContent
   * @param string $xsdPath
   *
   * @throws \Exception
   */
  protected function assertValidUuid(string $value, string $field): void {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
      throw new \InvalidArgumentException("{$field} must be a valid UUID");
    }
  }

  protected function validateXml(string $xmlContent, string $xsdPath): void {
    if (!file_exists($xsdPath)) {
      throw new \Exception("XSD file not found: $xsdPath");
    }

    $dom = new \DOMDocument();
    if (!$dom->loadXML($xmlContent)) {
      throw new \Exception("Invalid XML structure.");
    }

    libxml_use_internal_errors(true);
    if (!$dom->schemaValidate($xsdPath)) {
      $errors = libxml_get_errors();
      $errorMessage = "XML validation failed: ";
      foreach ($errors as $error) {
        $errorMessage .= trim($error->message) . " ";
      }
      libxml_clear_errors();
      throw new \Exception($errorMessage);
    }
    libxml_use_internal_errors(false);
  }
}
