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
use Drupal\iq_content_publishing\Service\ContentPublishingManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for reviewing AI-generated content before publishing.
 *
 * Step 2 of the publishing workflow: displays AI-generated content
 * in editable textareas, allowing the editor to review and modify
 * before sending to each platform.
 */
final class PublishingReviewForm extends FormBase {

  public function __construct(
    protected ContentPublishingManager $publishingManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected DateFormatterInterface $dateFormatter,
    protected ContentPublishingPlatformManager $pluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('iq_content_publishing.manager'),
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('date.formatter'),
      $container->get('plugin.manager.content_publishing_platform'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'iq_content_publishing_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node) {
      return $form;
    }

    // Load platform IDs from form_state storage, tempstore, or submitted input.
    $platformIds = $form_state->get('platform_ids');
    if (!$platformIds) {
      $tempStore = $this->tempStoreFactory->get('iq_content_publishing');
      $platformIds = $tempStore->get('review_platform_ids') ?? [];
      if (!empty($platformIds)) {
        $tempStore->delete('review_platform_ids');
      }
      $form_state->set('platform_ids', $platformIds);
    }
    // Fallback: on form rebuild (POST), recover from the hidden field.
    if (empty($platformIds)) {
      $input = $form_state->getUserInput();
      if (!empty($input['platform_ids'])) {
        $platformIds = explode(',', $input['platform_ids']);
        $form_state->set('platform_ids', $platformIds);
      }
    }

    if (empty($platformIds)) {
      $this->messenger()->addWarning($this->t('No platforms selected. Please select platforms first.'));
      $url = Url::fromRoute('iq_content_publishing.select', ['node' => $node->id()]);
      $form['redirect_message'] = [
        '#markup' => '<p>' . $this->t('No platforms were selected for review. <a href="@url">Go back to platform selection</a>.', [
          '@url' => $url->toString(),
        ]) . '</p>',
      ];
      return $form;
    }

    $form['nid'] = [
      '#type' => 'hidden',
      '#value' => $node->id(),
    ];

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Review and edit the AI-generated content for "<strong>@title</strong>" before sending:', [
        '@title' => $node->getTitle(),
      ]) . '</p>',
    ];

    $platformStorage = $this->entityTypeManager->getStorage('publishing_platform');
    $logStorage = $this->entityTypeManager->getStorage('publishing_log');
    $generatedContents = $form_state->get('generated_contents') ?? [];

    $form['platforms'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($platformIds as $platformId) {
      /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface|null $platform */
      $platform = $platformStorage->load($platformId);
      if (!$platform) {
        continue;
      }

      // Get the output schema from the plugin.
      $outputSchema = [];
      try {
        $plugin = $this->pluginManager->createInstance($platform->getPluginId());
        $outputSchema = $plugin->getOutputSchema();
      }
      catch (\Exception) {
        // Fall back to default single text field.
        $outputSchema = [
          'text' => [
            'type' => 'textarea',
            'label' => $this->t('Post text'),
            'required' => TRUE,
            'ai_generated' => TRUE,
          ],
        ];
      }

      // Generate content if not already cached in form state.
      if (!isset($generatedContents[$platformId])) {
        $aiResult = $this->publishingManager->generateContent($node, $platform);
        $generatedContents[$platformId] = [
          'fields' => $aiResult->success ? $aiResult->fields : [],
          'error' => $aiResult->error,
          'prompt' => $aiResult->prompt,
        ];
      }

      $form_state->set('generated_contents', $generatedContents);

      $form['platforms'][$platformId] = [
        '#type' => 'details',
        '#title' => $platform->label(),
        '#open' => TRUE,
      ];

      // Check for previous publish and show re-submit warnings.
      $lastLog = $this->getLastLog($logStorage, $node, $platformId);
      if ($lastLog) {
        $dateStr = $this->dateFormatter->format((int) $lastLog->get('created')->value, 'short');
        $resubmitBehavior = $platform->getResubmitBehavior();

        if ($resubmitBehavior === 'warn' || $resubmitBehavior === 'block') {
          $form['platforms'][$platformId]['resubmit_warning'] = [
            '#markup' => '<div class="messages messages--warning"><strong>' .
              $this->t('This content was already published to @platform on @date. Publishing again will create a new post on the external platform.', [
                '@platform' => $platform->label(),
                '@date' => $dateStr,
              ]) . '</strong></div>',
            '#weight' => -10,
          ];
        }

        if ($resubmitBehavior === 'block') {
          $form['platforms'][$platformId]['confirm_resubmit'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('I understand this will create a duplicate post on @platform and want to proceed.', [
              '@platform' => $platform->label(),
            ]),
            '#default_value' => FALSE,
            '#weight' => -5,
          ];
        }
      }

      if (!empty($generatedContents[$platformId]['error'])) {
        $form['platforms'][$platformId]['error'] = [
          '#markup' => '<p class="messages messages--error">' . $this->t('AI generation failed: @error', [
            '@error' => $generatedContents[$platformId]['error'],
          ]) . '</p>',
        ];
      }

      // Build per-field widgets based on the output schema.
      $form['platforms'][$platformId]['fields'] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];

      $currentFields = $generatedContents[$platformId]['fields'] ?? [];

      foreach ($outputSchema as $fieldName => $fieldDef) {
        $fieldType = $fieldDef['type'] ?? 'textfield';
        $fieldLabel = $fieldDef['label'] ?? $fieldName;
        $fieldValue = $currentFields[$fieldName] ?? '';

        switch ($fieldType) {
          case 'textarea':
            $form['platforms'][$platformId]['fields'][$fieldName] = [
              '#type' => 'textarea',
              '#title' => $fieldLabel,
              '#default_value' => is_string($fieldValue) ? $fieldValue : '',
              '#rows' => 6,
              '#description' => $fieldDef['description'] ?? '',
            ];
            if (!empty($fieldDef['max_length'])) {
              $form['platforms'][$platformId]['fields'][$fieldName]['#attributes']['maxlength'] = $fieldDef['max_length'];
              $form['platforms'][$platformId]['fields'][$fieldName]['#description'] .=
                ' ' . $this->t('(max @count characters)', ['@count' => $fieldDef['max_length']]);
            }
            break;

          case 'textfield':
            $form['platforms'][$platformId]['fields'][$fieldName] = [
              '#type' => 'textfield',
              '#title' => $fieldLabel,
              '#default_value' => is_string($fieldValue) ? $fieldValue : '',
              '#description' => $fieldDef['description'] ?? '',
              '#maxlength' => $fieldDef['max_length'] ?? 255,
            ];
            break;

          case 'url':
            $form['platforms'][$platformId]['fields'][$fieldName] = [
              '#type' => 'url',
              '#title' => $fieldLabel,
              '#default_value' => is_string($fieldValue) ? $fieldValue : '',
              '#description' => $fieldDef['description'] ?? '',
            ];
            break;

          case 'image':
            // Image fields show available images from the node with checkboxes/radios.
            $maxImages = $fieldDef['max'] ?? 0;
            $imageOptions = [];
            $defaultImages = [];

            if (is_array($fieldValue)) {
              foreach ($fieldValue as $idx => $imageData) {
                if (!is_array($imageData) || empty($imageData['url'])) {
                  continue;
                }
                $optionKey = (string) ($imageData['fid'] ?? $idx);
                $filename = htmlspecialchars($imageData['filename'] ?? 'Image', ENT_QUOTES);
                $alt = htmlspecialchars($imageData['alt'] ?? '', ENT_QUOTES);

                // Use the thumbnail image style if the file has a Drupal URI.
                $thumbnailUrl = $imageData['url'];
                if (!empty($imageData['uri'])) {
                  /** @var \Drupal\image\ImageStyleInterface|null $imageStyle */
                  $imageStyle = $this->entityTypeManager->getStorage('image_style')->load('thumbnail');
                  if ($imageStyle) {
                    $thumbnailUrl = $imageStyle->buildUrl($imageData['uri']);
                  }
                }

                $imgTag = '<div style="display:inline-block;text-align:center;margin:4px 8px 4px 0;vertical-align:top;">'
                  . '<img src="' . htmlspecialchars($thumbnailUrl, ENT_QUOTES) . '" '
                  . 'alt="' . $alt . '" '
                  . 'style="max-width:100px;max-height:100px;display:block;margin-bottom:4px;">'
                  . '<small>' . $filename . '</small>'
                  . '</div>';
                $imageOptions[$optionKey] = $imgTag;
                $defaultImages[] = $optionKey;
              }
            }

            if (!empty($imageOptions)) {
              if ($maxImages === 1) {
                // Single image: radios.
                $form['platforms'][$platformId]['fields'][$fieldName] = [
                  '#type' => 'radios',
                  '#title' => $fieldLabel,
                  '#options' => $imageOptions + ['_none' => $this->t('No image')],
                  '#default_value' => !empty($defaultImages) ? reset($defaultImages) : '_none',
                  '#description' => $fieldDef['description'] ?? '',
                ];
              }
              else {
                // Multiple images: checkboxes.
                $form['platforms'][$platformId]['fields'][$fieldName] = [
                  '#type' => 'checkboxes',
                  '#title' => $fieldLabel,
                  '#options' => $imageOptions,
                  '#default_value' => $defaultImages,
                  '#description' => $fieldDef['description'] ?? '',
                ];
              }
            }
            else {
              $form['platforms'][$platformId]['fields'][$fieldName] = [
                '#type' => 'item',
                '#title' => $fieldLabel,
                '#markup' => '<em>' . $this->t('No images available from this content.') . '</em>',
              ];
            }
            break;
        }
      }

      $form['platforms'][$platformId]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Publish to @platform', ['@platform' => $platform->label()]),
        '#default_value' => !empty($currentFields),
      ];
    }

    $form['platform_ids'] = [
      '#type' => 'hidden',
      '#value' => implode(',', $platformIds),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send to platforms'),
      '#button_type' => 'primary',
    ];

    $form['actions']['regenerate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Regenerate'),
      '#limit_validation_errors' => [],
      '#submit' => ['::regenerateSubmit'],
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
    parent::validateForm($form, $form_state);

    // Validate that 'block' platforms have confirmation checked.
    $platformIds = explode(',', $form_state->getValue('platform_ids') ?? '');
    $platforms = $form_state->getValue('platforms', []);
    $platformStorage = $this->entityTypeManager->getStorage('publishing_platform');

    foreach ($platformIds as $platformId) {
      if (empty($platforms[$platformId]['enabled'])) {
        continue;
      }
      /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface|null $platform */
      $platform = $platformStorage->load($platformId);
      if (!$platform) {
        continue;
      }
      // If platform has 'block' behavior and this is a re-submission,
      // require the confirmation checkbox.
      if ($platform->getResubmitBehavior() === 'block'
          && isset($platforms[$platformId]['confirm_resubmit'])
          && empty($platforms[$platformId]['confirm_resubmit'])) {
        $form_state->setErrorByName(
          "platforms][$platformId][confirm_resubmit",
          $this->t('@platform: You must confirm re-submission to proceed.', [
            '@platform' => $platform->label(),
          ]),
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = $form_state->getValue('nid');
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      $this->messenger()->addError($this->t('Node not found.'));
      return;
    }

    $platformIds = explode(',', $form_state->getValue('platform_ids'));
    $platforms = $form_state->getValue('platforms', []);
    $platformStorage = $this->entityTypeManager->getStorage('publishing_platform');
    $generatedContents = $form_state->get('generated_contents') ?? [];
    $logLink = Link::fromTextAndUrl($this->t('View publishing log'), Url::fromRoute('iq_content_publishing.node_log', ['node' => $nid]))->toString();

    foreach ($platformIds as $platformId) {
      if (empty($platforms[$platformId]['enabled'])) {
        continue;
      }

      /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface|null $platform */
      $platform = $platformStorage->load($platformId);
      if (!$platform) {
        continue;
      }

      // Collect structured fields from the form submission.
      $submittedFields = $platforms[$platformId]['fields'] ?? [];
      $generatedFields = $generatedContents[$platformId]['fields'] ?? [];

      // Get the output schema to properly process each field type.
      $outputSchema = [];
      try {
        $plugin = $this->pluginManager->createInstance($platform->getPluginId());
        $outputSchema = $plugin->getOutputSchema();
      }
      catch (\Exception) {
        // Continue with raw submitted fields.
      }

      $fields = $this->collectFieldsFromSubmission($submittedFields, $generatedFields, $outputSchema);

      // Check if we have any content to publish.
      $hasContent = FALSE;
      foreach ($fields as $value) {
        if (!empty($value)) {
          $hasContent = TRUE;
          break;
        }
      }

      if (!$hasContent) {
        $this->messenger()->addWarning($this->t('@platform: skipped (no content).', [
          '@platform' => $platform->label(),
        ]));
        continue;
      }

      $publishResult = $this->publishingManager->publish($node, $platform, $fields);

      if ($publishResult === NULL) {
        $this->messenger()->addStatus($this->t('@platform: queued for processing. @log_link', [
          '@platform' => $platform->label(),
          '@log_link' => $logLink,
        ]));
      }
      elseif ($publishResult->success) {
        $this->messenger()->addStatus($this->t('@platform: published successfully. @log_link', [
          '@platform' => $platform->label(),
          '@log_link' => $logLink,
        ]));
      }
      else {
        $this->messenger()->addError($this->t('@platform: failed — @message. @log_link', [
          '@platform' => $platform->label(),
          '@message' => $publishResult->message,
          '@log_link' => $logLink,
        ]));
      }
    }

    // Redirect back to the node edit form.
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.edit_form', [
      'node' => $nid,
    ]));
  }

  /**
   * Collects structured fields from the form submission.
   *
   * Merges user-edited text fields with programmatic fields (images).
   * For image fields, resolves selected file IDs back to image data arrays.
   *
   * @param array $submittedFields
   *   The raw form-submitted field values.
   * @param array $generatedFields
   *   The original generated fields (for image data lookup).
   * @param array $outputSchema
   *   The platform output schema.
   *
   * @return array
   *   The structured fields ready for publishing.
   */
  protected function collectFieldsFromSubmission(array $submittedFields, array $generatedFields, array $outputSchema): array {
    $fields = [];

    foreach ($outputSchema as $fieldName => $fieldDef) {
      $fieldType = $fieldDef['type'] ?? 'textfield';

      switch ($fieldType) {
        case 'image':
          // Resolve selected images back to full image data.
          $selectedValue = $submittedFields[$fieldName] ?? [];
          $availableImages = is_array($generatedFields[$fieldName] ?? NULL) ? $generatedFields[$fieldName] : [];

          if (is_string($selectedValue)) {
            // Radios return a single string value.
            if ($selectedValue === '_none' || $selectedValue === '') {
              $fields[$fieldName] = [];
            }
            else {
              $fields[$fieldName] = $this->resolveSelectedImages([$selectedValue], $availableImages);
            }
          }
          else {
            // Checkboxes return an array.
            $selected = array_filter((array) $selectedValue);
            $fields[$fieldName] = $this->resolveSelectedImages(array_keys($selected), $availableImages);
          }
          break;

        default:
          $fields[$fieldName] = $submittedFields[$fieldName] ?? '';
          break;
      }
    }

    return $fields;
  }

  /**
   * Resolves selected image IDs back to full image data arrays.
   *
   * @param array $selectedIds
   *   The selected file IDs (as strings).
   * @param array $availableImages
   *   The available image data arrays from generation.
   *
   * @return array
   *   Array of image data arrays for the selected images.
   */
  protected function resolveSelectedImages(array $selectedIds, array $availableImages): array {
    $resolved = [];
    foreach ($availableImages as $idx => $imageData) {
      if (!is_array($imageData)) {
        continue;
      }
      $fid = (string) ($imageData['fid'] ?? $idx);
      if (in_array($fid, $selectedIds, TRUE)) {
        $resolved[] = $imageData;
      }
    }
    return $resolved;
  }

  /**
   * Submit handler for the regenerate button.
   */
  public function regenerateSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('generated_contents', NULL);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Gets the most recent log entry for a node + platform combination.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $logStorage
   *   The log entity storage.
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $platformId
   *   The platform config entity ID.
   *
   * @return \Drupal\iq_content_publishing\Entity\PublishingLog|null
   *   The most recent log entry, or NULL if none found.
   */
  protected function getLastLog($logStorage, NodeInterface $node, string $platformId) {
    $ids = $logStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $node->id())
      ->condition('platform_id', $platformId)
      ->condition('status_code', 'success')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    return !empty($ids) ? $logStorage->load(reset($ids)) : NULL;
  }

}
