<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface;
use Drupal\node\NodeInterface;

/**
 * Dispatched before AI content transformation.
 *
 * Allows subscribers to modify the AI instructions and output schema
 * before they are sent to the AI provider. This enables platform plugins
 * and other modules to inject dynamic context (e.g., template HTML,
 * platform-specific data) into the AI prompt.
 */
final class PreTransformEvent extends Event {

  /**
   * The event name.
   */
  const EVENT_NAME = 'iq_content_publishing.pre_transform';

  /**
   * The AI instructions string.
   */
  protected string $instructions;

  /**
   * The output schema array.
   */
  protected array $outputSchema;

  /**
   * Constructs a PreTransformEvent.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being transformed.
   * @param string $instructions
   *   The resolved AI instructions.
   * @param array $outputSchema
   *   The output schema for AI-generated fields.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   * @param string|int|null $toolId
   *   The tool identifier for multi-tool platforms, or NULL.
   */
  public function __construct(
    protected readonly NodeInterface $node,
    string $instructions,
    array $outputSchema,
    protected readonly PublishingPlatformConfigInterface $platform,
    protected readonly string|int|null $toolId = NULL,
  ) {
    $this->instructions = $instructions;
    $this->outputSchema = $outputSchema;
  }

  /**
   * Gets the node being transformed.
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * Gets the current AI instructions.
   */
  public function getInstructions(): string {
    return $this->instructions;
  }

  /**
   * Sets the AI instructions.
   */
  public function setInstructions(string $instructions): void {
    $this->instructions = $instructions;
  }

  /**
   * Gets the output schema.
   */
  public function getOutputSchema(): array {
    return $this->outputSchema;
  }

  /**
   * Sets the output schema.
   */
  public function setOutputSchema(array $outputSchema): void {
    $this->outputSchema = $outputSchema;
  }

  /**
   * Gets the platform config entity.
   */
  public function getPlatform(): PublishingPlatformConfigInterface {
    return $this->platform;
  }

  /**
   * Gets the tool ID.
   */
  public function getToolId(): string|int|null {
    return $this->toolId;
  }

}
