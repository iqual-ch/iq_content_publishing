<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for PublishingPlatformConfig config entities.
 */
interface PublishingPlatformConfigInterface extends ConfigEntityInterface {

  /**
   * Returns the platform plugin ID.
   */
  public function getPluginId(): string;

  /**
   * Returns whether review mode is enabled.
   */
  public function isReviewMode(): bool;

  /**
   * Returns the processing mode ('sync' or 'async').
   */
  public function getProcessingMode(): string;

  /**
   * Returns the enabled content type machine names.
   */
  public function getContentTypes(): array;

  /**
   * Returns the AI prompt instructions.
   */
  public function getAiInstructions(): string;

  /**
   * Returns the AI model override, or empty string for default.
   */
  public function getAiModel(): string;

  /**
   * Returns the AI provider ID, or empty string for default.
   */
  public function getAiProvider(): string;

  /**
   * Returns the re-submission behavior: 'allow', 'warn', or 'block'.
   */
  public function getResubmitBehavior(): string;

  /**
   * Returns the platform credentials.
   */
  public function getCredentials(): array;

  /**
   * Returns the platform-specific settings.
   */
  public function getPluginSettings(): array;

  /**
   * Returns the description.
   */
  public function getDescription(): string;

  /**
   * Checks if this platform supports the given content type.
   */
  public function supportsContentType(string $content_type): bool;

}
