<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin;

/**
 * Interface for publishing platforms that support multiple content tools.
 *
 * Some platforms (e.g., contentbird) can publish to different content types
 * or "tools" — such as Wiki articles, Facebook posts, Instagram posts,
 * news articles, etc. Each tool may require its own AI instructions and
 * output schema.
 *
 * Plugins that implement this interface signal that they support multiple
 * tools. The platform configuration form will display tool selection
 * and per-tool AI instructions when this interface is detected.
 *
 * Plugins that do NOT implement this interface are single-tool platforms
 * and behave exactly as before (backwards compatible).
 */
interface MultiToolPlatformInterface {

  /**
   * Returns the available tools/content types for this platform.
   *
   * Each tool represents a distinct content format the platform supports,
   * such as a blog post, social media post, wiki article, etc.
   *
   * @return array<string|int, array{id: string|int, name: string, description?: string}>
   *   An array of tool definitions keyed by tool ID. Each value must contain:
   *   - 'id': The unique tool identifier (e.g., the platform's type ID).
   *   - 'name': The human-readable tool name.
   *   - 'description': (optional) A description of this tool/content type.
   */
  public function getAvailableTools(): array;

  /**
   * Returns the output schema for a specific tool.
   *
   * Allows platforms to define different output schemas per tool. For example,
   * a social media post tool may only need a short text field, whereas a
   * wiki article tool needs title, summary, and content body fields.
   *
   * @param string|int $toolId
   *   The tool identifier.
   *
   * @return array<string, array<string, mixed>>
   *   The output schema keyed by field name (same format as getOutputSchema()).
   *   Return an empty array to use the platform's default output schema.
   */
  public function getOutputSchemaForTool(string|int $toolId): array;

  /**
   * Returns default AI prompt instructions for a specific tool.
   *
   * Each tool can have its own prompt instructions tailored to the specific
   * content format. For example, a "Facebook post" tool would get instructions
   * about writing concise social media copy, while a "Wiki" tool would get
   * instructions about structured, informational content.
   *
   * @param string|int $toolId
   *   The tool identifier.
   *
   * @return string
   *   The default AI instructions for this tool.
   */
  public function getDefaultAiInstructionsForTool(string|int $toolId): string;

}
