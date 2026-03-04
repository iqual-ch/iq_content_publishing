<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\iq_content_publishing\Service\ContentPublishingManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued content publishing operations.
 *
 * @QueueWorker(
 *   id = "iq_content_publishing",
 *   title = @Translation("Content Publishing Queue Worker"),
 *   cron = {"time" = 60},
 * )
 */
final class ContentPublishingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ContentPublishingQueueWorker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ContentPublishingManager $publishingManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('iq_content_publishing');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('iq_content_publishing.manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = $data['nid'] ?? NULL;
    $platformId = $data['platform_id'] ?? NULL;
    $fields = $data['fields'] ?? [];
    $toolId = $data['tool_id'] ?? NULL;

    if (!$nid || !$platformId) {
      $this->logger->error('Invalid queue item: missing nid or platform_id.');
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      $this->logger->error('Queue item references non-existent node @nid.', ['@nid' => $nid]);
      return;
    }

    $platform = $this->entityTypeManager->getStorage('publishing_platform')->load($platformId);
    if (!$platform) {
      $this->logger->error('Queue item references non-existent platform @id.', ['@id' => $platformId]);
      return;
    }

    // If no pre-generated fields, generate them now.
    if (empty($fields)) {
      $aiResult = $this->publishingManager->generateContent($node, $platform, $toolId);
      if (!$aiResult->success) {
        $this->logger->error('AI generation failed for queued item (node @nid, platform @platform): @error', [
          '@nid' => $nid,
          '@platform' => $platformId,
          '@error' => $aiResult->error,
        ]);
        throw new RequeueException('AI generation failed, requeueing.');
      }
      $fields = $aiResult->fields;
    }

    // Publish synchronously (bypasses the queue check in publishSync).
    $this->publishingManager->publishSync($node, $platform, $fields, $toolId);
  }

}
