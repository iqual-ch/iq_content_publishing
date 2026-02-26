<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Global settings form for the content publishing module.
 */
final class SettingsForm extends ConfigFormBase {

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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('iq_content_publishing.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
