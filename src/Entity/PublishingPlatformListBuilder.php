<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for PublishingPlatformConfig entities.
 */
final class PublishingPlatformListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Platform');
    $header['plugin_id'] = $this->t('Plugin');
    $header['review_mode'] = $this->t('Review Mode');
    $header['processing_mode'] = $this->t('Processing');
    $header['content_types'] = $this->t('Content Types');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $entity */
    $row['label'] = $entity->label();
    $row['plugin_id'] = $entity->getPluginId();
    $row['review_mode'] = $entity->isReviewMode() ? $this->t('Yes') : $this->t('No');
    $row['processing_mode'] = $entity->getProcessingMode() === 'async' ? $this->t('Async') : $this->t('Sync');
    $content_types = $entity->getContentTypes();
    $row['content_types'] = $content_types ? implode(', ', $content_types) : $this->t('All');
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

}
