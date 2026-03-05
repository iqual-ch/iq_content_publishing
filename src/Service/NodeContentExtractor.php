<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Extracts node content as Markdown, images, and videos from rendered HTML.
 *
 * Renders the node once using the "content_publishing" view mode, then:
 * - Converts the HTML to clean Markdown for the AI prompt.
 * - Parses <img> tags for image selection in the review form.
 * - Parses <video> and <iframe> tags for video extraction.
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
   *   - 'id': (string) A unique identifier for the image (hash of the URL).
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
   * Extracts all videos from the node's rendered HTML.
   *
   * Parses <video> tags (including <source> children) and <iframe> embeds
   * (YouTube, Vimeo) from the rendered node output.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract videos from.
   *
   * @return array
   *   Array of video data arrays, each containing:
   *   - 'id': (string) A unique identifier for the video (hash of the URL).
   *   - 'uri': (string) The file URI, or empty if external/embed.
   *   - 'url': (string) The absolute URL to the video.
   *   - 'source': (string) One of 'local', 'youtube', 'vimeo', or 'embed'.
   *   - 'mime': (string) The MIME type, or empty if unknown.
   *   - 'filename': (string) The filename.
   *   - 'thumbnail': (string) The thumbnail URL, if available.
   *   - 'width': (int) The width in pixels, or 0 if unknown.
   *   - 'height': (int) The height in pixels, or 0 if unknown.
   */
  public function extractVideos(NodeInterface $node): array {
    $html = $this->getRenderedHtml($node);
    if (trim($html) === '') {
      return [];
    }

    $videos = $this->parseVideosFromHtml($html);

    // Deduplicate by URL.
    $seen = [];
    $videos = array_values(array_filter($videos, function (array $video) use (&$seen) {
      if (isset($seen[$video['url']])) {
        return FALSE;
      }
      $seen[$video['url']] = TRUE;
      return TRUE;
    }));

    return $videos;
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

      $url = $fileData['url'] ?? $src;
      $images[] = [
        'id' => substr(hash('sha256', $url), 0, 12),
        'uri' => $fileData['uri'] ?? '',
        'url' => $url,
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
   * Parses <video> and <iframe> tags from HTML and extracts video metadata.
   *
   * @param string $html
   *   The rendered HTML.
   *
   * @return array
   *   Array of video data arrays.
   */
  protected function parseVideosFromHtml(string $html): array {
    $videos = [];

    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    // Parse <video> tags.
    $videoTags = $dom->getElementsByTagName('video');
    foreach ($videoTags as $video) {
      $src = $video->getAttribute('src');
      $mime = $video->getAttribute('type') ?: '';
      $width = (int) $video->getAttribute('width');
      $height = (int) $video->getAttribute('height');

      // If no src on the <video> tag, look for <source> children.
      if (empty($src)) {
        $sources = $video->getElementsByTagName('source');
        foreach ($sources as $source) {
          $sourceSrc = $source->getAttribute('src');
          if (!empty($sourceSrc)) {
            $src = $sourceSrc;
            $mime = $source->getAttribute('type') ?: $mime;
            break;
          }
        }
      }

      if (empty($src)) {
        continue;
      }

      $fileData = $this->resolveFileFromUrl($src);
      $url = $fileData['url'] ?? $src;

      $videos[] = [
        'id' => substr(hash('sha256', $url), 0, 12),
        'uri' => $fileData['uri'] ?? '',
        'url' => $url,
        'source' => 'local',
        'mime' => $mime,
        'filename' => $fileData['filename'] ?? basename(parse_url($src, PHP_URL_PATH) ?: 'video'),
        'thumbnail' => $video->getAttribute('poster') ?: '',
        'width' => $width,
        'height' => $height,
      ];
    }

    // Parse <iframe> tags for video embeds (YouTube, Vimeo, etc.).
    $iframeTags = $dom->getElementsByTagName('iframe');
    foreach ($iframeTags as $iframe) {
      $src = $iframe->getAttribute('src');
      if (empty($src)) {
        continue;
      }

      $embedInfo = $this->resolveVideoEmbed($src);
      if ($embedInfo === NULL) {
        continue;
      }

      $width = (int) $iframe->getAttribute('width');
      $height = (int) $iframe->getAttribute('height');

      $videos[] = [
        'id' => substr(hash('sha256', $embedInfo['url']), 0, 12),
        'uri' => '',
        'url' => $embedInfo['url'],
        'source' => $embedInfo['source'],
        'mime' => '',
        'filename' => '',
        'thumbnail' => $embedInfo['thumbnail'],
        'width' => $width,
        'height' => $height,
      ];
    }

    return $videos;
  }

  /**
   * Resolves an iframe src to a video embed with metadata.
   *
   * @param string $src
   *   The iframe src URL.
   *
   * @return array|null
   *   An array with 'url', 'source', and 'thumbnail' keys, or NULL if the
   *   iframe is not a recognized video embed.
   */
  protected function resolveVideoEmbed(string $src): ?array {
    // YouTube.
    if (preg_match('#(?:youtube\.com/embed/|youtube-nocookie\.com/embed/)([\w-]+)#', $src, $m)) {
      return [
        'url' => 'https://www.youtube.com/watch?v=' . $m[1],
        'source' => 'youtube',
        'thumbnail' => 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg',
      ];
    }

    // Vimeo.
    if (preg_match('#player\.vimeo\.com/video/(\d+)#', $src, $m)) {
      return [
        'url' => 'https://vimeo.com/' . $m[1],
        'source' => 'vimeo',
        'thumbnail' => '',
      ];
    }

    return NULL;
  }

  /**
   * Attempts to resolve a media URL to a Drupal file entity.
   *
   * @param string $src
   *   The src attribute from the HTML element.
   *
   * @return array
   *   File data array with 'uri', 'url', 'filename' keys, or empty if not
   *   resolvable.
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
          'uri' => $file->getFileUri(),
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'filename' => $file->getFilename(),
        ];
      }

      // File entity not found, but we can still generate an absolute URL
      // from the derived URI.
      return [
        'uri' => $uri,
        'url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
        'filename' => basename($relativePath),
      ];
    }

    return [];
  }

}
