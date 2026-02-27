<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_example\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\iq_content_publishing_example\Plugin\ContentPublishingPlatform\MockSocialPlatform;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for viewing mock API request logs.
 *
 * Displays all simulated API requests made by the MockSocialPlatform plugin,
 * including full request/response details for debugging and testing.
 */
final class MockApiLogController extends ControllerBase {

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * Displays the mock API request log overview.
   */
  public function overview(): array {
    $log = $this->state->get(MockSocialPlatform::STATE_KEY, []);

    // Reverse to show newest first.
    $log = array_reverse($log, TRUE);

    $build = [];

    $build['description'] = [
      '#markup' => '<p>' . $this->t('This page shows all simulated API requests made by the <strong>Mock Social Platform</strong> plugin. No real HTTP calls were made — this is for testing and development purposes.') . '</p>',
    ];

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions']],
    ];

    if (!empty($log)) {
      $build['actions']['clear'] = [
        '#type' => 'link',
        '#title' => $this->t('Clear all logs'),
        '#url' => Url::fromRoute('iq_content_publishing_example.mock_log.clear'),
        '#attributes' => [
          'class' => ['button', 'button--danger'],
        ],
      ];
    }

    $header = [
      $this->t('#'),
      $this->t('Date'),
      $this->t('Node'),
      $this->t('Method'),
      $this->t('URL'),
      $this->t('Status'),
      $this->t('Content (preview)'),
      $this->t('Post ID'),
      $this->t('Details'),
    ];

    $rows = [];
    foreach ($log as $originalIndex => $entry) {
      $contentPreview = mb_substr($entry['content_preview'] ?? '', 0, 80);
      if (mb_strlen($entry['content_preview'] ?? '') > 80) {
        $contentPreview .= '…';
      }

      $statusCode = $entry['response']['status_code'] ?? 'N/A';
      $statusClass = $entry['success'] ? 'color: green;' : 'color: red;';

      $nodeLink = $entry['node_title'] ?? 'N/A';
      if (!empty($entry['node_id'])) {
        $nodeEntity = $this->entityTypeManager()->getStorage('node')->load($entry['node_id']);
        if ($nodeEntity) {
          $nodeLink = $nodeEntity->toLink()->toString();
        }
      }

      $rows[] = [
        $originalIndex + 1,
        $entry['date'] ?? 'N/A',
        ['data' => ['#markup' => $nodeLink]],
        $entry['request']['method'] ?? 'N/A',
        $entry['request']['url'] ?? 'N/A',
        ['data' => ['#markup' => '<span style="' . $statusClass . 'font-weight:bold;">' . $statusCode . '</span>']],
        $contentPreview,
        $entry['post_id'] ?? '-',
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('View'),
            '#url' => Url::fromRoute('iq_content_publishing_example.mock_log.detail', [
              'index' => $originalIndex,
            ]),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No mock API requests logged yet. Publish a node and use the Mock Social Platform to see requests here.'),
    ];

    return $build;
  }

  /**
   * Displays the full detail of a single mock API request.
   *
   * @param int $index
   *   The log entry index.
   */
  public function detail(int $index): array {
    $log = $this->state->get(MockSocialPlatform::STATE_KEY, []);

    if (!isset($log[$index])) {
      throw new NotFoundHttpException();
    }

    $entry = $log[$index];

    $build = [];

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to log'),
      '#url' => Url::fromRoute('iq_content_publishing_example.mock_log'),
      '#attributes' => ['class' => ['button']],
    ];

    // General info.
    $build['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Information'),
      '#open' => TRUE,
    ];
    $build['general']['table'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Timestamp'), $entry['date'] ?? 'N/A'],
        [$this->t('Node ID'), $entry['node_id'] ?? 'N/A'],
        [$this->t('Node Title'), $entry['node_title'] ?? 'N/A'],
        [$this->t('Content Type'), $entry['node_type'] ?? 'N/A'],
        [$this->t('Success'), $entry['success'] ? $this->t('Yes') : $this->t('No')],
        [$this->t('Post ID'), $entry['post_id'] ?? '-'],
        [$this->t('Post URL'), $entry['post_url'] ?? '-'],
        [$this->t('Content Length'), ($entry['content_length'] ?? 0) . ' characters'],
      ],
    ];

    // Published content.
    $build['content'] = [
      '#type' => 'details',
      '#title' => $this->t('Published Content'),
      '#open' => TRUE,
    ];
    $build['content']['text'] = [
      '#type' => 'item',
      '#markup' => '<pre style="white-space: pre-wrap; background: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">' . htmlspecialchars($entry['content_preview'] ?? '') . '</pre>',
    ];

    // Full request.
    $build['request'] = [
      '#type' => 'details',
      '#title' => $this->t('API Request'),
      '#open' => TRUE,
    ];
    $build['request']['data'] = [
      '#type' => 'item',
      '#markup' => '<pre style="white-space: pre-wrap; background: #eef6ff; padding: 15px; border: 1px solid #b8d4f0; border-radius: 4px; overflow-x: auto;">' . htmlspecialchars(json_encode($entry['request'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>',
    ];

    // Full response.
    $build['response'] = [
      '#type' => 'details',
      '#title' => $this->t('API Response'),
      '#open' => TRUE,
    ];

    $responseStyle = ($entry['success'] ?? FALSE)
      ? 'background: #efffef; border: 1px solid #a8d5a8;'
      : 'background: #fff0f0; border: 1px solid #d5a8a8;';

    $build['response']['data'] = [
      '#type' => 'item',
      '#markup' => '<pre style="white-space: pre-wrap; ' . $responseStyle . ' padding: 15px; border-radius: 4px; overflow-x: auto;">' . htmlspecialchars(json_encode($entry['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>',
    ];

    // cURL equivalent.
    $build['curl'] = [
      '#type' => 'details',
      '#title' => $this->t('cURL Equivalent'),
      '#open' => FALSE,
    ];

    $curl = $this->buildCurlCommand($entry['request'] ?? []);
    $build['curl']['command'] = [
      '#type' => 'item',
      '#markup' => '<pre style="white-space: pre-wrap; background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto;">' . htmlspecialchars($curl) . '</pre>',
    ];

    return $build;
  }

  /**
   * Clears all mock API log entries.
   */
  public function clear(): RedirectResponse {
    $this->state->delete(MockSocialPlatform::STATE_KEY);
    $this->messenger()->addStatus($this->t('Mock API log cleared.'));
    return new RedirectResponse(
      Url::fromRoute('iq_content_publishing_example.mock_log')->toString()
    );
  }

  /**
   * Builds a cURL command string from a mock request.
   *
   * @param array $request
   *   The mock request data.
   *
   * @return string
   *   A cURL command string.
   */
  protected function buildCurlCommand(array $request): string {
    $method = $request['method'] ?? 'POST';
    $url = $request['url'] ?? 'https://example.com/api/v1/posts';
    $headers = $request['headers'] ?? [];
    $body = $request['body'] ?? [];

    $parts = ["curl -X {$method}"];
    $parts[] = "  '{$url}'";

    foreach ($headers as $name => $value) {
      $parts[] = "  -H '{$name}: {$value}'";
    }

    if (!empty($body)) {
      $jsonBody = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      $parts[] = "  -d '" . str_replace("'", "'\\''", $jsonBody) . "'";
    }

    return implode(" \\\n", $parts);
  }

}
