<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Extracts and converts node content to Markdown for AI processing.
 *
 * Renders the node using the "content_publishing" view mode, then converts the
 * resulting HTML to clean Markdown. This approach handles any field type,
 * paragraph structures, layout builder output, etc., without needing to
 * enumerate individual fields.
 *
 * Falls back to the "default" view mode if "content_publishing" is not
 * configured for the node's bundle.
 */
final class NodeContentExtractor {

  /**
   * The view mode used to render nodes for AI input.
   */
  protected const VIEW_MODE = 'content_publishing';

  /**
   * Fallback view mode if the primary one is not configured.
   */
  protected const FALLBACK_VIEW_MODE = 'default';

  /**
   * Constructs a NodeContentExtractor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Extracts the node content as a structured Markdown string.
   *
   * The output contains the node title, Markdown-converted body content,
   * the canonical URL, and content type — ready for use as an AI user message.
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

    // Render the node and convert to Markdown.
    $markdown = $this->renderNodeAsMarkdown($node);
    if ($markdown !== '') {
      $parts[] = $markdown;
    }

    // Append the canonical URL.
    try {
      $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
      $parts[] = 'Source URL: ' . $url;
    }
    catch (\Exception) {
      // Node may not have a URL yet.
    }

    // Append content type for context.
    $parts[] = 'Content type: ' . $node->getType();

    return implode("\n\n", $parts);
  }

  /**
   * Renders the node using the content_publishing view mode and converts to Markdown.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   Cleaned Markdown content.
   */
  protected function renderNodeAsMarkdown(NodeInterface $node): string {
    $viewMode = $this->resolveViewMode($node);

    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $renderArray = $viewBuilder->view($node, $viewMode);

    // Render to HTML string.
    $html = (string) $this->renderer->renderInIsolation($renderArray);

    if (trim($html) === '') {
      return '';
    }

    return $this->htmlToMarkdown($html);
  }

  /**
   * Determines which view mode to use for the given node.
   *
   * Falls back to "default" if the content_publishing view mode is not
   * enabled for this bundle.
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
   * Strips Drupal theme wrapper markup (divs, classes, etc.) and converts
   * semantic elements (headings, lists, links, emphasis) to Markdown.
   *
   * @param string $html
   *   The HTML to convert.
   *
   * @return string
   *   Clean Markdown text.
   */
  protected function htmlToMarkdown(string $html): string {
    $converter = new HtmlConverter([
      'strip_tags' => TRUE,
      'remove_nodes' => 'script style',
      'hard_break' => TRUE,
      'strip_placeholder_links' => TRUE,
    ]);

    $markdown = $converter->convert($html);

    // Clean up excessive whitespace from Drupal's wrapper divs.
    $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
    $markdown = trim($markdown);

    return $markdown;
  }

}
