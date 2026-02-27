<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Transforms node content into structured platform-specific output using AI.
 *
 * Uses the drupal/ai module's native structured JSON schema support
 * (ChatInput::setChatStructuredJsonSchema) when the provider supports it,
 * guaranteeing valid JSON output at the API level. Falls back to prompt-based
 * JSON instructions with parsing/repair for providers without native support.
 */
final class AiContentTransformer {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an AiContentTransformer.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected Token $token,
    protected NodeContentExtractor $contentExtractor,
    \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('iq_content_publishing');
  }

  /**
   * Generates structured platform-specific content from a node using AI.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The source node.
   * @param string $instructions
   *   The AI prompt instructions (system message).
   * @param array $outputSchema
   *   The platform's output schema from getOutputSchema().
   * @param string $ai_provider
   *   Optional AI provider ID. Empty string = use site default.
   * @param string $ai_model
   *   Optional AI model override. Empty string = use site default.
   *
   * @return \Drupal\iq_content_publishing\Service\AiTransformResult
   *   The transformation result with structured fields.
   */
  public function transform(NodeInterface $node, string $instructions, array $outputSchema = [], string $ai_provider = '', string $ai_model = ''): AiTransformResult {
    try {
      // Extract node content as Markdown via the dedicated view mode.
      $userMessage = $this->contentExtractor->extract($node);

      // Determine which AI-generated fields we need.
      $aiFields = $this->getAiGeneratedFields($outputSchema);
      $useStructuredOutput = count($aiFields) > 1
        || (count($aiFields) === 1 && !(isset($aiFields['text']) && $aiFields['text']['type'] === 'textarea'));

      // Build the system prompt.
      $systemPrompt = $this->buildSystemPrompt($instructions, $outputSchema, $node, $useStructuredOutput);

      // Get AI provider and model.
      [$provider, $modelId] = $this->resolveProvider($ai_provider, $ai_model);

      // Build the chat input.
      $input = new ChatInput([
        new ChatMessage('user', $userMessage),
      ]);
      $input->setSystemPrompt($systemPrompt);

      // For multi-field output, try to use native structured JSON schema.
      $nativeJsonSchemaUsed = FALSE;
      if ($useStructuredOutput) {
        $jsonSchema = $this->buildJsonSchema($aiFields);
        if ($this->supportsStructuredOutput($provider, $modelId)) {
          $input->setChatStructuredJsonSchema($jsonSchema);
          $nativeJsonSchemaUsed = TRUE;
          $this->logger->debug('Using native structured JSON schema for AI output.');
        }
        else {
          $this->logger->debug('Provider does not support structured output; using prompt-based JSON instructions.');
        }
      }

      $response = $provider->chat($input, $modelId, ['iq_content_publishing']);
      $generatedText = $response->getNormalized()->getText();

      // Parse the AI response into structured fields.
      $fields = $this->parseAiResponse($generatedText, $outputSchema, $nativeJsonSchemaUsed);

      return new AiTransformResult(
        success: TRUE,
        fields: $fields,
        prompt: $systemPrompt,
        userMessage: $userMessage,
      );
    }
    catch (\Exception $e) {
      $this->logger->error('AI content transformation failed for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);

      return new AiTransformResult(
        success: FALSE,
        fields: [],
        prompt: $instructions,
        userMessage: $this->contentExtractor->extract($node),
        error: $e->getMessage(),
      );
    }
  }

  /**
   * Resolves the AI provider instance and model ID.
   *
   * @param string $ai_provider
   *   Provider ID or empty for default.
   * @param string $ai_model
   *   Model ID or empty for default.
   *
   * @return array
   *   A tuple of [provider instance, model ID string].
   */
  protected function resolveProvider(string $ai_provider, string $ai_model): array {
    if ($ai_provider && $ai_model) {
      return [$this->aiProvider->createInstance($ai_provider), $ai_model];
    }

    $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
    $provider = $this->aiProvider->createInstance($default['provider_id']);
    $modelId = $ai_model ?: $default['model_id'];

    return [$provider, $modelId];
  }

  /**
   * Checks if the given provider/model supports native structured output.
   *
   * @param object $provider
   *   The AI provider plugin instance.
   * @param string $modelId
   *   The model identifier.
   *
   * @return bool
   *   TRUE if the model advertises ChatStructuredResponse capability.
   */
  protected function supportsStructuredOutput(object $provider, string $modelId): bool {
    try {
      if (!method_exists($provider, 'getModelCapabilities')) {
        return FALSE;
      }
      $capabilities = $provider->getModelCapabilities('chat', $modelId);
      if (is_array($capabilities)) {
        return in_array(AiModelCapability::ChatStructuredResponse, $capabilities, TRUE);
      }
    }
    catch (\Exception $e) {
      $this->logger->debug('Could not check model capabilities: @msg', [
        '@msg' => $e->getMessage(),
      ]);
    }
    return FALSE;
  }

  /**
   * Builds the system prompt.
   *
   * When native structured output is used, the prompt focuses on content
   * guidance without JSON formatting instructions (the API enforces the
   * schema). When falling back to prompt-based mode, explicit JSON
   * instructions are included.
   *
   * @param string $instructions
   *   The platform-specific AI instructions.
   * @param array $outputSchema
   *   The platform's output schema.
   * @param \Drupal\node\NodeInterface $node
   *   The source node (for token replacement).
   * @param bool $useStructuredOutput
   *   Whether multi-field structured output is expected.
   *
   * @return string
   *   The complete system prompt.
   */
  protected function buildSystemPrompt(string $instructions, array $outputSchema, NodeInterface $node, bool $useStructuredOutput): string {
    // Resolve tokens in base instructions.
    $resolvedInstructions = $this->token->replace($instructions, ['node' => $node], ['clear' => TRUE]);

    // Preamble: explain the input format to the AI.
    $preamble = "You are a content transformation assistant. " .
      "You will receive a piece of web content converted to Markdown. " .
      "Use this content as the sole source material for your output. " .
      "Ignore any residual markup artifacts or navigation elements.\n\n";

    $aiFields = $this->getAiGeneratedFields($outputSchema);

    // No AI fields or simple single text field — plain text mode.
    if (empty($aiFields) || !$useStructuredOutput) {
      $suffix = '';
      if (count($aiFields) === 1 && isset($aiFields['text']) && $aiFields['text']['type'] === 'textarea') {
        $suffix = "\n\nOutput ONLY the final post text, nothing else.";
      }
      return $preamble . $resolvedInstructions . $suffix;
    }

    // Multi-field mode — add field descriptions to help the AI understand
    // what each field is for. The JSON structure itself is enforced by the
    // API schema when supported; these descriptions provide semantic context.
    $schemaDescription = $this->buildSchemaDescription($aiFields);

    return $preamble . $resolvedInstructions . "\n\n" .
      "Your response must contain the following fields:\n" .
      $schemaDescription;
  }

  /**
   * Builds a JSON Schema for the AI provider's structured output feature.
   *
   * Converts our platform output schema into the OpenAI-compatible JSON
   * Schema format used by ChatInput::setChatStructuredJsonSchema().
   *
   * @param array $aiFields
   *   The AI-generated fields from the output schema.
   *
   * @return array
   *   A JSON Schema array with 'name', 'strict', and 'schema' keys.
   */
  protected function buildJsonSchema(array $aiFields): array {
    $properties = [];
    $required = [];

    foreach ($aiFields as $fieldName => $field) {
      $property = [
        'type' => 'string',
        'description' => ($field['label'] ?? $fieldName),
      ];

      if (!empty($field['description'])) {
        $property['description'] .= ' — ' . $field['description'];
      }

      if (!empty($field['max_length'])) {
        $property['description'] .= ' (max ' . $field['max_length'] . ' characters)';
      }

      $properties[$fieldName] = $property;

      // In strict mode, all properties must be listed as required.
      $required[] = $fieldName;
    }

    return [
      'name' => 'content_publishing_output',
      'strict' => TRUE,
      'schema' => [
        'type' => 'object',
        'properties' => $properties,
        'required' => $required,
        'additionalProperties' => FALSE,
      ],
    ];
  }

  /**
   * Filters the output schema to only AI-generated fields.
   *
   * @param array $outputSchema
   *   The full output schema.
   *
   * @return array
   *   Only fields with ai_generated === TRUE.
   */
  protected function getAiGeneratedFields(array $outputSchema): array {
    return array_filter($outputSchema, function (array $field) {
      return !empty($field['ai_generated']);
    });
  }

  /**
   * Builds a human-readable schema description for the AI prompt.
   *
   * @param array $aiFields
   *   The AI-generated fields from the output schema.
   *
   * @return string
   *   The schema description for the prompt.
   */
  protected function buildSchemaDescription(array $aiFields): string {
    $lines = [];
    foreach ($aiFields as $fieldName => $field) {
      $desc = '- "' . $fieldName . '": ';
      $desc .= ($field['label'] ?? $fieldName);

      if (!empty($field['description'])) {
        $desc .= ' — ' . $field['description'];
      }

      $constraints = [];
      if (!empty($field['required'])) {
        $constraints[] = 'required';
      }
      if (!empty($field['max_length'])) {
        $constraints[] = 'max ' . $field['max_length'] . ' characters';
      }
      if ($field['type'] === 'textfield') {
        $constraints[] = 'short single-line text';
      }
      elseif ($field['type'] === 'textarea') {
        $constraints[] = 'multi-line text';
      }
      elseif ($field['type'] === 'url') {
        $constraints[] = 'valid URL';
      }

      if (!empty($constraints)) {
        $desc .= ' (' . implode(', ', $constraints) . ')';
      }

      $lines[] = $desc;
    }

    return implode("\n", $lines);
  }

  /**
   * Parses the AI response into structured fields.
   *
   * When native structured output was used, the response is guaranteed
   * to be valid JSON — we parse directly. Otherwise, falls back to
   * progressive parsing strategies (clean → repair → regex extraction).
   *
   * @param string $response
   *   The raw AI response text.
   * @param array $outputSchema
   *   The platform's output schema.
   * @param bool $nativeJsonSchemaUsed
   *   Whether native structured JSON schema was used for this request.
   *
   * @return array
   *   Structured fields keyed by schema field names.
   */
  protected function parseAiResponse(string $response, array $outputSchema, bool $nativeJsonSchemaUsed = FALSE): array {
    $aiFields = $this->getAiGeneratedFields($outputSchema);

    // Single text field mode: return the response as-is.
    if (count($aiFields) === 1 && isset($aiFields['text']) && $aiFields['text']['type'] === 'textarea') {
      return ['text' => trim($response)];
    }

    // If empty AI fields, nothing to parse.
    if (empty($aiFields)) {
      return [];
    }

    $this->logger->debug('Raw AI response for parsing: @response', [
      '@response' => mb_substr($response, 0, 1000),
    ]);

    // When the provider enforced the JSON schema, parse directly.
    if ($nativeJsonSchemaUsed) {
      $decoded = json_decode(trim($response), TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $this->logger->debug('Parsed native structured JSON response.');
        return $this->mapDecodedFields($decoded, $aiFields);
      }
      // Unexpected: native schema should always return valid JSON.
      // Fall through to fallback strategies.
      $this->logger->warning('Native structured output returned invalid JSON; attempting fallback parsing.');
    }

    // Fallback Strategy 1: Clean markdown fences / extract JSON object.
    $cleanedResponse = $this->cleanJsonResponse($response);
    $decoded = json_decode($cleanedResponse, TRUE);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $this->logger->debug('Parsed AI response as JSON (after cleaning).');
      return $this->mapDecodedFields($decoded, $aiFields);
    }

    // Fallback Strategy 2: Repair common JSON issues and re-try.
    $repairedJson = $this->repairJson($cleanedResponse, array_keys($aiFields));
    $decoded = json_decode($repairedJson, TRUE);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $this->logger->notice('Parsed AI response after JSON repair.');
      return $this->mapDecodedFields($decoded, $aiFields);
    }

    // Fallback Strategy 3: Extract fields using regex.
    $regexFields = $this->extractFieldsViaRegex($response, $aiFields);
    if (!empty($regexFields)) {
      $this->logger->notice('Recovered fields via regex extraction from malformed response.');
      return $regexFields;
    }

    // Last resort: dump everything into the first field.
    $this->logger->warning('Could not parse AI response into structured fields. Response: @response', [
      '@response' => mb_substr($response, 0, 500),
    ]);
    $firstField = array_key_first($aiFields);
    return [$firstField => trim($response)];
  }

  /**
   * Maps decoded JSON data to the expected AI field keys.
   *
   * @param array $decoded
   *   The decoded JSON array.
   * @param array $aiFields
   *   The expected AI-generated fields.
   *
   * @return array
   *   The mapped fields.
   */
  protected function mapDecodedFields(array $decoded, array $aiFields): array {
    $fields = [];
    foreach ($aiFields as $fieldName => $fieldDef) {
      $value = $decoded[$fieldName] ?? '';
      // Ensure we always return strings.
      $fields[$fieldName] = is_array($value) ? json_encode($value) : (string) $value;
    }
    return $fields;
  }

  /**
   * Extracts field values from a response using regex as a last resort.
   *
   * Handles cases where the AI returns near-JSON but with formatting
   * issues that prevent proper JSON parsing. Tries quoted values first,
   * then falls back to unquoted values using known field boundaries.
   *
   * @param string $response
   *   The raw AI response.
   * @param array $aiFields
   *   The expected AI-generated fields from the output schema.
   *
   * @return array
   *   Extracted fields, or empty array if extraction fails.
   */
  protected function extractFieldsViaRegex(string $response, array $aiFields): array {
    $fields = [];
    $fieldNames = array_keys($aiFields);

    foreach ($fieldNames as $fieldName) {
      // Try A: properly quoted value — "fieldName": "value".
      $pattern = '/"' . preg_quote($fieldName, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s';
      if (preg_match($pattern, $response, $matches)) {
        $unescaped = json_decode('"' . $matches[1] . '"');
        $fields[$fieldName] = is_string($unescaped) ? $unescaped : $matches[1];
        continue;
      }

      // Try B: unquoted or partially-quoted value.
      $boundaries = ['\s*}'];
      foreach ($fieldNames as $otherField) {
        if ($otherField !== $fieldName) {
          $boundaries[] = '\s*,\s*"' . preg_quote($otherField, '/') . '"';
        }
      }
      $boundaryLookahead = '(?=' . implode('|', $boundaries) . ')';
      $pattern = '/"' . preg_quote($fieldName, '/') . '"\s*:\s*(.+?)' . $boundaryLookahead . '/s';
      if (preg_match($pattern, $response, $matches)) {
        $value = trim($matches[1]);
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
          $decoded = json_decode($value);
          $value = is_string($decoded) ? $decoded : substr($value, 1, -1);
        }
        elseif (str_ends_with($value, '"')) {
          $value = substr($value, 0, -1);
        }
        $fields[$fieldName] = $value;
      }
    }

    return !empty($fields) ? $fields : [];
  }

  /**
   * Attempts to repair malformed JSON by fixing unquoted string values.
   *
   * @param string $json
   *   The JSON string (already cleaned via cleanJsonResponse).
   * @param array $fieldNames
   *   The expected field names from the output schema.
   *
   * @return string
   *   The repaired JSON string.
   */
  protected function repairJson(string $json, array $fieldNames): string {
    foreach ($fieldNames as $fieldName) {
      $quotedName = preg_quote($fieldName, '/');

      $boundaries = ['\s*}'];
      foreach ($fieldNames as $other) {
        if ($other !== $fieldName) {
          $boundaries[] = '\s*,\s*"' . preg_quote($other, '/') . '"';
        }
      }
      $boundary = '(?=' . implode('|', $boundaries) . ')';

      $pattern = '/("' . $quotedName . '"\s*:\s*)(?!")(.+?)' . $boundary . '/s';

      $json = preg_replace_callback($pattern, function (array $m): string {
        $value = trim($m[2]);
        if (str_ends_with($value, '"')) {
          $value = substr($value, 0, -1);
        }
        return $m[1] . json_encode($value, JSON_UNESCAPED_UNICODE);
      }, $json);
    }

    return $json;
  }

  /**
   * Cleans potential markdown formatting from a JSON response.
   *
   * @param string $response
   *   The raw AI response.
   *
   * @return string
   *   The cleaned response containing only the JSON.
   */
  protected function cleanJsonResponse(string $response): string {
    $response = trim($response);

    // Extract content from markdown code fences.
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $response, $matches)) {
      return trim($matches[1]);
    }

    // Find the first { and last } to extract the JSON object.
    $firstBrace = strpos($response, '{');
    $lastBrace = strrpos($response, '}');
    if ($firstBrace !== FALSE && $lastBrace !== FALSE && $lastBrace > $firstBrace) {
      return substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
    }

    return $response;
  }

}
