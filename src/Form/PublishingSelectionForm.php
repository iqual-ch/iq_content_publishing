<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface;
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
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('iq_content_publishing.manager'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
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

    foreach ($platforms as $platform) {
      $platformId = $platform->id();
      $label = $platform->label();

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
        $statusEmoji = $lastPublish['status'] === 'success' ? '✓' : '✗';
        $badge = $this->t('⚠ Previously published: @status on @date', [
          '@status' => $statusEmoji,
          '@date' => $dateStr,
        ]);
        $parts[] = '<br><strong>' . $badge . '</strong>';

        $resubmitBehavior = $platform->getResubmitBehavior();
        if ($resubmitBehavior === 'block') {
          $parts[] = '<br><em>' . $this->t('Re-submission requires confirmation on the review page.') . '</em>';
        }
        elseif ($resubmitBehavior === 'warn') {
          $parts[] = '<br><em>' . $this->t('Re-submitting will create a new post on this platform.') . '</em>';
        }
      }

      $form['platforms']['#options'][$platformId] = $label;
      $form['platforms'][$platformId]['#description'] = [
        '#markup' => implode(' ', $parts),
      ];
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
      '#attributes' => ['class' => ['button']],
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

    $reviewPlatforms = [];
    $fireAndForgetPlatforms = [];
    $platformStorage = $this->entityTypeManager->getStorage('publishing_platform');

    foreach ($selected as $platformId) {
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
    foreach ($fireAndForgetPlatforms as $platform) {
      $aiResult = $this->publishingManager->generateContent($node, $platform);
      if ($aiResult->success) {
        $publishResult = $this->publishingManager->publish($node, $platform, $aiResult->fields);
        if ($publishResult === NULL) {
          $this->messenger()->addStatus($this->t('@platform: queued for processing.', [
            '@platform' => $platform->label(),
          ]));
        }
        elseif ($publishResult->success) {
          $this->messenger()->addStatus($this->t('@platform: published successfully.', [
            '@platform' => $platform->label(),
          ]));
        }
        else {
          $this->messenger()->addError($this->t('@platform: failed — @message', [
            '@platform' => $platform->label(),
            '@message' => $publishResult->message,
          ]));
        }
      }
      else {
        $this->messenger()->addError($this->t('@platform: AI generation failed — @error', [
          '@platform' => $platform->label(),
          '@error' => $aiResult->error,
        ]));
      }
    }

    // If there are review-mode platforms, redirect to the review form.
    if (!empty($reviewPlatforms)) {
      $tempStore = \Drupal::service('tempstore.private')->get('iq_content_publishing');
      $tempStore->set('review_platform_ids', array_keys($reviewPlatforms));

      $form_state->setRedirectUrl(Url::fromRoute('iq_content_publishing.review', [
        'node' => $nid,
      ]));
    }
    else {
      // All done — redirect back to the node.
      $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', [
        'node' => $nid,
      ]));
    }
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
