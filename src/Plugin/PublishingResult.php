<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin;

/**
 * Value object representing the result of a publishing operation.
 */
final class PublishingResult {

  /**
   * Constructs a PublishingResult.
   *
   * @param bool $success
   *   Whether the publishing was successful.
   * @param string $message
   *   A human-readable message about the result.
   * @param array $data
   *   Optional additional data (API response, external ID, etc.).
   */
  public function __construct(
    public readonly bool $success,
    public readonly string $message,
    public readonly array $data = [],
  ) {}

  /**
   * Creates a successful result.
   */
  public static function success(string $message, array $data = []): self {
    return new self(TRUE, $message, $data);
  }

  /**
   * Creates a failed result.
   */
  public static function failure(string $message, array $data = []): self {
    return new self(FALSE, $message, $data);
  }

}
