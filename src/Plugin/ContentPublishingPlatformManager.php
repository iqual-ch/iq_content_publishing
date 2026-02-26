<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;

/**
 * Plugin manager for ContentPublishingPlatform plugins.
 *
 * Discovers platform plugins across all enabled modules using the
 * ContentPublishingPlatform attribute on classes in the
 * Plugin\ContentPublishingPlatform namespace.
 */
final class ContentPublishingPlatformManager extends DefaultPluginManager {

  /**
   * Constructs a ContentPublishingPlatformManager.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ContentPublishingPlatform',
      $namespaces,
      $module_handler,
      ContentPublishingPlatformInterface::class,
      ContentPublishingPlatform::class,
    );
    $this->alterInfo('content_publishing_platform_info');
    $this->setCacheBackend($cache_backend, 'content_publishing_platform_plugins');
  }

}
