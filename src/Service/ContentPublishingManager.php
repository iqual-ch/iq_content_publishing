<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformManager;
use Drupal\iq_content_publishing\Plugin\MultiToolPlatformInterface;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the content publishing workflow.
 *
 * Coordinates AI content transformation and platform dispatch,
 * handling both synchronous and asynchronous processing modes.
 */
final class ContentPublishingManager {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ContentPublishingManager.
   */
  public function __construct(
    protected ContentPublishingPlatformManager $pluginManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiContentTransformer $aiTransformer,
    protected NodeContentExtractor $contentExtractor,
    protected QueueFactory $queueFactory,
    \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory,
    protected AccountProxyInterface $currentUser,
  ) {
    $this->logger = $loggerFactory->get('iq_content_publishing');
  }

  /**
   * Gets all enabled platforms for a given content type.
   *
   * @param string $content_type
   *   The node bundle machine name.
   *
   * @return \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface[]
   *   Array of enabled platform config entities.
   */
  public function getAvailablePlatforms(string $content_type): array {
    $storage = $this->entityTypeManager->getStorage('publishing_platform');
    /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface[] $platforms */
    $platforms = $storage->loadMultiple();

    return array_filter($platforms, function (PublishingPlatformConfigInterface $platform) use ($content_type) {
      return $platform->status() && $platform->supportsContentType($content_type);
    });
  }

  /**
   * Generates structured content for a node and platform.
   *
   * Calls the AI transformer for ai_generated fields, then merges in
   * programmatic fields (images from node, link from node URL).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   * @param string|int|null $toolId
   *   The tool identifier for multi-tool platforms, or NULL for
   *   single-tool platforms. When provided, tool-specific AI instructions
   *   and output schema are used.
   *
   * @return \Drupal\iq_content_publishing\Service\AiTransformResult
   *   The AI transformation result with all fields populated.
   */
  public function generateContent(NodeInterface $node, PublishingPlatformConfigInterface $platform, string|int|null $toolId = NULL): AiTransformResult {
    $outputSchema = [];

    try {
      /** @var \Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformInterface $plugin */
      $plugin = $this->pluginManager->createInstance($platform->getPluginId());

      // Determine output schema — tool-specific or platform default.
      if ($toolId !== NULL && $plugin instanceof MultiToolPlatformInterface) {
        $toolSchema = $plugin->getOutputSchemaForTool($toolId);
        $outputSchema = !empty($toolSchema) ? $toolSchema : $plugin->getOutputSchema();
      }
      else {
        $outputSchema = $plugin->getOutputSchema();
      }

      // Determine AI instructions — per-tool config > platform config > plugin default.
      $instructions = '';
      if ($toolId !== NULL) {
        // Check for per-tool instructions stored in plugin_settings.
        $pluginSettings = $platform->getPluginSettings();
        $instructions = $pluginSettings['tools'][$toolId]['ai_instructions'] ?? '';
      }

      // Fall back to platform-level instructions.
      if (empty($instructions)) {
        $instructions = $platform->getAiInstructions();
      }

      // Fall back to plugin default (tool-specific or generic).
      if (empty($instructions)) {
        if ($toolId !== NULL && $plugin instanceof MultiToolPlatformInterface) {
          $instructions = $plugin->getDefaultAiInstructionsForTool($toolId);
        }
        else {
          $instructions = $plugin->getDefaultAiInstructions();
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load plugin @plugin: @message', [
        '@plugin' => $platform->getPluginId(),
        '@message' => $e->getMessage(),
      ]);
      return new AiTransformResult(
        success: FALSE,
        fields: [],
        error: 'Failed to load platform plugin: ' . $e->getMessage(),
      );
    }

    // Call the AI transformer with the output schema.
    $aiResult = $this->aiTransformer->transform(
      $node,
      $instructions,
      $outputSchema,
      $platform->getAiProvider(),
      $platform->getAiModel(),
    );

    if (!$aiResult->success) {
      return $aiResult;
    }

    // Merge AI-generated fields with programmatic fields.
    $fields = $this->mergeWithProgrammaticFields($node, $aiResult->fields, $outputSchema);

    return new AiTransformResult(
      success: TRUE,
      fields: $fields,
      prompt: $aiResult->prompt,
      userMessage: $aiResult->userMessage,
    );
  }

  /**
   * Merges AI-generated fields with programmatically populated fields.
   *
   * Non-AI fields like images and links are filled from the node:
   * - 'image' type: extracted from node image/media fields.
   * - 'url' type with ai_generated=FALSE: filled with the node URL.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The source node.
   * @param array $aiFields
   *   The AI-generated fields.
   * @param array $outputSchema
   *   The platform output schema.
   *
   * @return array
   *   The complete fields array.
   */
  protected function mergeWithProgrammaticFields(NodeInterface $node, array $aiFields, array $outputSchema): array {
    $fields = $aiFields;

    foreach ($outputSchema as $fieldName => $fieldDef) {
      // Skip AI-generated fields (already in $fields from AI response).
      if (!empty($fieldDef['ai_generated'])) {
        continue;
      }

      switch ($fieldDef['type'] ?? '') {
        case 'image':
          $fields[$fieldName] = $this->contentExtractor->extractImages($node);
          break;

        case 'url':
          try {
            $fields[$fieldName] = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
          }
          catch (\Exception) {
            $fields[$fieldName] = '';
          }
          break;

        default:
          // For other non-AI fields, set empty default.
          if (!isset($fields[$fieldName])) {
            $fields[$fieldName] = '';
          }
          break;
      }
    }

    return $fields;
  }

  /**
   * Publishes structured content to a platform.
   *
   * Handles both sync and async processing modes.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   * @param array $fields
   *   Structured content fields keyed by output schema field names.
   * @param string|int|null $toolId
   *   The tool identifier for multi-tool platforms, or NULL.
   *
   * @return \Drupal\iq_content_publishing\Plugin\PublishingResult|null
   *   The publishing result for sync mode, NULL for async (queued).
   */
  public function publish(NodeInterface $node, PublishingPlatformConfigInterface $platform, array $fields, string|int|null $toolId = NULL): ?PublishingResult {
    if ($platform->getProcessingMode() === 'async') {
      $this->queueForProcessing($node, $platform, $fields, $toolId);
      return NULL;
    }

    return $this->publishSync($node, $platform, $fields, $toolId);
  }

  /**
   * Synchronously publishes structured content to a platform.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   * @param array $fields
   *   Structured content fields.
   * @param string|int|null $toolId
   *   The tool identifier for multi-tool platforms, or NULL.
   *
   * @return \Drupal\iq_content_publishing\Plugin\PublishingResult
   *   The publishing result.
   */
  public function publishSync(NodeInterface $node, PublishingPlatformConfigInterface $platform, array $fields, string|int|null $toolId = NULL): PublishingResult {
    try {
      $plugin = $this->pluginManager->createInstance($platform->getPluginId());
      $result = $plugin->publish(
        $node,
        $fields,
        $platform->getCredentials(),
        $platform->getPluginSettings(),
        $toolId,
      );
    }
    catch (\Exception $e) {
      $this->logger->error('Publishing to @platform failed for node @nid: @message', [
        '@platform' => $platform->label(),
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      $result = PublishingResult::failure('Publishing failed: ' . $e->getMessage());
    }

    // Log the result.
    $this->createLogEntry($node, $platform, $fields, $result, '', $toolId);

    return $result;
  }

  /**
   * Queues structured content for asynchronous publishing.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   * @param array $fields
   *   Structured content fields.
   * @param string|int|null $toolId
   *   The tool identifier for multi-tool platforms, or NULL.
   */
  protected function queueForProcessing(NodeInterface $node, PublishingPlatformConfigInterface $platform, array $fields, string|int|null $toolId = NULL): void {
    $queue = $this->queueFactory->get('iq_content_publishing');
    $queue->createItem([
      'nid' => $node->id(),
      'platform_id' => $platform->id(),
      'fields' => $fields,
      'uid' => $this->currentUser->id(),
      'tool_id' => $toolId,
    ]);

    $this->logger->info('Queued publishing to @platform for node @nid.', [
      '@platform' => $platform->label(),
      '@nid' => $node->id(),
    ]);
  }

  /**
   * Creates a publishing log entry.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   * @param array $fields
   *   The structured content that was published.
   * @param \Drupal\iq_content_publishing\Plugin\PublishingResult $result
   *   The publishing result.
   * @param string $prompt
   *   The AI prompt used (optional).
   * @param string|int|null $toolId
   *   The tool identifier for multi-tool platforms, or NULL.
   */
  public function createLogEntry(
    NodeInterface $node,
    PublishingPlatformConfigInterface $platform,
    array $fields,
    PublishingResult $result,
    string $prompt = '',
    string|int|null $toolId = NULL,
  ): void {
    try {
      $logStorage = $this->entityTypeManager->getStorage('publishing_log');
      $log = $logStorage->create([
        'nid' => $node->id(),
        'platform_id' => $platform->id(),
        'plugin_id' => $platform->getPluginId(),
        'tool_id' => $toolId !== NULL ? (string) $toolId : NULL,
        'status_code' => $result->success ? 'success' : 'failure',
        'ai_output' => json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        'ai_prompt' => $prompt,
        'api_response' => isset($result->data['response']) ? json_encode($result->data['response']) : '',
        'message' => $result->message,
        'external_id' => $result->data['external_id'] ?? '',
        'external_url' => $result->data['external_url'] ?? '',
        'uid' => $this->currentUser->id(),
      ]);
      $log->save();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create publishing log entry: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
