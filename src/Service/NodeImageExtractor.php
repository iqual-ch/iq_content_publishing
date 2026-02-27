<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Extracts image data from rendered node HTML.
 *
 * Renders the node using the "content_publishing" view mode and parses
 * <img> tags from the HTML output. This captures images from any source —
 * direct image fields, media fields, paragraphs, layout builder, etc.
 *
 * When possible, images are resolved back to Drupal file entities to
 * provide file IDs and URIs. Images from external sources are included
 * with URL-only metadata.
 */
final class NodeImageExtractor {

  /**
   * The view mode used to render nodes for image extraction.
   */
  protected const VIEW_MODE = 'content_publishing';

  /**
   * Fallback view mode.
   */
  protected const FALLBACK_VIEW_MODE = 'default';

  /**
   * Constructs a NodeImageExtractor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Extracts all images from a node's rendered HTML output.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract images from.
   * @param int $limit
   *   Maximum number of images to return. 0 means no limit.
   *
   * @return array
   *   Array of image data arrays, each containing:
   *   - 'fid': (int) The file entity ID, or 0 if not resolvable.
   *   - 'uri': (string) The file URI, or empty if external.
   *   - 'url': (string) The absolute URL to the image.
   *   - 'alt': (string) The alt text.
   *   - 'title': (string) The title attribute.
   *   - 'filename': (string) The filename.
   *   - 'width': (int) The width in pixels, or 0 if unknown.
   *   - 'height': (int) The height in pixels, or 0 if unknown.
   */
  public function extractImages(NodeInterface $node, int $limit = 0): array {
    $html = $this->renderNode($node);
    if (trim($html) === '') {
      return [];
    }

    $images = $this->extractImagesFromHtml($html);

    // Deduplicate by URL.
    $seen = [];
    $images = array_filter($images, function (array $image) use (&$seen) {
      if (isset($seen[$image['url']])) {
        return FALSE;
      }
      $seen[$image['url']] = TRUE;
      return TRUE;
    });
    $images = array_values($images);

    if ($limit > 0 && count($images) > $limit) {
      $images = array_slice($images, 0, $limit);
    }

    return $images;
  }

  /**
   * Renders the node using the content_publishing view mode.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The rendered HTML.
   */
  protected function renderNode(NodeInterface $node): string {
    $viewMode = $this->resolveViewMode($node);
    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $renderArray = $viewBuilder->view($node, $viewMode);

    return (string) $this->renderer->renderInIsolation($renderArray);
  }

  /**
   * Determines which view mode to use.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The view mode to use.
   */
  protected function resolveViewMode(NodeInterface $node): string {
    $bundle = $node->bundle();
    $viewDisplay = $this->entityTypeManager
      ->getStorage('entity_view_display')
      ->load('node.' . $bundle . '.' . static::VIEW_MODE);

    if ($viewDisplay && $viewDisplay->status()) {
      return static::VIEW_MODE;
    }

    return static::FALLBACK_VIEW_MODE;
  }

  /**
   * Parses <img> tags from HTML and extracts image metadata.
   *
   * Attempts to resolve each image URL back to a Drupal file entity.
   *
   * @param string $html
   *   The rendered HTML.
   *
   * @return array
   *   Array of image data arrays.
   */
  protected function extractImagesFromHtml(string $html): array {
    $images = [];

    $dom = new \DOMDocument();
    // Suppress warnings from malformed HTML.
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    $imgTags = $dom->getElementsByTagName('img');

    foreach ($imgTags as $img) {
      $src = $img->getAttribute('src');
      if (empty($src)) {
        continue;
      }

      // Skip tiny images (likely icons, spacers, tracking pixels).
      $width = (int) $img->getAttribute('width');
      $height = (int) $img->getAttribute('height');
      if (($width > 0 && $width < 50) || ($height > 0 && $height < 50)) {
        continue;
      }

      // Skip data URIs and SVGs.
      if (str_starts_with($src, 'data:') || str_ends_with(strtolower($src), '.svg')) {
        continue;
      }

      $alt = $img->getAttribute('alt') ?: '';
      $title = $img->getAttribute('title') ?: '';

      // Try to resolve the URL to a Drupal file entity.
      $fileData = $this->resolveFileFromUrl($src);

      $images[] = [
        'fid' => $fileData['fid'] ?? 0,
        'uri' => $fileData['uri'] ?? '',
        'url' => $fileData['url'] ?? $src,
        'alt' => $alt,
        'title' => $title,
        'filename' => $fileData['filename'] ?? basename(parse_url($src, PHP_URL_PATH) ?: 'image'),
        'width' => $width,
        'height' => $height,
      ];
    }

    return $images;
  }

  /**
   * Attempts to resolve an image URL to a Drupal file entity.
   *
   * Looks for files whose URI generates a URL matching the given src.
   *
   * @param string $src
   *   The image src attribute from the HTML.
   *
   * @return array
   *   File data with 'fid', 'uri', 'url', 'filename' keys, or empty if
   *   the file could not be resolved.
   */
  protected function resolveFileFromUrl(string $src): array {
    // Extract the path portion and try to match against known file schemes.
    // Drupal file URLs typically contain /sites/default/files/ or /files/.
    if (preg_match('#/files/(.+?)(?:\?|$)#', $src, $matches)) {
      $relativePath = urldecode($matches[1]);

      // Handle image styles: /files/styles/STYLE/public/PATH
      if (preg_match('#^styles/[^/]+/public/(.+)$#', $relativePath, $styleMatches)) {
        $relativePath = $styleMatches[1];
      }

      $uri = 'public://' . $relativePath;

      $fileStorage = $this->entityTypeManager->getStorage('file');
      $files = $fileStorage->loadByProperties(['uri' => $uri]);

      if (!empty($files)) {
        /** @var \Drupal\file\FileInterface $file */
        $file = reset($files);
        return [
          'fid' => (int) $file->id(),
          'uri' => $file->getFileUri(),
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'filename' => $file->getFilename(),
        ];
      }
    }

    return [];
  }

}
