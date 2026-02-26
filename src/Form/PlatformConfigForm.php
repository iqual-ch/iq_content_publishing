<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfig;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformManager;
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
        'callback' => '::pluginSettingsAjax',
        'wrapper' => 'plugin-settings-wrapper',
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

    // Content types.
    $content_types = [];
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($node_types as $type) {
      $content_types[$type->id()] = $type->label();
    }

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled content types'),
      '#options' => $content_types,
      '#default_value' => $entity->getContentTypes(),
      '#description' => $this->t('Select which content types can be published to this platform. Leave all unchecked to enable for all types.'),
    ];

    // AI configuration.
    $form['ai'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Configuration'),
      '#open' => TRUE,
    ];

    // Try to get default instructions from the plugin.
    $defaultInstructions = '';
    $pluginId = $form_state->getValue('plugin_id') ?: $entity->getPluginId();
    if ($pluginId && $this->pluginManager->hasDefinition($pluginId)) {
      try {
        $plugin = $this->pluginManager->createInstance($pluginId);
        $defaultInstructions = $plugin->getDefaultAiInstructions();
      }
      catch (\Exception) {
        // Plugin may not be loadable yet.
      }
    }

    $form['ai']['ai_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('AI prompt instructions'),
      '#default_value' => $entity->getAiInstructions() ?: $defaultInstructions,
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
      $form['ai'],
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

    // Plugin-specific settings (dynamic).
    $form['plugin_settings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'plugin-settings-wrapper'],
    ];

    if ($pluginId && $this->pluginManager->hasDefinition($pluginId)) {
      try {
        $plugin = $this->pluginManager->createInstance($pluginId);

        $credentials_form = $plugin->buildCredentialsForm([], $entity->getCredentials());
        if (!empty($credentials_form)) {
          $form['plugin_settings_wrapper']['credentials'] = [
            '#type' => 'details',
            '#title' => $this->t('Credentials'),
            '#open' => TRUE,
          ] + $credentials_form;
        }

        $settings_form = $plugin->buildSettingsForm([], $entity->getPluginSettings());
        if (!empty($settings_form)) {
          $form['plugin_settings_wrapper']['plugin_settings'] = [
            '#type' => 'details',
            '#title' => $this->t('Platform Settings'),
            '#open' => TRUE,
          ] + $settings_form;
        }
      }
      catch (\Exception) {
        $form['plugin_settings_wrapper']['notice'] = [
          '#markup' => '<p>' . $this->t('Could not load plugin settings. Make sure the platform module is enabled.') . '</p>',
        ];
      }
    }

    return $form;
  }

  /**
   * AJAX callback for plugin selection.
   */
  public function pluginSettingsAjax(array &$form, FormStateInterface $form_state): array {
    return $form['plugin_settings_wrapper'];
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
    if ($form_state->getValue('plugin_settings')) {
      $entity->set('plugin_settings', $form_state->getValue('plugin_settings'));
    }

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
