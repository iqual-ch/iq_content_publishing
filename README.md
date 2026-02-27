# Content Publishing

A platform-agnostic framework for publishing Drupal content to external
platforms (social networks, newsletters, etc.) using AI-powered content
transformation.

## Overview

Content Publishing lets editors send published nodes to any number of external
platforms with a single workflow. An AI model transforms the node body into
platform-specific structured output (post text, headline, images, links, etc.)
that the editor can review and edit before the module dispatches it through the
platform's API.

The module ships a **plugin system** so that each target platform (e.g. X /
Twitter, LinkedIn, Mailchimp) can be provided by its own sub-module. A bundled
example sub-module (`iq_content_publishing_example`) demonstrates how to build a
platform plugin.

### Key features

- **AI content transformation** – uses the Drupal AI module to convert node
  content into structured, platform-ready output.
- **Plugin-based platform architecture** – add new platforms by creating a small
  plugin class with a PHP attribute; no core changes needed.
- **Two-step editorial workflow** – select target platforms, then review and edit
  AI-generated content before publishing.
- **Per-platform configuration** – credentials, content-type restrictions, and
  custom AI prompt instructions per platform.
- **Queue support** – optionally dispatch publishing jobs through Drupal's queue
  system for asynchronous processing.
- **Publishing log** – every publish operation is recorded as a content entity
  with status, API response, and AI output for auditing and debugging.

## Requirements

| Dependency            | Version |
|-----------------------|---------|
| Drupal core           | ^11     |
| Node module           | (core)  |
| Content Moderation    | (core)  |
| [AI module]           | ^1      |

[AI module]: https://www.drupal.org/project/ai

An AI provider (e.g. OpenAI, Anthropic) must be configured in the AI module
before content transformation will work.

## Installation

Install as you would normally install a contributed Drupal module.
See [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.

```bash
composer require drupal/iq_content_publishing
drush en iq_content_publishing
```

## Configuration

1. Navigate to **Administration » Configuration » Web services » Content
   Publishing Platforms** (`/admin/config/services/content-publishing`).
2. Click **Add Publishing Platform** to create a new platform configuration.
   - Select the platform plugin.
   - Enter API credentials.
   - Choose which content types the platform should be available for.
   - Optionally customize the AI prompt instructions.
3. Visit the **Settings** tab
   (`/admin/config/services/content-publishing/settings`) to enable or disable
   the module globally.

### Permissions

| Permission                          | Description                                        |
|-------------------------------------|----------------------------------------------------|
| *Administer content publishing*     | Manage platform configurations and global settings  |
| *Publish to external platforms*     | Trigger the publish workflow from node forms         |
| *View publishing log*               | View the publishing history                         |

## Usage

1. Edit (or view) a published node.
2. Click **Publish externally** in the node form actions area.
3. Select one or more target platforms and click **Next**.
4. Review the AI-generated content for each platform, make any edits, and click
   **Publish**.
5. Publishing results are shown on-screen and recorded in the publishing log.

The per-node publishing history is available under the **Publishing Log** tab on
each node.

## Creating a platform plugin

To add support for a new external platform, create a module with a plugin class:

```php
namespace Drupal\my_platform\Plugin\ContentPublishingPlatform;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformBase;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\node\NodeInterface;

#[ContentPublishingPlatform(
  id: 'my_platform',
  label: new TranslatableMarkup('My Platform'),
  description: new TranslatableMarkup('Publish content to My Platform.'),
)]
class MyPlatform extends ContentPublishingPlatformBase {

  public function getOutputSchema(): array {
    return [
      'text' => [
        'type' => 'textarea',
        'label' => 'Post text',
        'required' => TRUE,
        'max_length' => 280,
        'ai_generated' => TRUE,
      ],
    ];
  }

  public function getDefaultAiInstructions(): string {
    return 'Write a short social media post for My Platform.';
  }

  public function buildCredentialsForm(array $form, array $credentials): array {
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $credentials['api_key'] ?? '',
      '#required' => TRUE,
    ];
    return $form;
  }

  public function publish(NodeInterface $node, array $fields, array $credentials, array $settings): PublishingResult {
    // Call the platform's API and return a PublishingResult.
  }

}
```

See the bundled `iq_content_publishing_example` sub-module for a fully working
reference implementation.

## Maintainers

- [iqual](https://www.drupal.org/iqual)