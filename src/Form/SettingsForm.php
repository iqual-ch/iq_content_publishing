<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Global settings form for the content publishing module.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['iq_content_publishing.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'iq_content_publishing_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('iq_content_publishing.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable content publishing'),
      '#description' => $this->t('When disabled, the publishing modal will not appear on node forms.'),
      '#default_value' => $config->get('enabled'),
    ];

    // Build options from available filter formats.
    $formatOptions = $this->getAvailableTextFormats();

    $form['default_html_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Default HTML text format'),
      '#description' => $this->t('The text format to use for HTML editor fields in the review form. Platform plugins may override this.'),
      '#options' => $formatOptions,
      '#default_value' => $config->get('default_html_format') ?: 'full_html',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('iq_content_publishing.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('default_html_format', $form_state->getValue('default_html_format'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Gets available text formats as options.
   *
   * @return array
   *   Array of format ID => label.
   */
  protected function getAvailableTextFormats(): array {
    $options = [];
    /** @var \Drupal\filter\FilterFormatInterface[] $formats */
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    foreach ($formats as $format) {
      if ($format->status()) {
        $options[$format->id()] = $format->label();
      }
    }
    return $options;
  }

}
