<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformManager;
use Drupal\iq_content_publishing\Plugin\MultiToolPlatformInterface;
use Drupal\iq_content_publishing\Service\ContentPublishingManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for selecting external publishing platforms.
 *
 * Step 1 of the publishing workflow: the editor selects which external
 * platforms to publish to. Fire-and-forget platforms are processed
 * immediately; review-mode platforms redirect to the review form.
 */
final class PublishingSelectionForm extends FormBase {

  public function __construct(
    protected ContentPublishingManager $publishingManager,
    protected ContentPublishingPlatformManager $pluginManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('iq_content_publishing.manager'),
      $container->get('plugin.manager.content_publishing_platform'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'iq_content_publishing_selection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node) {
      return $form;
    }

    $platforms = $this->publishingManager->getAvailablePlatforms($node->getType());

    if (empty($platforms)) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No publishing platforms are configured for this content type.') . '</p>',
      ];
      return $form;
    }

    // Query the publishing log to find previous submissions for this node.
    $publishHistory = $this->getPublishHistory($node, $platforms);

    $form['nid'] = [
      '#type' => 'hidden',
      '#value' => $node->id(),
    ];

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Select the external platforms you want to publish "<strong>@title</strong>" to:', [
        '@title' => $node->getTitle(),
      ]) . '</p>',
    ];

    $form['platforms'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Platforms'),
      '#options' => [],
    ];

    // Collect default selections: multi-tool entries are pre-selected.
    $defaultPlatforms = [];

    foreach ($platforms as $platform) {
      $platformId = $platform->id();
      $label = $platform->label();

      // Check if this is a multi-tool platform with enabled tools.
      $toolIds = $this->getEnabledToolIds($platform);

      // Build a list of "entries" — either one per tool or one for the whole
      // platform (single-tool).
      $entries = [];
      if (!empty($toolIds)) {
        foreach ($toolIds as $toolId) {
          $compositeKey = $platformId . '--' . $toolId;
          $toolLabel = $label . ' — ' . $this->getToolLabel($platform, $toolId);
          $entries[$compositeKey] = $toolLabel;
          $defaultPlatforms[] = $compositeKey;
        }
      }
      else {
        $entries[$platformId] = $label;
      }

      foreach ($entries as $entryKey => $entryLabel) {
        // Build description with publish history badge.
        $parts = [];
        $description = $platform->getDescription();
        if ($description) {
          $parts[] = $description;
        }
        $mode = $platform->isReviewMode() ? $this->t('review before sending') : $this->t('send immediately');
        $processing = $platform->getProcessingMode() === 'async' ? $this->t('queued') : $this->t('instant');
        $parts[] = '(' . $mode . ', ' . $processing . ')';

        // Show previous publish info if exists.
        if (!empty($publishHistory[$platformId])) {
          $lastPublish = $publishHistory[$platformId];
          $dateStr = $this->dateFormatter->format($lastPublish['created'], 'short');
          if ($lastPublish['status'] === 'success') {
            $badge = $this->t('⚠ Previously published: ✓ on @date', [
              '@date' => $dateStr,
            ]);
          }
          else {
            $badge = $this->t('⚠ Previous submission failed: ✗ on @date', [
              '@date' => $dateStr,
            ]);
          }
          $parts[] = '<br><strong>' . $badge . '</strong>';

          $resubmitBehavior = $platform->getResubmitBehavior();
          if ($resubmitBehavior === 'block') {
            $parts[] = '<br><em>' . $this->t('Re-submission requires confirmation on the review page.') . '</em>';
          }
          elseif ($resubmitBehavior === 'warn') {
            $parts[] = '<br><em>' . $this->t('Re-submitting will create a new post on this platform.') . '</em>';
          }
        }

        $form['platforms']['#options'][$entryKey] = $entryLabel;
        $form['platforms'][$entryKey]['#description'] = [
          '#markup' => implode(' ', $parts),
        ];
      }
    }

    // Pre-select all multi-tool entries by default.
    if (!empty($defaultPlatforms)) {
      $form['platforms']['#default_value'] = $defaultPlatforms;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()]),
      '#attributes' => [
        'class' => ['button', 'button--danger'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter($form_state->getValue('platforms', []));
    if (empty($selected)) {
      $form_state->setErrorByName('platforms', $this->t('Please select at least one platform.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = $form_state->getValue('nid');
    $selected = array_filter($form_state->getValue('platforms', []));
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      $this->messenger()->addError($this->t('Node not found.'));
      return;
    }

    // Parse composite keys (platformId--toolId) to group selections by
    // platform. Single-tool selections use just the platform ID.
    $platformToolSelections = [];
    foreach ($selected as $key) {
      if (str_contains($key, '--')) {
        [$platformId, $toolId] = explode('--', $key, 2);
        $platformToolSelections[$platformId][] = $toolId;
      }
      else {
        // Single-tool platform.
        $platformToolSelections[$key] = [NULL];
      }
    }

    $reviewPlatforms = [];
    $fireAndForgetPlatforms = [];
    $platformStorage = $this->entityTypeManager->getStorage('publishing_platform');

    foreach ($platformToolSelections as $platformId => $toolIds) {
      /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface|null $platform */
      $platform = $platformStorage->load($platformId);
      if (!$platform) {
        continue;
      }
      if ($platform->isReviewMode()) {
        $reviewPlatforms[$platformId] = $platform;
      }
      else {
        $fireAndForgetPlatforms[$platformId] = $platform;
      }
    }

    // Process fire-and-forget platforms immediately.
    $logLink = Link::fromTextAndUrl($this->t('View publishing log'), Url::fromRoute('iq_content_publishing.node_log', ['node' => $nid]))->toString();

    foreach ($fireAndForgetPlatforms as $platformId => $platform) {
      // Use only the tools the user selected for this platform.
      $toolIds = $platformToolSelections[$platformId] ?? [NULL];

      foreach ($toolIds as $toolId) {
        $toolLabel = $toolId !== NULL ? $platform->label() . ' (' . $toolId . ')' : $platform->label();

        $aiResult = $this->publishingManager->generateContent($node, $platform, $toolId);
        if ($aiResult->success) {
          $publishResult = $this->publishingManager->publish($node, $platform, $aiResult->fields, $toolId);
          if ($publishResult === NULL) {
            $this->messenger()->addStatus($this->t('@platform: queued for processing. @log_link', [
              '@platform' => $toolLabel,
              '@log_link' => $logLink,
            ]));
          }
          elseif ($publishResult->success) {
            $this->messenger()->addStatus($this->t('@platform: published successfully. @log_link', [
              '@platform' => $toolLabel,
              '@log_link' => $logLink,
            ]));
          }
          else {
            $this->messenger()->addError($this->t('@platform: failed — @message. @log_link', [
              '@platform' => $toolLabel,
              '@message' => $publishResult->message,
              '@log_link' => $logLink,
            ]));
          }
        }
        else {
          $this->messenger()->addError($this->t('@platform: AI generation failed — @error. @log_link', [
            '@platform' => $toolLabel,
            '@error' => $aiResult->error,
            '@log_link' => $logLink,
          ]));
        }
      }
    }

    // If there are review-mode platforms, redirect to the review form.
    if (!empty($reviewPlatforms)) {
      $tempStore = $this->tempStoreFactory->get('iq_content_publishing');
      $tempStore->set('review_platform_ids', array_keys($reviewPlatforms));

      // Store the selected tools per review platform so the review form
      // only generates content for tools the user picked.
      $reviewToolSelections = [];
      foreach ($reviewPlatforms as $platformId => $platform) {
        if (isset($platformToolSelections[$platformId])) {
          $reviewToolSelections[$platformId] = $platformToolSelections[$platformId];
        }
      }
      $tempStore->set('review_tool_selections', $reviewToolSelections);

      $form_state->setRedirectUrl(Url::fromRoute('iq_content_publishing.review', [
        'node' => $nid,
      ]));
    }
    else {
      // All done — redirect back to the node edit form.
      $form_state->setRedirectUrl(Url::fromRoute('entity.node.edit_form', [
        'node' => $nid,
      ]));
    }
  }

  /**
   * Gets the enabled tool IDs for a multi-tool platform.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   *
   * @return array
   *   Array of tool ID strings, or empty array if not a multi-tool platform
   *   or no tools are enabled.
   */
  protected function getEnabledToolIds($platform): array {
    try {
      $plugin = $this->pluginManager->createInstance($platform->getPluginId());
      if (!$plugin instanceof MultiToolPlatformInterface) {
        return [];
      }
    }
    catch (\Exception) {
      return [];
    }

    $pluginSettings = $platform->getPluginSettings();
    $toolsConfig = $pluginSettings['tools'] ?? [];

    if (empty($toolsConfig)) {
      return [];
    }

    return array_keys($toolsConfig);
  }

  /**
   * Gets the human-readable label for a tool.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   * @param string|int $toolId
   *   The tool identifier.
   *
   * @return string
   *   The tool label, or the tool ID as fallback.
   */
  protected function getToolLabel($platform, string|int $toolId): string {
    try {
      $plugin = $this->pluginManager->createInstance($platform->getPluginId());
      if ($plugin instanceof MultiToolPlatformInterface) {
        $tools = $plugin->getAvailableTools();
        if (isset($tools[(string) $toolId])) {
          return $tools[(string) $toolId]['name'];
        }
      }
    }
    catch (\Exception) {
      // Fall through.
    }
    return (string) $toolId;
  }

  /**
   * Gets the most recent successful publish for each platform for this node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface[] $platforms
   *   The available platforms.
   *
   * @return array
   *   Keyed by platform_id, each value has 'created', 'status', 'external_id'.
   */
  protected function getPublishHistory(NodeInterface $node, array $platforms): array {
    $history = [];
    $logStorage = $this->entityTypeManager->getStorage('publishing_log');

    foreach ($platforms as $platform) {
      $query = $logStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('nid', $node->id())
        ->condition('platform_id', $platform->id())
        ->sort('created', 'DESC')
        ->range(0, 1);
      $ids = $query->execute();

      if (!empty($ids)) {
        /** @var \Drupal\iq_content_publishing\Entity\PublishingLog|null $log */
        $log = $logStorage->load(reset($ids));
        if ($log) {
          $history[$platform->id()] = [
            'created' => (int) $log->get('created')->value,
            'status' => $log->get('status_code')->value,
            'external_id' => $log->get('external_id')->value ?? '',
            'external_url' => $log->get('external_url')->value ?? '',
          ];
        }
      }
    }

    return $history;
  }

}
