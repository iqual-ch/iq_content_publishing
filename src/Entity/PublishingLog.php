<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the PublishingLog content entity.
 *
 * Tracks all content publishing operations including AI output,
 * API responses, and status for auditing and debugging.
 *
 * @ContentEntityType(
 *   id = "publishing_log",
 *   label = @Translation("Publishing Log"),
 *   label_collection = @Translation("Publishing Logs"),
 *   label_singular = @Translation("publishing log entry"),
 *   label_plural = @Translation("publishing log entries"),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "iq_content_publishing_log",
 *   admin_permission = "view publishing log",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
final class PublishingLog extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['nid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Node'))
      ->setDescription(t('The node that was published externally.'))
      ->setSetting('target_type', 'node')
      ->setRequired(TRUE);

    $fields['platform_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Platform'))
      ->setDescription(t('The publishing platform config entity ID.'))
      ->setSettings(['max_length' => 255])
      ->setRequired(TRUE);

    $fields['plugin_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Plugin'))
      ->setDescription(t('The platform plugin ID.'))
      ->setSettings(['max_length' => 255])
      ->setRequired(TRUE);

    $fields['tool_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tool ID'))
      ->setDescription(t('The tool identifier for multi-tool platforms (e.g. social:4, content:2). NULL for single-tool platforms.'))
      ->setSettings(['max_length' => 255]);

    $fields['status_code'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('The publishing status: success or failure.'))
      ->setSettings(['max_length' => 32])
      ->setRequired(TRUE);

    $fields['ai_output'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('AI Output'))
      ->setDescription(t('The AI-generated content that was sent.'));

    $fields['ai_prompt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('AI Prompt'))
      ->setDescription(t('The prompt used for AI generation.'));

    $fields['api_response'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('API Response'))
      ->setDescription(t('The raw API response from the external platform.'));

    $fields['message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Message'))
      ->setDescription(t('A human-readable result message.'));

    $fields['external_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('External ID'))
      ->setDescription(t('The ID of the post on the external platform.'))
      ->setSettings(['max_length' => 512]);

    $fields['external_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('External URL'))
      ->setDescription(t('The URL of the post on the external platform.'));

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('The user who triggered the publishing.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('When the publishing operation occurred.'));

    return $fields;
  }

}
