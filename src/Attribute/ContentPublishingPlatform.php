<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a ContentPublishingPlatform plugin attribute.
 *
 * Plugin classes in any module can use this attribute to register
 * themselves as an available publishing platform.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ContentPublishingPlatform extends AttributeBase {

  /**
   * Constructs a ContentPublishingPlatform attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   An optional description of the platform.
   * @param class-string|null $deriver
   *   An optional deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
