<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for ContentPublishingPlatform plugins.
 *
 * Provides shared functionality and sensible defaults.
 * Platform-specific modules should extend this class.
 */
abstract class ContentPublishingPlatformBase extends PluginBase implements ContentPublishingPlatformInterface {

  use StringTranslationTrait;

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) ($this->pluginDefinition['description'] ?? '');
  }

  /**
   * {@inheritdoc}
   *
   * Default schema: a single required "text" textarea field.
   * Platform plugins should override this with their specific schema.
   */
  public function getOutputSchema(): array {
    return [
      'text' => [
        'type' => 'textarea',
        'label' => (string) $this->t('Post text'),
        'required' => TRUE,
        'ai_generated' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructions(): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildCredentialsForm(array $form, array $credentials): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, array $settings, array $credentials = []): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCredentials(array $credentials): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function publish(NodeInterface $node, array $fields, array $credentials, array $settings, string|int|null $toolId = NULL): PublishingResult;

}
