<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the PublishingPlatformConfig config entity.
 *
 * Stores configuration for a publishing platform instance, including
 * which plugin to use, AI instructions, credentials, and behavior settings.
 *
 * @ConfigEntityType(
 *   id = "publishing_platform",
 *   label = @Translation("Publishing Platform"),
 *   label_collection = @Translation("Publishing Platforms"),
 *   label_singular = @Translation("publishing platform"),
 *   label_plural = @Translation("publishing platforms"),
 *   label_count = @PluralTranslation(
 *     singular = "@count publishing platform",
 *     plural = "@count publishing platforms",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\iq_content_publishing\Entity\PublishingPlatformListBuilder",
 *     "form" = {
 *       "add" = "Drupal\iq_content_publishing\Form\PlatformConfigForm",
 *       "edit" = "Drupal\iq_content_publishing\Form\PlatformConfigForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "platform",
 *   admin_permission = "administer content publishing",
 *   links = {
 *     "collection" = "/admin/config/services/content-publishing",
 *     "add-form" = "/admin/config/services/content-publishing/add",
 *     "edit-form" = "/admin/config/services/content-publishing/{publishing_platform}",
 *     "delete-form" = "/admin/config/services/content-publishing/{publishing_platform}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "plugin_id",
 *     "review_mode",
 *     "processing_mode",
 *     "content_types",
 *     "ai_instructions",
 *     "ai_provider",
 *     "ai_model",
 *     "resubmit_behavior",
 *     "credentials",
 *     "plugin_settings",
 *   },
 * )
 */
final class PublishingPlatformConfig extends ConfigEntityBase implements PublishingPlatformConfigInterface {

  /**
   * The platform machine name.
   */
  protected string $id;

  /**
   * The platform label.
   */
  protected string $label;

  /**
   * The platform description.
   */
  protected string $description = '';

  /**
   * The platform plugin ID.
   */
  protected string $plugin_id = '';

  /**
   * Whether to require review before publishing.
   */
  protected bool $review_mode = TRUE;

  /**
   * Processing mode: 'sync' or 'async'.
   */
  protected string $processing_mode = 'sync';

  /**
   * Enabled content type machine names.
   *
   * @var string[]
   */
  protected array $content_types = [];

  /**
   * AI prompt instructions template.
   */
  protected string $ai_instructions = '';

  /**
   * AI model override (empty = use site default).
   */
  protected string $ai_model = '';

  /**
   * AI provider ID (empty = use site default).
   */
  protected string $ai_provider = '';

  /**
   * Re-submission behavior: 'allow', 'warn', or 'block'.
   */
  protected string $resubmit_behavior = 'warn';

  /**
   * Platform credentials (API keys, tokens, etc.).
   *
   * @var array
   */
  protected array $credentials = [];

  /**
   * Platform-specific settings.
   *
   * @var array
   */
  protected array $plugin_settings = [];

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return $this->plugin_id;
  }

  /**
   * {@inheritdoc}
   */
  public function isReviewMode(): bool {
    return $this->review_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getProcessingMode(): string {
    return $this->processing_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getContentTypes(): array {
    return $this->content_types;
  }

  /**
   * {@inheritdoc}
   */
  public function getAiInstructions(): string {
    return $this->ai_instructions;
  }

  /**
   * {@inheritdoc}
   */
  public function getAiModel(): string {
    return $this->ai_model;
  }

  /**
   * {@inheritdoc}
   */
  public function getAiProvider(): string {
    return $this->ai_provider;
  }

  /**
   * {@inheritdoc}
   */
  public function getResubmitBehavior(): string {
    return $this->resubmit_behavior;
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentials(): array {
    return $this->credentials;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginSettings(): array {
    return $this->plugin_settings;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsContentType(string $content_type): bool {
    $types = $this->getContentTypes();
    return empty($types) || in_array($content_type, $types, TRUE);
  }

}
