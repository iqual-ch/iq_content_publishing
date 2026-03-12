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

    // Load platform IDs and tool selections from form_state, tempstore, or input.
    $platformIds = $form_state->get('platform_ids');
    $toolSelections = $form_state->get('tool_selections');
    if (!$platformIds) {
      $tempStore = $this->tempStoreFactory->get('iq_content_publishing');
      $platformIds = $tempStore->get('review_platform_ids') ?? [];
      $toolSelections = $tempStore->get('review_tool_selections') ?? [];
      if (!empty($platformIds)) {
        $tempStore->delete('review_platform_ids');
        $tempStore->delete('review_tool_selections');
      }
      $form_state->set('platform_ids', $platformIds);
      $form_state->set('tool_selections', $toolSelections);
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
      $url = Url::fromRoute('iq_content_publishing.select_platforms', ['node' => $node->id()]);
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

    $form['#attached']['library'][] = 'iq_content_publishing/review_form';

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

      // Determine tool IDs for this platform (multi-tool support).
      // Prefer the user's selection from the selection form over all enabled
      // tools so that only chosen tools are generated and reviewed.
      if (!empty($toolSelections[$platformId])) {
        $toolIds = $toolSelections[$platformId];
        // Filter out NULL entries that represent single-tool platforms.
        $toolIds = array_values(array_filter($toolIds, fn ($id) => $id !== NULL));
      }
      else {
        $toolIds = $this->getEnabledToolIds($platform);
      }
      if (empty($toolIds)) {
        // Single-tool platform — use NULL as the tool ID.
        $toolIds = [NULL];
      }

      foreach ($toolIds as $toolId) {
        // Build a composite key for form structure and generated content.
        $entryKey = $toolId !== NULL ? $platformId . '--' . $toolId : $platformId;
        $entryLabel = $platform->label();
        if ($toolId !== NULL) {
          $entryLabel .= ' — ' . $this->getToolLabel($platform, $toolId);
        }

        // Get the output schema from the plugin (tool-specific or default).
        $outputSchema = [];
        try {
          /** @var \Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformInterface $plugin */
          $plugin = $this->pluginManager->createInstance($platform->getPluginId());
          if ($toolId !== NULL && $plugin instanceof MultiToolPlatformInterface) {
            $toolSchema = $plugin->getOutputSchemaForTool($toolId);
            $outputSchema = !empty($toolSchema) ? $toolSchema : $plugin->getOutputSchema();
          }
          else {
            $outputSchema = $plugin->getOutputSchema();
          }
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
        if (!isset($generatedContents[$entryKey])) {
          $aiResult = $this->publishingManager->generateContent($node, $platform, $toolId);
          $generatedContents[$entryKey] = [
            'fields' => $aiResult->success ? $aiResult->fields : [],
            'error' => $aiResult->error,
            'prompt' => $aiResult->prompt,
            'platform_id' => $platformId,
            'tool_id' => $toolId,
          ];
        }

        $form_state->set('generated_contents', $generatedContents);

        $form['platforms'][$entryKey] = [
          '#type' => 'details',
          '#title' => $entryLabel,
          '#open' => TRUE,
        ];

      // Check for previous publish and show re-submit warnings.
      $lastLog = $this->getLastLog($logStorage, $node, $platformId, $toolId);
      if ($lastLog) {
        $dateStr = $this->dateFormatter->format((int) $lastLog->get('created')->value, 'short');
        $resubmitBehavior = $platform->getResubmitBehavior();

        if ($resubmitBehavior === 'warn' || $resubmitBehavior === 'block') {
          $form['platforms'][$entryKey]['resubmit_warning'] = [
            '#markup' => '<div class="messages messages--warning"><strong>' .
              $this->t('This content was already published to @platform on @date. Publishing again will create a new post on the external platform.', [
                '@platform' => $entryLabel,
                '@date' => $dateStr,
              ]) . '</strong></div>',
            '#weight' => -10,
          ];
        }

        if ($resubmitBehavior === 'block') {
          $form['platforms'][$entryKey]['confirm_resubmit'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('I understand this will create a duplicate post on @platform and want to proceed.', [
              '@platform' => $entryLabel,
            ]),
            '#default_value' => FALSE,
            '#weight' => -5,
          ];
        }
      }

      if (!empty($generatedContents[$entryKey]['error'])) {
        $form['platforms'][$entryKey]['error'] = [
          '#markup' => '<p class="messages messages--error">' . $this->t('AI generation failed: @error', [
            '@error' => $generatedContents[$entryKey]['error'],
          ]) . '</p>',
        ];
      }

      // Build per-field widgets based on the output schema.
      $form['platforms'][$entryKey]['fields'] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];

      $currentFields = $generatedContents[$entryKey]['fields'] ?? [];

      foreach ($outputSchema as $fieldName => $fieldDef) {
        // If $fieldDef is already a form field definition, use it directly.
        if (isset($fieldDef['#type'])) {
          $form['platforms'][$entryKey]['fields'][$fieldName] = $fieldDef;
          continue;
        }

        // Else build a form field based on the schema definition.
        $fieldType = $fieldDef['type'] ?? 'textfield';
        $fieldLabel = $fieldDef['label'] ?? $fieldName;
        $fieldValue = $currentFields[$fieldName] ?? '';

        switch ($fieldType) {
          case 'textarea':
            $form['platforms'][$entryKey]['fields'][$fieldName] = [
              '#type' => 'textarea',
              '#title' => $fieldLabel,
              '#default_value' => is_string($fieldValue) ? $fieldValue : '',
              '#rows' => 6,
              '#description' => $fieldDef['description'] ?? '',
            ];
            if (!empty($fieldDef['max_length'])) {
              $form['platforms'][$entryKey]['fields'][$fieldName]['#attributes']['maxlength'] = $fieldDef['max_length'];
              $form['platforms'][$entryKey]['fields'][$fieldName]['#description'] .=
                ' ' . $this->t('(max @count characters)', ['@count' => $fieldDef['max_length']]);
            }
            break;

          case 'textfield':
            $form['platforms'][$entryKey]['fields'][$fieldName] = [
              '#type' => 'textfield',
              '#title' => $fieldLabel,
              '#default_value' => is_string($fieldValue) ? $fieldValue : '',
              '#description' => $fieldDef['description'] ?? '',
              '#maxlength' => $fieldDef['max_length'] ?? 255,
            ];
            break;

          case 'url':
            $form['platforms'][$entryKey]['fields'][$fieldName] = [
              '#type' => 'url',
              '#title' => $fieldLabel,
              '#default_value' => is_string($fieldValue) ? $fieldValue : '',
              '#description' => $fieldDef['description'] ?? '',
            ];
            break;

          case 'text_format':
          case 'html_text':
            $form['platforms'][$entryKey]['fields'][$fieldName] = [
              '#type' => 'text_format',
              '#title' => $fieldLabel,
              '#default_value' => is_string($fieldValue) ? $fieldValue : '',
              '#format' => $fieldDef['format'] ?? 'basic_html',
              '#rows' => $fieldDef['rows'] ?? 6,
              '#description' => $fieldDef['description'] ?? '',
            ];
            if (!empty($fieldDef['allowed_formats'])) {
              $form['platforms'][$entryKey]['fields'][$fieldName]['#allowed_formats'] = $fieldDef['allowed_formats'];
            }
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
                $optionKey = $imageData['id'] ?? (string) $idx;
                $alt = htmlspecialchars($imageData['alt'] ?? '', ENT_QUOTES);

                // Use the medium image style for the preview.
                $previewUrl = $this->buildPreviewImageUrl($imageData);

                $imgTag = '<img src="' . htmlspecialchars($previewUrl, ENT_QUOTES) . '" '
                  . 'alt="' . $alt . '">';
                $imageOptions[$optionKey] = $imgTag;
                $defaultImages[] = $optionKey;
              }
            }

            if (!empty($imageOptions)) {
              $wrapper_prefix = '<div class="iq-cp-image-selector">';
              $wrapper_suffix = '</div>';

              if ($maxImages === 1) {
                // Single image: radios.
                $form['platforms'][$entryKey]['fields'][$fieldName] = [
                  '#type' => 'radios',
                  '#title' => $fieldLabel,
                  '#options' => $imageOptions + ['_none' => $this->t('No image')],
                  '#default_value' => !empty($defaultImages) ? reset($defaultImages) : '_none',
                  '#description' => $fieldDef['description'] ?? '',
                  '#prefix' => $wrapper_prefix,
                  '#suffix' => $wrapper_suffix,
                ];
              }
              else {
                // Multiple images: checkboxes.
                $form['platforms'][$entryKey]['fields'][$fieldName] = [
                  '#type' => 'checkboxes',
                  '#title' => $fieldLabel,
                  '#options' => $imageOptions,
                  '#default_value' => $defaultImages,
                  '#description' => $fieldDef['description'] ?? '',
                  '#prefix' => $wrapper_prefix,
                  '#suffix' => $wrapper_suffix,
                ];
              }
            }
            else {
              $form['platforms'][$entryKey]['fields'][$fieldName] = [
                '#type' => 'item',
                '#title' => $fieldLabel,
                '#markup' => '<em>' . $this->t('No images available from this content.') . '</em>',
              ];
            }
            break;

          case 'video':
            // Video fields show available videos from the node with checkboxes/radios.
            $maxVideos = $fieldDef['max'] ?? 0;
            $videoOptions = [];
            $defaultVideos = [];

            if (is_array($fieldValue)) {
              foreach ($fieldValue as $idx => $videoData) {
                if (!is_array($videoData) || empty($videoData['url'])) {
                  continue;
                }
                $optionKey = $videoData['id'] ?? (string) $idx;
                $sourceLabel = ucfirst($videoData['source'] ?? 'video');
                $filename = htmlspecialchars($videoData['filename'] ?? '', ENT_QUOTES);
                $videoUrl = htmlspecialchars($videoData['url'], ENT_QUOTES);

                // Build a preview: thumbnail image if available, otherwise a text label.
                $thumbnailUrl = $videoData['thumbnail'] ?? '';
                if (!empty($thumbnailUrl)) {
                  $preview = '<img src="' . htmlspecialchars($thumbnailUrl, ENT_QUOTES) . '" '
                    . 'alt="' . $sourceLabel . '" style="max-width:200px;max-height:150px;">';
                }
                else {
                  $preview = '<span class="iq-cp-video-icon">&#9654;</span>';
                }

                $label = $preview . '<br><small>' . $sourceLabel;
                if (!empty($filename)) {
                  $label .= ': ' . $filename;
                }
                $label .= '</small><br><small>' . $videoUrl . '</small>';
                $videoOptions[$optionKey] = $label;
                $defaultVideos[] = $optionKey;
              }
            }

            if (!empty($videoOptions)) {
              $wrapper_prefix = '<div class="iq-cp-video-selector">';
              $wrapper_suffix = '</div>';

              if ($maxVideos === 1) {
                // Single video: radios.
                $form['platforms'][$entryKey]['fields'][$fieldName] = [
                  '#type' => 'radios',
                  '#title' => $fieldLabel,
                  '#options' => $videoOptions + ['_none' => $this->t('No video')],
                  '#default_value' => !empty($defaultVideos) ? reset($defaultVideos) : '_none',
                  '#description' => $fieldDef['description'] ?? '',
                  '#prefix' => $wrapper_prefix,
                  '#suffix' => $wrapper_suffix,
                ];
              }
              else {
                // Multiple videos: checkboxes.
                $form['platforms'][$entryKey]['fields'][$fieldName] = [
                  '#type' => 'checkboxes',
                  '#title' => $fieldLabel,
                  '#options' => $videoOptions,
                  '#default_value' => $defaultVideos,
                  '#description' => $fieldDef['description'] ?? '',
                  '#prefix' => $wrapper_prefix,
                  '#suffix' => $wrapper_suffix,
                ];
              }
            }
            else {
              $form['platforms'][$entryKey]['fields'][$fieldName] = [
                '#type' => 'item',
                '#title' => $fieldLabel,
                '#markup' => '<em>' . $this->t('No videos available from this content.') . '</em>',
              ];
            }
            break;

          case 'hidden':
            // Hidden fields are not shown in the form but are included in the data.
            $form['platforms'][$entryKey]['fields'][$fieldName] = [
              '#type' => 'hidden',
              '#value' => is_string($fieldValue) ? $fieldValue : '',
            ];
            break;

          default:
            // Unknown field type, do nothing or optionally log a warning.
            $form['platforms'][$entryKey]['fields'][$fieldName] = [
              '#type' => 'item',
              '#title' => $fieldLabel,
              '#markup' => '<em>' . $this->t('Unsupported field type: @type', ['@type' => $fieldType]) . '</em>',
            ];

            break;
        }
      }

      $form['platforms'][$entryKey]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Publish to @platform', ['@platform' => $entryLabel]),
        '#default_value' => !empty($currentFields),
      ];
      } // End foreach toolIds.
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
    $platforms = $form_state->getValue('platforms', []);
    $platformStorage = $this->entityTypeManager->getStorage('publishing_platform');

    foreach ($platforms as $entryKey => $entryValues) {
      if (empty($entryValues['enabled'])) {
        continue;
      }

      // Extract platform ID from composite key (platformId--toolId).
      $platformId = str_contains((string) $entryKey, '--')
        ? strstr((string) $entryKey, '--', TRUE)
        : (string) $entryKey;

      /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface|null $platform */
      $platform = $platformStorage->load($platformId);
      if (!$platform) {
        continue;
      }
      // If platform has 'block' behavior and this is a re-submission,
      // require the confirmation checkbox.
      if ($platform->getResubmitBehavior() === 'block'
          && isset($entryValues['confirm_resubmit'])
          && empty($entryValues['confirm_resubmit'])) {
        $form_state->setErrorByName(
          "platforms][$entryKey][confirm_resubmit",
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

    // Iterate all form entries (which can be platformId or platformId--toolId).
    foreach ($platforms as $entryKey => $entryValues) {
      if (empty($entryValues['enabled'])) {
        continue;
      }

      // Parse composite key to extract platformId and toolId.
      $entryKeyStr = (string) $entryKey;
      if (str_contains($entryKeyStr, '--')) {
        $platformId = strstr($entryKeyStr, '--', TRUE);
        $toolId = substr(strstr($entryKeyStr, '--'), 2);
      }
      else {
        $platformId = $entryKeyStr;
        $toolId = NULL;
      }

      /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface|null $platform */
      $platform = $platformStorage->load($platformId);
      if (!$platform) {
        continue;
      }

      $entryLabel = $platform->label();
      if ($toolId !== NULL) {
        $entryLabel .= ' — ' . $this->getToolLabel($platform, $toolId);
      }

      // Collect structured fields from the form submission.
      $submittedFields = $entryValues['fields'] ?? [];
      $generatedFields = $generatedContents[$entryKeyStr]['fields'] ?? [];

      // Get the output schema to properly process each field type.
      $outputSchema = [];
      try {
        /** @var \Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformInterface $plugin */
        $plugin = $this->pluginManager->createInstance($platform->getPluginId());
        if ($toolId !== NULL && $plugin instanceof MultiToolPlatformInterface) {
          $toolSchema = $plugin->getOutputSchemaForTool($toolId);
          $outputSchema = !empty($toolSchema) ? $toolSchema : $plugin->getOutputSchema();
        }
        else {
          $outputSchema = $plugin->getOutputSchema();
        }
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
          '@platform' => $entryLabel,
        ]));
        continue;
      }

      $publishResult = $this->publishingManager->publish($node, $platform, $fields, $toolId);

      if ($publishResult === NULL) {
        $this->messenger()->addStatus($this->t('@platform: queued for processing. @log_link', [
          '@platform' => $entryLabel,
          '@log_link' => $logLink,
        ]));
      }
      elseif ($publishResult->success) {
        $this->messenger()->addStatus($this->t('@platform: published successfully. @log_link', [
          '@platform' => $entryLabel,
          '@log_link' => $logLink,
        ]));
      }
      else {
        $this->messenger()->addError($this->t('@platform: failed — @message. @log_link', [
          '@platform' => $entryLabel,
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
   * Merges user-edited text fields with programmatic fields (images, videos).
   * For image/video fields, resolves selected IDs back to data arrays.
   *
   * @param array $submittedFields
   *   The raw form-submitted field values.
   * @param array $generatedFields
   *   The original generated fields (for image/video data lookup).
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

        case 'video':
          // Resolve selected videos back to full video data.
          $selectedValue = $submittedFields[$fieldName] ?? [];
          $availableVideos = is_array($generatedFields[$fieldName] ?? NULL) ? $generatedFields[$fieldName] : [];

          if (is_string($selectedValue)) {
            // Radios return a single string value.
            if ($selectedValue === '_none' || $selectedValue === '') {
              $fields[$fieldName] = [];
            }
            else {
              $fields[$fieldName] = $this->resolveSelectedVideos([$selectedValue], $availableVideos);
            }
          }
          else {
            // Checkboxes return an array.
            $selected = array_filter((array) $selectedValue);
            $fields[$fieldName] = $this->resolveSelectedVideos(array_keys($selected), $availableVideos);
          }
          break;

        case 'text_format':
        case 'html_text':
          // text_format fields submit as ['value' => ..., 'format' => ...].
          $rawValue = $submittedFields[$fieldName] ?? '';
          if (is_array($rawValue)) {
            $fields[$fieldName] = $rawValue['value'] ?? '';
          }
          else {
            $fields[$fieldName] = $rawValue;
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
      $id = $imageData['id'] ?? (string) $idx;
      if (in_array($id, $selectedIds, TRUE)) {
        $resolved[] = $imageData;
      }
    }
    return $resolved;
  }

  /**
   * Resolves selected video IDs back to full video data arrays.
   *
   * @param array $selectedIds
   *   The selected video IDs (as strings).
   * @param array $availableVideos
   *   The available video data arrays from generation.
   *
   * @return array
   *   Array of video data arrays for the selected videos.
   */
  protected function resolveSelectedVideos(array $selectedIds, array $availableVideos): array {
    $resolved = [];
    foreach ($availableVideos as $idx => $videoData) {
      if (!is_array($videoData)) {
        continue;
      }
      $id = $videoData['id'] ?? (string) $idx;
      if (in_array($id, $selectedIds, TRUE)) {
        $resolved[] = $videoData;
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
   * Gets the enabled tool IDs for a multi-tool platform.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform
   *   The platform config entity.
   *
   * @return array
   *   Array of tool ID strings, or empty array if not multi-tool.
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

    return !empty($toolsConfig) ? array_keys($toolsConfig) : [];
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
        $tools = $plugin->getAvailableTools($platform->getPluginSettings());
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
   * Gets the most recent log entry for a node + platform combination.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $logStorage
   *   The log entity storage.
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $platformId
   *   The platform config entity ID.
   * @param string|int|null $toolId
   *   The tool identifier for multi-tool platforms, or NULL.
   *
   * @return \Drupal\iq_content_publishing\Entity\PublishingLog|null
   *   The most recent log entry, or NULL if none found.
   */
  protected function getLastLog($logStorage, NodeInterface $node, string $platformId, string|int|null $toolId = NULL) {
    $query = $logStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $node->id())
      ->condition('platform_id', $platformId)
      ->condition('status_code', 'success')
      ->sort('created', 'DESC')
      ->range(0, 1);

    if ($toolId !== NULL) {
      $query->condition('tool_id', (string) $toolId);
    }

    $ids = $query->execute();

    return !empty($ids) ? $logStorage->load(reset($ids)) : NULL;
  }

  /**
   * Builds a preview URL for an image, using the 'medium' image style.
   *
   * Resolves the file URI from several sources:
   * 1. The 'uri' key if already set (file entity was resolved).
   * 2. The 'url' key if it contains /files/ (derive URI from URL path).
   * 3. Falls back to the original URL as-is (external images).
   *
   * @param array $imageData
   *   Image data array with 'url' and optionally 'uri'.
   *
   * @return string
   *   The image style URL.
   */
  protected function buildPreviewImageUrl(array $imageData): string {
    $uri = $imageData['uri'] ?? '';

    // If no URI, try to derive it from the URL (handles image-styled URLs).
    if (empty($uri) && !empty($imageData['url'])) {
      $url = $imageData['url'];
      if (preg_match('#/files/(.+?)(?:\?|$)#', $url, $matches)) {
        $relativePath = urldecode($matches[1]);
        // Strip existing image style path: styles/STYLE_NAME/public/...
        if (preg_match('#^styles/[^/]+/public/(.+)$#', $relativePath, $styleMatches)) {
          $relativePath = $styleMatches[1];
        }
        $uri = 'public://' . $relativePath;
      }
    }

    // If we have a URI, generate the thumbnail style URL.
    if (!empty($uri)) {
      /** @var \Drupal\image\ImageStyleInterface|null $imageStyle */
      $imageStyle = $this->entityTypeManager->getStorage('image_style')->load('medium');
      if ($imageStyle) {
        return $imageStyle->buildUrl($uri);
      }
    }

    // Fallback: use the original URL.
    return $imageData['url'] ?? '';
  }

}
