<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;

/**
 * Interface for ContentPublishingPlatform plugins.
 *
 * Each platform plugin is responsible for:
 * - Defining an output schema (the structured fields it expects).
 * - Providing default AI prompt instructions.
 * - Defining platform-specific configuration (credentials form).
 * - Publishing structured content to the external API.
 */
interface ContentPublishingPlatformInterface extends PluginInspectionInterface, ContainerFactoryPluginInterface {

  /**
   * Returns the plugin label.
   *
   * @return string
   *   The human-readable platform label.
   */
  public function getLabel(): string;

  /**
   * Returns the plugin description.
   *
   * @return string
   *   The platform description.
   */
  public function getDescription(): string;

  /**
   * Returns the output schema for this platform.
   *
   * Each key is a field name, each value is an array describing the field:
   *   - 'type': Form widget type ('textarea', 'textfield', 'url', 'image').
   *   - 'label': Human-readable field label.
   *   - 'description': Help text for the field (optional).
   *   - 'required': Whether the field is required (default FALSE).
   *   - 'max_length': Max character count (optional, for text fields).
   *   - 'max': Max number of items (optional, for image fields).
   *   - 'ai_generated': Whether the AI should fill this field (default TRUE).
   *     Fields with ai_generated=FALSE are populated programmatically
   *     (e.g., images from node fields, link from node URL).
   *
   * @return array<string, array<string, mixed>>
   *   The output schema keyed by field name.
   */
  public function getOutputSchema(): array;

  /**
   * Returns default AI prompt instructions for this platform.
   *
   * These instructions tell the AI how to transform node content
   * for this specific platform (e.g., social post format, newsletter format).
   *
   * @return string
   *   The default prompt instructions.
   */
  public function getDefaultAiInstructions(): string;

  /**
   * Builds the platform-specific credentials configuration form.
   *
   * @param array $form
   *   The form array.
   * @param array $credentials
   *   The current credential values.
   *
   * @return array
   *   The form elements for credentials.
   */
  public function buildCredentialsForm(array $form, array $credentials): array;

  /**
   * Builds the platform-specific settings form.
   *
   * @param array $form
   *   The form array.
   * @param array $settings
   *   The current plugin settings.
   *
   * @return array
   *   The form elements for plugin-specific settings.
   */
  public function buildSettingsForm(array $form, array $settings): array;

  /**
   * Validates the credentials.
   *
   * @param array $credentials
   *   The credentials to validate.
   *
   * @return bool
   *   TRUE if credentials are valid.
   */
  public function validateCredentials(array $credentials): bool;

  /**
   * Publishes structured content to the external platform.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The source node.
   * @param array $fields
   *   Structured content keyed by output schema field names.
   *   Text fields contain strings; image fields contain arrays with
   *   'uri', 'url', 'alt', and 'fid' keys.
   * @param array $credentials
   *   The platform credentials.
   * @param array $settings
   *   The platform-specific settings.
   * @param string|int|null $toolId
   *   The tool/content type identifier for multi-tool platforms.
   *   NULL for single-tool platforms. Platforms implementing
   *   MultiToolPlatformInterface receive this to route content to
   *   the correct tool.
   *
   * @return \Drupal\iq_content_publishing\Plugin\PublishingResult
   *   The result of the publishing operation.
   */
  public function publish(NodeInterface $node, array $fields, array $credentials, array $settings, string|int|null $toolId = NULL): PublishingResult;

}
