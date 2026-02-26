<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * Controller for the publishing log views.
 */
final class PublishingLogController extends ControllerBase {

  /**
   * Displays the global publishing log overview.
   */
  public function overview(): array {
    $storage = $this->entityTypeManager()->getStorage('publishing_log');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->pager(50);
    $ids = $query->execute();
    $logs = $storage->loadMultiple($ids);

    return $this->buildLogTable($logs);
  }

  /**
   * Displays the publishing log for a specific node.
   */
  public function nodeLog(NodeInterface $node): array {
    $storage = $this->entityTypeManager()->getStorage('publishing_log');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('nid', $node->id())
      ->sort('created', 'DESC')
      ->pager(50);
    $ids = $query->execute();
    $logs = $storage->loadMultiple($ids);

    if (empty($logs)) {
      return [
        '#markup' => '<p>' . $this->t('No publishing history for this content.') . '</p>',
      ];
    }

    return $this->buildLogTable($logs);
  }

  /**
   * Builds a render array for a table of publishing log entries.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingLog[] $logs
   *   The log entries.
   *
   * @return array
   *   A render array.
   */
  protected function buildLogTable(array $logs): array {
    $header = [
      $this->t('Date'),
      $this->t('Node'),
      $this->t('Platform'),
      $this->t('Status'),
      $this->t('External'),
      $this->t('Message'),
      $this->t('User'),
    ];

    $rows = [];
    foreach ($logs as $log) {
      $node = $log->get('nid')->entity;
      $user = $log->get('uid')->entity;
      $externalUrl = $log->get('external_url')->value ?? '';
      $externalId = $log->get('external_id')->value ?? '';
      $externalCell = $externalUrl
        ? ['data' => ['#type' => 'link', '#title' => $externalId ?: $this->t('View'), '#url' => \Drupal\Core\Url::fromUri($externalUrl), '#attributes' => ['target' => '_blank']]]
        : ($externalId ?: '-');

      $rows[] = [
        \Drupal::service('date.formatter')->format($log->get('created')->value, 'short'),
        $node ? $node->toLink()->toString() : $this->t('Deleted'),
        $log->get('platform_id')->value,
        $log->get('status_code')->value,
        $externalCell,
        $log->get('message')->value ?: '-',
        $user ? $user->getDisplayName() : $this->t('Unknown'),
      ];
    }

    $build = [];
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No publishing log entries yet.'),
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
