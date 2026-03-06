<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfig;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformManager;
use Drupal\iq_content_publishing\Plugin\MultiToolPlatformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding/editing publishing platform config entities.
 */
final class PlatformConfigForm extends EntityForm {

  /**
   * Constructs a PlatformConfigForm.
   */
  public function __construct(
    protected ContentPublishingPlatformManager $pluginManager,
    protected AiProviderFormHelper $aiFormHelper,
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.content_publishing_platform'),
      $container->get('ai.form_helper'),
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $entity */
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [PublishingPlatformConfig::class, 'load'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->getDescription(),
      '#rows' => 2,
    ];

    // Platform plugin selection.
    $plugins = [];
    foreach ($this->pluginManager->getDefinitions() as $id => $definition) {
      $plugins[$id] = $definition['label'];
    }

    $form['plugin_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Platform plugin'),
      '#options' => $plugins,
      '#default_value' => $entity->getPluginId(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a platform -'),
      '#description' => $this->t('The platform plugin that handles API communication. Install additional modules to add more platforms.'),
      '#ajax' => [
        'callback' => '::pluginDependentSettingsAjax',
        'wrapper' => 'plugin-dependent-settings',
      ],
    ];

    // Behavior settings.
    $form['behavior'] = [
      '#type' => 'details',
      '#title' => $this->t('Behavior'),
      '#open' => TRUE,
    ];

    $form['behavior']['review_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require review before publishing'),
      '#description' => $this->t('If enabled, editors will see and can edit the AI-generated content before it is sent to the platform.'),
      '#default_value' => $entity->isReviewMode(),
    ];

    $form['behavior']['processing_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Processing mode'),
      '#options' => [
        'sync' => $this->t('Synchronous — editor waits for result'),
        'async' => $this->t('Asynchronous — queued for background processing'),
      ],
      '#default_value' => $entity->getProcessingMode() ?: 'sync',
      '#description' => $this->t('Synchronous mode gives immediate feedback. Asynchronous mode queues the request and processes it on the next cron run.'),
    ];

    $form['behavior']['resubmit_behavior'] = [
      '#type' => 'radios',
      '#title' => $this->t('Re-submission behavior'),
      '#options' => [
        'allow' => $this->t('Allow silently — no warnings when re-submitting'),
        'warn' => $this->t('Warn — show a warning but allow re-submission'),
        'block' => $this->t('Require confirmation — user must confirm duplicate before publishing'),
      ],
      '#default_value' => $entity->getResubmitBehavior() ?: 'warn',
      '#description' => $this->t('Controls what happens when a user tries to publish a node that was already sent to this platform. Re-submitting creates a new post on external platforms like Hootsuite.'),
    ];

    // Content configuration.
    $form['content_configuration'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Configuration'),
      '#open' => TRUE,
    ];

    $content_types = [];
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($node_types as $type) {
      $content_types[$type->id()] = $type->label();
    }

    $form['content_configuration']['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled content types'),
      '#options' => $content_types,
      '#default_value' => $entity->getContentTypes(),
      '#description' => $this->t('Select which content types can be published to this platform. Leave all unchecked to enable for all types.'),
    ];

    // Wrapper for all plugin-dependent sections (refreshed via AJAX).
    $form['plugin_dependent'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'plugin-dependent-settings'],
    ];

    // AI configuration.
    $form['plugin_dependent']['ai'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Configuration'),
      '#open' => TRUE,
    ];

    // Try to get default instructions from the plugin.
    // During AJAX rebuilds, getValue() may not yet be populated, so check
    // user input first, then fall back to the entity's stored value.
    $defaultInstructions = '';
    $pluginId = $form_state->getValue('plugin_id')
      ?? ($form_state->getUserInput()['plugin_id'] ?? NULL)
      ?: $entity->getPluginId();
    if ($pluginId && $this->pluginManager->hasDefinition($pluginId)) {
      try {
        $plugin = $this->pluginManager->createInstance($pluginId);
        $defaultInstructions = $plugin->getDefaultAiInstructions();
      }
      catch (\Exception) {
        // Plugin may not be loadable yet.
      }
    }

    // On AJAX rebuild triggered by plugin_id change, Drupal ignores
    // #default_value and repopulates from user input. We must inject the
    // plugin default into user input so the textarea actually shows it.
    $triggeringElement = $form_state->getTriggeringElement();
    $isPluginChange = $triggeringElement && ($triggeringElement['#name'] ?? '') === 'plugin_id';

    $currentInstructions = $entity->getAiInstructions();
    if ($isPluginChange && $entity->isNew() && empty($currentInstructions)) {
      // Plugin just changed — force the default instructions into user input.
      $input = $form_state->getUserInput();
      $input['ai_instructions'] = $defaultInstructions;
      $form_state->setUserInput($input);
    }

    $instructionsDefault = $currentInstructions ?: $defaultInstructions;

    $form['plugin_dependent']['ai']['ai_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('AI prompt instructions'),
      '#default_value' => $instructionsDefault,
      '#rows' => 8,
      '#description' => $this->t('Instructions for the AI to transform node content for this platform. Supports tokens like [node:title], [node:url]. Leave empty to use the plugin default.'),
    ];

    // AI provider/model selection using the Drupal AI module form helper.
    // Pre-seed form state with saved values so the helper picks them up.
    if ($form_state->getValue('ai_ai_provider') === NULL && $entity->getAiProvider()) {
      $form_state->setValue('ai_ai_provider', $entity->getAiProvider());
    }
    if ($form_state->getValue('ai_ai_model') === NULL && $entity->getAiModel()) {
      $form_state->setValue('ai_ai_model', $entity->getAiModel());
    }

    $this->aiFormHelper->generateAiProvidersForm(
      $form['plugin_dependent']['ai'],
      $form_state,
      'chat',
      'ai',
      AiProviderFormHelper::FORM_CONFIGURATION_NONE,
      0,
      '',
      $this->t('AI Provider'),
      $this->t('Select the AI provider and model to use for generating content for this platform. Choose "Default" to use the site-wide default.'),
      TRUE,
    );

    // Plugin-specific settings (dynamic, inside the AJAX wrapper).
    if ($pluginId && $this->pluginManager->hasDefinition($pluginId)) {
      try {
        /** @var \Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformInterface $plugin */
        $plugin = $this->pluginManager->createInstance($pluginId);

        $credentials_form = $plugin->buildCredentialsForm([], $entity->getCredentials());
        if (!empty($credentials_form)) {
          $form['plugin_dependent']['credentials'] = [
            '#type' => 'details',
            '#title' => $this->t('Credentials'),
            '#open' => TRUE,
            '#tree' => TRUE,
          ] + $credentials_form;
        }

        // Resolve current plugin settings: during AJAX rebuilds, prefer
        // user input so that changing e.g. project_id refreshes tools.
        $currentSettings = $entity->getPluginSettings();
        $userInput = $form_state->getUserInput();
        if (!empty($userInput['plugin_settings']) && is_array($userInput['plugin_settings'])) {
          $currentSettings = array_merge($currentSettings, $userInput['plugin_settings']);
        }

        $settings_form = $plugin->buildSettingsForm([], $currentSettings, $entity->getCredentials());
        if (!empty($settings_form)) {
          $form['plugin_dependent']['plugin_settings'] = [
            '#type' => 'details',
            '#title' => $this->t('Platform Settings'),
            '#open' => TRUE,
            '#tree' => TRUE,
            '#prefix' => '<div id="plugin-settings-wrapper">',
            '#suffix' => '</div>',
          ] + $settings_form;
        }

        // Multi-tool support: show tool selection and per-tool AI
        // instructions inside the Platform Settings section.
        if ($plugin instanceof MultiToolPlatformInterface) {
          $toolsSection = $this->buildToolsSection($plugin, $entity, $form_state, $isPluginChange, $currentSettings);
          if (!empty($toolsSection)) {
            $form['plugin_dependent']['plugin_settings']['tools'] = $toolsSection;
          }
        }
      }
      catch (\Exception) {
        $form['plugin_dependent']['notice'] = [
          '#markup' => '<p>' . $this->t('Could not load plugin settings. Make sure the platform module is enabled.') . '</p>',
        ];
      }
    }

    return $form;
  }

  /**
   * AJAX callback for plugin settings changes (e.g. project_id).
   *
   * Returns the plugin_settings wrapper so tools can refresh.
   */
  public function pluginSettingsAjax(array &$form, FormStateInterface $form_state): array {
    return $form['plugin_dependent']['plugin_settings'];
  }

  /**
   * Builds the tools configuration section for multi-tool platforms.
   *
   * @param \Drupal\iq_content_publishing\Plugin\MultiToolPlatformInterface $plugin
   *   The multi-tool platform plugin instance.
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $entity
   *   The platform config entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param bool $isPluginChange
   *   Whether this render is triggered by a plugin_id AJAX change.
   *
   * @return array
   *   The form render array for the tools section.
   */
  protected function buildToolsSection(MultiToolPlatformInterface $plugin, $entity, FormStateInterface $form_state, bool $isPluginChange, array $currentSettings = []): array {
    $availableTools = $plugin->getAvailableTools($currentSettings ?: $entity->getPluginSettings());

    if (empty($availableTools)) {
      return [];
    }

    $pluginSettings = $entity->getPluginSettings();
    $toolsConfig = $pluginSettings['tools'] ?? [];

    $enabledTools = array_keys($toolsConfig);
    // On AJAX plugin change for new entities, default to none selected.
    if ($isPluginChange && $entity->isNew()) {
      $enabledTools = [];
    }

    // Separate tools by group. Ungrouped tools are top-level individuals.
    $groups = [];
    $ungroupedTools = [];
    foreach ($availableTools as $tool) {
      $toolId = (string) $tool['id'];
      $groupId = $tool['group'] ?? '';
      if ($groupId !== '') {
        if (!isset($groups[$groupId])) {
          $groups[$groupId] = [
            'label' => $tool['group_label'] ?? ucfirst($groupId),
            'tools' => [],
          ];
        }
        $groups[$groupId]['tools'][$toolId] = $tool;
      }
      else {
        $ungroupedTools[$toolId] = $tool;
      }
    }

    $section = [
      '#type' => 'details',
      '#title' => $this->t('Tools'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Select which integrations to enable and configure per-tool AI instructions.'),
    ];

    // --- Top-level tool selection ---
    // Each group becomes a master toggle; ungrouped tools are individual items.
    $toolOptions = [];
    foreach ($groups as $groupId => $group) {
      $toolOptions[$groupId] = $group['label'];
    }
    foreach ($ungroupedTools as $tool) {
      $toolId = (string) $tool['id'];
      $label = $tool['name'];
      if (!empty($tool['description'])) {
        $label .= ' — ' . $tool['description'];
      }
      $toolOptions[$toolId] = $label;
    }

    // Determine defaults: a group toggle is checked if any tool in it is
    // enabled; ungrouped tools are checked directly.
    $topLevelDefaults = [];
    foreach ($groups as $groupId => $group) {
      foreach (array_keys($group['tools']) as $toolId) {
        if (in_array($toolId, $enabledTools, TRUE)) {
          $topLevelDefaults[] = $groupId;
          break;
        }
      }
    }
    foreach ($ungroupedTools as $tool) {
      $toolId = (string) $tool['id'];
      if (in_array($toolId, $enabledTools, TRUE)) {
        $topLevelDefaults[] = $toolId;
      }
    }

    $section['enabled'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled integrations'),
      '#options' => $toolOptions,
      '#default_value' => $topLevelDefaults,
      '#description' => $this->t('Select which integrations this platform should publish to.'),
    ];

    // --- Per-group sub-selection (visible when group toggle is checked) ---
    foreach ($groups as $groupId => $group) {
      $groupToolOptions = [];
      foreach ($group['tools'] as $tool) {
        $toolId = (string) $tool['id'];
        $label = $tool['name'];
        if (!empty($tool['description'])) {
          $label .= ' — ' . $tool['description'];
        }
        $groupToolOptions[$toolId] = $label;
      }

      $groupDefaults = array_intersect(array_keys($groupToolOptions), $enabledTools);

      $section['group_' . $groupId] = [
        '#type' => 'checkboxes',
        '#title' => $group['label'],
        '#options' => $groupToolOptions,
        '#default_value' => $groupDefaults,
        '#states' => [
          'visible' => [
            ':input[name="plugin_settings[tools][enabled][' . $groupId . ']"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // --- Per-tool AI instructions ---
    $section['instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Per-tool AI Instructions'),
      '#open' => TRUE,
      '#description' => $this->t('Override the default AI prompt instructions per tool. Leave empty to use the plugin default. Only enabled tools are shown.'),
    ];

    // Grouped tool instructions: visible when both group toggle AND the
    // specific tool checkbox are checked.
    foreach ($groups as $groupId => $group) {
      foreach ($group['tools'] as $tool) {
        $toolId = (string) $tool['id'];
        $defaultInstructions = $plugin->getDefaultAiInstructionsForTool($toolId);
        $savedInstructions = $toolsConfig[$toolId]['ai_instructions'] ?? '';

        if ($isPluginChange && $entity->isNew()) {
          $input = $form_state->getUserInput();
          $input['plugin_settings']['tools']['instructions'][$toolId] = $defaultInstructions;
          $form_state->setUserInput($input);
        }

        $section['instructions'][$toolId] = [
          '#type' => 'textarea',
          '#title' => $tool['name'],
          '#default_value' => $savedInstructions ?: $defaultInstructions,
          '#rows' => 6,
          '#description' => $this->t('AI instructions for %tool.', [
            '%tool' => $tool['name'],
          ]),
          '#states' => [
            'visible' => [
              ':input[name="plugin_settings[tools][enabled][' . $groupId . ']"]' => ['checked' => TRUE],
              ':input[name="plugin_settings[tools][group_' . $groupId . '][' . $toolId . ']"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
    }

    // Ungrouped tool instructions: visible when the tool is checked.
    foreach ($ungroupedTools as $tool) {
      $toolId = (string) $tool['id'];
      $defaultInstructions = $plugin->getDefaultAiInstructionsForTool($toolId);
      $savedInstructions = $toolsConfig[$toolId]['ai_instructions'] ?? '';

      if ($isPluginChange && $entity->isNew()) {
        $input = $form_state->getUserInput();
        $input['plugin_settings']['tools']['instructions'][$toolId] = $defaultInstructions;
        $form_state->setUserInput($input);
      }

      $section['instructions'][$toolId] = [
        '#type' => 'textarea',
        '#title' => $tool['name'],
        '#default_value' => $savedInstructions ?: $defaultInstructions,
        '#rows' => 6,
        '#description' => $this->t('AI instructions for %tool.', [
          '%tool' => $tool['name'],
        ]),
        '#states' => [
          'visible' => [
            ':input[name="plugin_settings[tools][enabled][' . $toolId . ']"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return $section;
  }

  /**
   * AJAX callback for plugin selection.
   *
   * Returns the entire plugin-dependent section (AI config + credentials +
   * plugin settings) so that changing the plugin refreshes everything.
   */
  public function pluginDependentSettingsAjax(array &$form, FormStateInterface $form_state): array {
    return $form['plugin_dependent'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $this->aiFormHelper->validateAiProvidersConfig($form, $form_state, 'chat', 'ai');
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $entity */
    $entity = $this->entity;

    // Clean up content_types (remove unchecked values).
    $content_types = array_values(array_filter($form_state->getValue('content_types', [])));
    $entity->set('content_types', $content_types);

    // Set behavior values from nested group.
    $entity->set('review_mode', (bool) $form_state->getValue('review_mode'));
    $entity->set('processing_mode', $form_state->getValue('processing_mode'));
    $entity->set('resubmit_behavior', $form_state->getValue('resubmit_behavior'));

    // Set AI values from nested group.
    $entity->set('ai_instructions', $form_state->getValue('ai_instructions'));

    // Save AI provider and model from the AI module form helper.
    $aiProvider = $form_state->getValue('ai_ai_provider') ?? '';
    $aiModel = $form_state->getValue('ai_ai_model') ?? '';
    if ($aiProvider === '__default__') {
      $entity->set('ai_provider', '');
      $entity->set('ai_model', '');
    }
    else {
      $entity->set('ai_provider', $aiProvider);
      $entity->set('ai_model', $aiModel);
    }

    // Set credentials and plugin settings if present.
    if ($form_state->getValue('credentials')) {
      $entity->set('credentials', $form_state->getValue('credentials'));
    }

    // Build plugin_settings from the form values.
    $pluginSettings = $form_state->getValue('plugin_settings') ?? $entity->getPluginSettings();

    // Merge tools configuration into plugin_settings when present.
    // Tools form is nested inside plugin_settings (#tree), so its values
    // arrive as $pluginSettings['tools'].
    $toolsValues = $pluginSettings['tools'] ?? NULL;
    if (is_array($toolsValues)) {
      $enabledTools = [];
      $topLevel = array_filter($toolsValues['enabled'] ?? []);

      // For each top-level entry, check if it's a group toggle (has a
      // corresponding group_<id> sub-selection) or a direct tool ID.
      foreach ($topLevel as $key) {
        $groupKey = 'group_' . $key;
        if (isset($toolsValues[$groupKey]) && is_array($toolsValues[$groupKey])) {
          // Group toggle: collect the individually selected tools within it.
          foreach (array_filter($toolsValues[$groupKey]) as $toolId) {
            $enabledTools[(string) $toolId] = (string) $toolId;
          }
        }
        else {
          // Direct tool ID (ungrouped).
          $enabledTools[(string) $key] = (string) $key;
        }
      }

      $toolInstructions = $toolsValues['instructions'] ?? [];

      $toolsConfig = [];
      foreach ($enabledTools as $toolId) {
        $toolsConfig[(string) $toolId] = [
          'ai_instructions' => $toolInstructions[(string) $toolId] ?? '',
        ];
      }

      $pluginSettings['tools'] = $toolsConfig;
    }

    $entity->set('plugin_settings', $pluginSettings);

    $status = $entity->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Publishing platform %label created.', [
        '%label' => $entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Publishing platform %label updated.', [
        '%label' => $entity->label(),
      ]));
    }

    $form_state->setRedirectUrl($entity->toUrl('collection'));

    return $status;
  }

}
