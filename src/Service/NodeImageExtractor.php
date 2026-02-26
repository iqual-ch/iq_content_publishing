<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Extracts image data from node entity fields.
 *
 * Scans a node for image fields (field type 'image') and entity
 * reference fields pointing to media entities with image source fields.
 * Returns an array of image metadata suitable for platform publishing.
 */
final class NodeImageExtractor {

  /**
   * Constructs a NodeImageExtractor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Extracts all images from a node.
   *
   * Scans the node's fields for:
   * - Direct image fields (field type 'image').
   * - Entity reference fields pointing to media entities of type 'image'.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract images from.
   * @param int $limit
   *   Maximum number of images to return. 0 means no limit.
   *
   * @return array
   *   Array of image data arrays, each containing:
   *   - 'fid': (int) The file entity ID.
   *   - 'uri': (string) The file URI (e.g., 'public://image.jpg').
   *   - 'url': (string) The absolute URL to the file.
   *   - 'alt': (string) The alt text.
   *   - 'title': (string) The title attribute (if available).
   *   - 'filename': (string) The original filename.
   *   - 'source_field': (string) The node field name the image came from.
   */
  public function extractImages(NodeInterface $node, int $limit = 0): array {
    $images = [];

    foreach ($node->getFieldDefinitions() as $fieldName => $definition) {
      $fieldType = $definition->getType();

      if ($fieldType === 'image') {
        $images = array_merge($images, $this->extractFromImageField($node, $fieldName));
      }
      elseif ($fieldType === 'entity_reference' && $definition->getSetting('target_type') === 'media') {
        $images = array_merge($images, $this->extractFromMediaField($node, $fieldName));
      }
    }

    if ($limit > 0 && count($images) > $limit) {
      $images = array_slice($images, 0, $limit);
    }

    return $images;
  }

  /**
   * Extracts images from an image field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $fieldName
   *   The image field name.
   *
   * @return array
   *   Array of image data arrays.
   */
  protected function extractFromImageField(NodeInterface $node, string $fieldName): array {
    $images = [];

    if ($node->get($fieldName)->isEmpty()) {
      return $images;
    }

    foreach ($node->get($fieldName) as $item) {
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $item->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }

      $images[] = [
        'fid' => (int) $file->id(),
        'uri' => $file->getFileUri(),
        'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
        'alt' => $item->alt ?? '',
        'title' => $item->title ?? '',
        'filename' => $file->getFilename(),
        'source_field' => $fieldName,
      ];
    }

    return $images;
  }

  /**
   * Extracts images from a media entity reference field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $fieldName
   *   The entity reference field name.
   *
   * @return array
   *   Array of image data arrays.
   */
  protected function extractFromMediaField(NodeInterface $node, string $fieldName): array {
    $images = [];

    if ($node->get($fieldName)->isEmpty()) {
      return $images;
    }

    foreach ($node->get($fieldName) as $item) {
      /** @var \Drupal\media\MediaInterface|null $media */
      $media = $item->entity;
      if (!$media instanceof MediaInterface) {
        continue;
      }

      // Only process image-type media.
      $sourceFieldName = $media->getSource()->getConfiguration()['source_field'] ?? '';
      if (empty($sourceFieldName) || !$media->hasField($sourceFieldName)) {
        continue;
      }

      $sourceField = $media->get($sourceFieldName);
      if ($sourceField->isEmpty()) {
        continue;
      }

      $sourceFieldType = $sourceField->getFieldDefinition()->getType();
      if ($sourceFieldType !== 'image') {
        continue;
      }

      foreach ($sourceField as $imageItem) {
        /** @var \Drupal\file\FileInterface|null $file */
        $file = $imageItem->entity;
        if (!$file instanceof FileInterface) {
          continue;
        }

        $images[] = [
          'fid' => (int) $file->id(),
          'uri' => $file->getFileUri(),
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'alt' => $imageItem->alt ?? '',
          'title' => $imageItem->title ?? '',
          'filename' => $file->getFilename(),
          'source_field' => $fieldName,
        ];
      }
    }

    return $images;
  }

}
