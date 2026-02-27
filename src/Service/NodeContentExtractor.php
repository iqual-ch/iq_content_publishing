<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Extracts node content as Markdown and images from rendered HTML.
 *
 * Renders the node once using the "content_publishing" view mode, then:
 * - Converts the HTML to clean Markdown for the AI prompt.
 * - Parses <img> tags for image selection in the review form.
 *
 * This unified approach handles any content architecture (paragraphs,
 * layout builder, media, etc.) without field-by-field enumeration,
 * and avoids rendering the node multiple times.
 *
 * Falls back to the "default" view mode if "content_publishing" is not
 * configured for the node's bundle.
 */
final class NodeContentExtractor {

  /**
   * The view mode used to render nodes.
   */
  protected const VIEW_MODE = 'content_publishing';

  /**
   * Fallback view mode if the primary one is not configured.
   */
  protected const FALLBACK_VIEW_MODE = 'default';

  /**
   * Cached rendered HTML keyed by node ID.
   *
   * @var array<int, string>
   */
  protected array $htmlCache = [];

  /**
   * Constructs a NodeContentExtractor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Extracts the node content as a structured Markdown string.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract content from.
   *
   * @return string
   *   Markdown representation of the node content.
   */
  public function extract(NodeInterface $node): string {
    $parts = [];
    $parts[] = '# ' . $node->getTitle();

    $markdown = $this->htmlToMarkdown($this->getRenderedHtml($node));
    if ($markdown !== '') {
      $parts[] = $markdown;
    }

    try {
      $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
      $parts[] = 'Source URL: ' . $url;
    }
    catch (\Exception) {
    }

    $parts[] = 'Content type: ' . $node->getType();

    return implode("\n\n", $parts);
  }

  /**
   * Extracts all images from the node's rendered HTML.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract images from.
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
  public function extractImages(NodeInterface $node): array {
    $html = $this->getRenderedHtml($node);
    if (trim($html) === '') {
      return [];
    }

    $images = $this->parseImagesFromHtml($html);

    // Deduplicate by URL.
    $seen = [];
    $images = array_values(array_filter($images, function (array $image) use (&$seen) {
      if (isset($seen[$image['url']])) {
        return FALSE;
      }
      $seen[$image['url']] = TRUE;
      return TRUE;
    }));

    return $images;
  }

  /**
   * Gets the rendered HTML for a node, caching per request.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The rendered HTML.
   */
  protected function getRenderedHtml(NodeInterface $node): string {
    $nid = (int) $node->id();

    if (!isset($this->htmlCache[$nid])) {
      $viewMode = $this->resolveViewMode($node);
      $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
      $renderArray = $viewBuilder->view($node, $viewMode);
      $this->htmlCache[$nid] = (string) $this->renderer->renderInIsolation($renderArray);
    }

    return $this->htmlCache[$nid];
  }

  /**
   * Determines which view mode to use for the given node.
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
   * Converts HTML to clean Markdown.
   *
   * @param string $html
   *   The HTML to convert.
   *
   * @return string
   *   Clean Markdown text.
   */
  protected function htmlToMarkdown(string $html): string {
    if (trim($html) === '') {
      return '';
    }

    $converter = new HtmlConverter([
      'strip_tags' => TRUE,
      'remove_nodes' => 'script style',
      'hard_break' => TRUE,
      'strip_placeholder_links' => TRUE,
    ]);

    $markdown = $converter->convert($html);
    $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

    return trim($markdown);
  }

  /**
   * Parses <img> tags from HTML and extracts image metadata.
   *
   * @param string $html
   *   The rendered HTML.
   *
   * @return array
   *   Array of image data arrays.
   */
  protected function parseImagesFromHtml(string $html): array {
    $images = [];

    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    $imgTags = $dom->getElementsByTagName('img');

    foreach ($imgTags as $img) {
      $src = $img->getAttribute('src');
      if (empty($src)) {
        continue;
      }

      $width = (int) $img->getAttribute('width');
      $height = (int) $img->getAttribute('height');
      if (($width > 0 && $width < 50) || ($height > 0 && $height < 50)) {
        continue;
      }

      if (str_starts_with($src, 'data:') || str_ends_with(strtolower($src), '.svg')) {
        continue;
      }

      $fileData = $this->resolveFileFromUrl($src);

      $images[] = [
        'fid' => $fileData['fid'] ?? 0,
        'uri' => $fileData['uri'] ?? '',
        'url' => $fileData['url'] ?? $src,
        'alt' => $img->getAttribute('alt') ?: '',
        'title' => $img->getAttribute('title') ?: '',
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
   * @param string $src
   *   The image src attribute from the HTML.
   *
   * @return array
   *   File data array, or empty if not resolvable.
   */
  protected function resolveFileFromUrl(string $src): array {
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
