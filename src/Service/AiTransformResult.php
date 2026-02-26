<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

/**
 * Value object for AI transformation results.
 *
 * Contains structured fields keyed by the platform output schema field names.
 * AI-generated fields are strings; image fields are arrays of image data.
 */
final class AiTransformResult {

  /**
   * Constructs an AiTransformResult.
   *
   * @param bool $success
   *   Whether the transformation succeeded.
   * @param array $fields
   *   Structured content fields keyed by output schema field names.
   *   AI-generated text fields are strings.
   *   Image fields are arrays of image data (fid, uri, url, alt, etc.).
   * @param string $prompt
   *   The system prompt that was used.
   * @param string $userMessage
   *   The user message that was sent.
   * @param string $error
   *   Error message if transformation failed.
   */
  public function __construct(
    public readonly bool $success,
    public readonly array $fields = [],
    public readonly string $prompt = '',
    public readonly string $userMessage = '',
    public readonly string $error = '',
  ) {}

}
