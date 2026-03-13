<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    $instance->configFactory = $container->get('config.factory');
    $instance->entityTypeManager = $container->get('entity_type.manager');
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
  public function getHtmlFormat(): string {
    $config = $this->configFactory->get('iq_content_publishing.settings');
    $format = $config->get('default_html_format');

    if ($format && $this->formatExists($format)) {
      return $format;
    }

    // Fallback: try common formats in order of preference.
    foreach (['full_html', 'basic_html'] as $fallback) {
      if ($this->formatExists($fallback)) {
        return $fallback;
      }
    }

    // plain_text always exists in core.
    return 'plain_text';
  }

  /**
   * Checks if a text format exists and is enabled.
   *
   * @param string $formatId
   *   The format machine name.
   *
   * @return bool
   *   TRUE if format exists and is enabled.
   */
  protected function formatExists(string $formatId): bool {
    /** @var \Drupal\filter\FilterFormatInterface|null $format */
    $format = $this->entityTypeManager->getStorage('filter_format')->load($formatId);
    return $format && $format->status();
  }

  /**
   * Gets available text formats as options for settings forms.
   *
   * @return array
   *   Array of format ID => label.
   */
  protected function getAvailableTextFormats(): array {
    $options = [];
    /** @var \Drupal\filter\FilterFormatInterface[] $formats */
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    foreach ($formats as $format) {
      if ($format->status()) {
        $options[$format->id()] = $format->label();
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function publish(NodeInterface $node, array $fields, array $credentials, array $settings, string|int|null $toolId = NULL): PublishingResult;

}
