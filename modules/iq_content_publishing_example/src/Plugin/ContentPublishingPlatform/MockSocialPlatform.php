<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_example\Plugin\ContentPublishingPlatform;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformBase;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mock publishing platform for testing the full publishing workflow.
 *
 * This plugin simulates a social media API without making real HTTP calls.
 * All "API requests" are logged to Drupal's State API so they can be
 * inspected at /admin/config/services/content-publishing/mock-api-log.
 *
 * Use this as:
 * - A reference implementation for building your own platform plugin.
 * - A test harness to verify the full node → AI → review → publish flow.
 */
#[ContentPublishingPlatform(
  id: 'mock_social',
  label: new TranslatableMarkup('Mock Social Platform'),
  description: new TranslatableMarkup('A simulated social media platform for testing. Logs all API requests without making real HTTP calls.'),
)]
final class MockSocialPlatform extends ContentPublishingPlatformBase {

  /**
   * State key for the mock API request log.
   */
  public const STATE_KEY = 'iq_content_publishing_example.mock_api_log';

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'text' => [
        'type' => 'textarea',
        'label' => (string) $this->t('Post text'),
        'description' => (string) $this->t('The main content of the social media post. Keep under 280 characters.'),
        'required' => TRUE,
        'max_length' => 280,
        'ai_generated' => TRUE,
      ],
      'hashtags' => [
        'type' => 'textfield',
        'label' => (string) $this->t('Hashtags'),
        'description' => (string) $this->t('Relevant hashtags, space-separated (e.g., #drupal #webdev).'),
        'required' => FALSE,
        'ai_generated' => TRUE,
      ],
      'image' => [
        'type' => 'image',
        'label' => (string) $this->t('Image'),
        'description' => (string) $this->t('Select an image to attach to the post.'),
        'required' => FALSE,
        'max' => 1,
        'ai_generated' => FALSE,
      ],
      'link' => [
        'type' => 'url',
        'label' => (string) $this->t('Link'),
        'description' => (string) $this->t('URL to include in the post.'),
        'required' => FALSE,
        'ai_generated' => FALSE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructions(): string {
    return <<<'INSTRUCTIONS'
Transform the provided Markdown content into a compelling social media post.

Guidelines:
- Keep the post text concise and engaging (under 280 characters preferred).
- Use an attention-grabbing opening line.
- Include a clear call-to-action in the text.
- Provide 2-3 relevant hashtags as a space-separated string.
- Maintain a professional yet approachable tone.
- Do NOT include any markdown formatting in the output.
- Do NOT include the URL in the text field (it will be added separately).
- Do NOT include hashtags in the text field (use the hashtags field).
- Focus on the main content — ignore any navigation, sidebar, or layout artifacts.
INSTRUCTIONS;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCredentialsForm(array $form, array $credentials): array {
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key (mock)'),
      '#description' => $this->t('Enter any value — this is a mock credential for testing. Example: <code>mock-api-key-12345</code>'),
      '#default_value' => $credentials['api_key'] ?? 'mock-api-key-12345',
      '#required' => TRUE,
    ];

    $form['api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Secret (mock)'),
      '#description' => $this->t('Enter any value — this is a mock credential for testing.'),
      '#default_value' => $credentials['api_secret'] ?? 'mock-secret-abcdef',
      '#required' => TRUE,
    ];

    $form['note'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--warning">' . $this->t('<strong>Mock Platform:</strong> No real API calls are made. All requests are logged to the <em>Mock API Log</em> tab for inspection.') . '</div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, array $settings): array {
    $form['simulate_failure'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Simulate API failure'),
      '#description' => $this->t('When checked, the mock API will return a failure response. Useful for testing error handling.'),
      '#default_value' => $settings['simulate_failure'] ?? FALSE,
    ];

    $form['simulated_delay_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Simulated API delay (milliseconds)'),
      '#description' => $this->t('Add an artificial delay to simulate network latency. Set to 0 for instant response.'),
      '#default_value' => $settings['simulated_delay_ms'] ?? 0,
      '#min' => 0,
      '#max' => 10000,
    ];

    $form['simulated_external_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Simulated external post ID prefix'),
      '#description' => $this->t('The mock API will return a post ID starting with this prefix, followed by a random number.'),
      '#default_value' => $settings['simulated_external_id'] ?? 'mock-post',
    ];

    $form['simulated_platform_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Simulated platform URL'),
      '#description' => $this->t('The mock API will return a URL where the post would be visible.'),
      '#default_value' => $settings['simulated_platform_url'] ?? 'https://mock-social.example.com/posts/',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCredentials(array $credentials): bool {
    return !empty($credentials['api_key']) && !empty($credentials['api_secret']);
  }

  /**
   * {@inheritdoc}
   */
  public function publish(NodeInterface $node, array $fields, array $credentials, array $settings): PublishingResult {
    $timestamp = time();
    $postId = ($settings['simulated_external_id'] ?? 'mock-post') . '-' . mt_rand(100000, 999999);
    $platformUrl = rtrim($settings['simulated_platform_url'] ?? 'https://mock-social.example.com/posts/', '/');
    $postUrl = $platformUrl . '/' . $postId;

    // Extract structured field values.
    $text = $fields['text'] ?? '';
    $hashtags = $fields['hashtags'] ?? '';
    $link = $fields['link'] ?? '';
    $images = $fields['image'] ?? [];

    // Build the full post content for the mock API body.
    $fullContent = $text;
    if ($hashtags) {
      $fullContent .= "\n" . $hashtags;
    }
    if ($link) {
      $fullContent .= "\n" . $link;
    }

    // Simulate network delay if configured.
    $delayMs = (int) ($settings['simulated_delay_ms'] ?? 0);
    if ($delayMs > 0) {
      usleep($delayMs * 1000);
    }

    // Build the mock API request that would be sent.
    $imageUrls = [];
    if (is_array($images)) {
      foreach ($images as $imageData) {
        if (is_array($imageData) && !empty($imageData['url'])) {
          $imageUrls[] = $imageData['url'];
        }
      }
    }

    $mockRequest = [
      'method' => 'POST',
      'url' => $platformUrl . '/api/v1/posts',
      'headers' => [
        'Authorization' => 'Bearer ' . ($credentials['api_key'] ?? 'N/A'),
        'Content-Type' => 'application/json',
        'X-API-Secret' => substr($credentials['api_secret'] ?? '', 0, 4) . '****',
        'User-Agent' => 'Drupal/ContentPublishing/1.0',
      ],
      'body' => [
        'text' => $text,
        'hashtags' => $hashtags,
        'link' => $link,
        'media_urls' => $imageUrls,
        'source_url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'source_title' => $node->getTitle(),
        'source_type' => $node->getType(),
        'source_nid' => (int) $node->id(),
        'publish_immediately' => TRUE,
        'metadata' => [
          'drupal_node_id' => (int) $node->id(),
          'drupal_content_type' => $node->getType(),
          'character_count' => mb_strlen($fullContent),
          'image_count' => count($imageUrls),
          'fields' => $fields,
        ],
      ],
    ];

    // Build the mock API response.
    $simulateFailure = !empty($settings['simulate_failure']);

    if ($simulateFailure) {
      $mockResponse = [
        'status_code' => 403,
        'headers' => [
          'Content-Type' => 'application/json',
          'X-Request-Id' => 'req-' . bin2hex(random_bytes(8)),
          'X-RateLimit-Remaining' => '0',
        ],
        'body' => [
          'error' => [
            'code' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'You have exceeded the rate limit. Please try again in 60 seconds.',
            'retry_after' => 60,
          ],
        ],
        'latency_ms' => $delayMs ?: 42,
      ];
    }
    else {
      $mockResponse = [
        'status_code' => 201,
        'headers' => [
          'Content-Type' => 'application/json',
          'X-Request-Id' => 'req-' . bin2hex(random_bytes(8)),
          'X-RateLimit-Remaining' => '998',
          'Location' => $postUrl,
        ],
        'body' => [
          'data' => [
            'id' => $postId,
            'text' => $text,
            'hashtags' => $hashtags,
            'media_urls' => $imageUrls,
            'link' => $link,
            'status' => 'published',
            'created_at' => date('c', $timestamp),
            'url' => $postUrl,
            'engagement' => [
              'likes' => 0,
              'shares' => 0,
              'comments' => 0,
            ],
          ],
        ],
        'latency_ms' => $delayMs ?: 127,
      ];
    }

    // Log the full request/response to State.
    $logEntry = [
      'timestamp' => $timestamp,
      'date' => date('Y-m-d H:i:s', $timestamp),
      'node_id' => (int) $node->id(),
      'node_title' => $node->getTitle(),
      'node_type' => $node->getType(),
      'fields_published' => $fields,
      'content_preview' => mb_substr($fullContent, 0, 500),
      'content_length' => mb_strlen($fullContent),
      'image_count' => count($imageUrls),
      'request' => $mockRequest,
      'response' => $mockResponse,
      'success' => !$simulateFailure,
      'post_id' => $simulateFailure ? NULL : $postId,
      'post_url' => $simulateFailure ? NULL : $postUrl,
    ];

    $this->appendToLog($logEntry);

    // Return result.
    if ($simulateFailure) {
      return PublishingResult::failure(
        'Mock API error: ' . $mockResponse['body']['error']['message'],
        [
          'response' => $mockResponse,
          'request' => $mockRequest,
        ]
      );
    }

    return PublishingResult::success(
      "Published to Mock Social Platform. Post ID: {$postId}. URL: {$postUrl}",
      [
        'post_id' => $postId,
        'post_url' => $postUrl,
        'external_id' => $postId,
        'external_url' => $postUrl,
        'response' => $mockResponse,
        'request' => $mockRequest,
      ]
    );
  }

  /**
   * Appends a log entry to the mock API log in State.
   *
   * @param array $entry
   *   The log entry.
   */
  protected function appendToLog(array $entry): void {
    $log = $this->state->get(self::STATE_KEY, []);
    $log[] = $entry;

    // Keep only the last 100 entries.
    if (count($log) > 100) {
      $log = array_slice($log, -100);
    }

    $this->state->set(self::STATE_KEY, $log);
  }

}
