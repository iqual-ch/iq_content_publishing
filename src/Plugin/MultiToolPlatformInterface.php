<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Plugin;

/**
 * Interface for publishing platforms that support multiple content tools.
 *
 * Some platforms can publish to different content types
 * or "tools" — such as Wiki articles, Facebook posts, Instagram posts,
 * news articles, etc. Each tool may require its own AI instructions and
 * output schema.
 *
 * Plugins that implement this interface signal that they support multiple
 * tools. The platform configuration form will display tool selection
 * and per-tool AI instructions when this interface is detected.
 *
 */
interface MultiToolPlatformInterface {

  /**
   * Returns the available tools/content types for this platform.
   *
   * Each tool represents a distinct publishing action the platform supports,
   * such as creating a content item (wiki, article) or posting to a social
   * media profile. Tool IDs should use a prefix convention to indicate the
   * type of action (e.g. "content:1" for content creation, "social:42" for
   * a social post).
   *
   * @param array $settings
   *   Optional platform plugin settings. Implementations may use these to
   *   query project-specific resources (e.g. active social profiles).
   *
   * @return array<string|int, array{id: string|int, name: string, description?: string}>
   *   An array of tool definitions keyed by tool ID. Each value must contain:
   *   - 'id': The unique tool identifier.
   *   - 'name': The human-readable tool name.
   *   - 'description': (optional) A description of this tool/content type.
   */
  public function getAvailableTools(array $settings = []): array;

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
