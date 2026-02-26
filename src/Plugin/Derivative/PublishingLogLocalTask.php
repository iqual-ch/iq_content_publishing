<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a local task for the publishing log on node entities.
 */
final class PublishingLogLocalTask extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs a PublishingLogLocalTask deriver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives['iq_content_publishing.node_log'] = [
      'title' => $this->t('Publishing Log'),
      'route_name' => 'iq_content_publishing.node_log',
      'base_route' => 'entity.node.canonical',
      'weight' => 90,
    ] + $base_plugin_definition;

    return $this->derivatives;
  }

}
