<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Transforms node content into structured platform-specific output using AI.
 *
 * Builds prompts from platform AI instructions, output schema, and node
 * content. The AI returns JSON matching the schema's ai_generated fields.
 * Non-AI fields (images, links) are populated programmatically.
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

      // Build the system prompt with output schema instructions.
      $systemPrompt = $this->buildSystemPrompt($instructions, $outputSchema, $node);

      // Get AI provider and model.
      if ($ai_provider && $ai_model) {
        $provider = $this->aiProvider->createInstance($ai_provider);
        $modelId = $ai_model;
      }
      elseif ($ai_model) {
        $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
        $provider = $this->aiProvider->createInstance($default['provider_id']);
        $modelId = $ai_model;
      }
      else {
        $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
        $provider = $this->aiProvider->createInstance($default['provider_id']);
        $modelId = $default['model_id'];
      }

      // Build and execute the chat input.
      $input = new ChatInput([
        new ChatMessage('user', $userMessage),
      ]);
      $input->setSystemPrompt($systemPrompt);

      $response = $provider->chat($input, $modelId, ['iq_content_publishing']);
      $generatedText = $response->getNormalized()->getText();

      // Parse the AI response into structured fields.
      $fields = $this->parseAiResponse($generatedText, $outputSchema);

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
   * Builds the system prompt including JSON output schema instructions.
   *
   * @param string $instructions
   *   The platform-specific AI instructions.
   * @param array $outputSchema
   *   The platform's output schema.
   * @param \Drupal\node\NodeInterface $node
   *   The source node (for token replacement).
   *
   * @return string
   *   The complete system prompt.
   */
  protected function buildSystemPrompt(string $instructions, array $outputSchema, NodeInterface $node): string {
    // Resolve tokens in base instructions.
    $resolvedInstructions = $this->token->replace($instructions, ['node' => $node], ['clear' => TRUE]);

    // Preamble: explain the input format to the AI.
    $preamble = "You are a content transformation assistant. " .
      "You will receive a piece of web content converted to Markdown. " .
      "Use this content as the sole source material for your output. " .
      "Ignore any residual markup artifacts or navigation elements.\n\n";

    // If no schema or only a single text field with no other AI fields,
    // fall back to simple text mode for backward compatibility.
    $aiFields = $this->getAiGeneratedFields($outputSchema);
    if (empty($aiFields)) {
      return $preamble . $resolvedInstructions;
    }

    // For a single AI "text" field, keep the prompt simple.
    if (count($aiFields) === 1 && isset($aiFields['text']) && $aiFields['text']['type'] === 'textarea') {
      return $preamble . $resolvedInstructions . "\n\nOutput ONLY the final post text, nothing else.";
    }

    // Multiple AI-generated fields: instruct AI to return JSON.
    $schemaDescription = $this->buildSchemaDescription($aiFields);

    return $preamble . $resolvedInstructions . "\n\n" .
      "IMPORTANT: You MUST respond with a valid JSON object containing the following fields:\n" .
      $schemaDescription . "\n" .
      "Do NOT include any text outside the JSON object. Do NOT use markdown code fences.\n" .
      "Respond with ONLY the raw JSON object.";
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
   * Handles two modes:
   * - Single text field: returns the raw text as the field value.
   * - Multiple fields: parses JSON response and maps to field names.
   *
   * @param string $response
   *   The raw AI response text.
   * @param array $outputSchema
   *   The platform's output schema.
   *
   * @return array
   *   Structured fields keyed by schema field names.
   */
  protected function parseAiResponse(string $response, array $outputSchema): array {
    $aiFields = $this->getAiGeneratedFields($outputSchema);

    // Single text field mode: return the response as-is.
    if (count($aiFields) === 1 && isset($aiFields['text']) && $aiFields['text']['type'] === 'textarea') {
      return ['text' => trim($response)];
    }

    $this->logger->debug('Raw AI response for parsing: @response', [
      '@response' => mb_substr($response, 0, 1000),
    ]);

    // Strategy 1: Clean and parse as JSON.
    $cleanedResponse = $this->cleanJsonResponse($response);
    $decoded = json_decode($cleanedResponse, TRUE);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $this->logger->debug('Parsed via cleanJsonResponse.');
      return $this->mapDecodedFields($decoded, $aiFields);
    }

    // Strategy 2: Try the raw trimmed response directly.
    $decoded = json_decode(trim($response), TRUE);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $this->logger->debug('Parsed raw response as JSON.');
      return $this->mapDecodedFields($decoded, $aiFields);
    }

    // Strategy 3: Extract fields using regex from the raw response.
    $regexFields = $this->extractFieldsViaRegex($response, $aiFields);
    if (!empty($regexFields)) {
      $this->logger->notice('Recovered fields via regex extraction from malformed response.');
      return $regexFields;
    }

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
   * issues that prevent proper JSON parsing. Looks for "key": "value"
   * patterns for each expected field.
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
      // Match "fieldName": "value" or "fieldName": "value with \"escapes\""
      // Also handle the value spanning multiple lines.
      $pattern = '/"' . preg_quote($fieldName, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s';
      if (preg_match($pattern, $response, $matches)) {
        // Unescape JSON string escapes.
        $fields[$fieldName] = stripcslashes($matches[1]);
      }
    }

    // Only consider it a success if we extracted at least one field.
    return !empty($fields) ? $fields : [];
  }

  /**
   * Cleans potential markdown formatting from a JSON response.
   *
   * AI models often wrap JSON in markdown code fences, or include
   * introductory text before the JSON object. This method extracts
   * the actual JSON from the response.
   *
   * @param string $response
   *   The raw AI response.
   *
   * @return string
   *   The cleaned response containing only the JSON.
   */
  protected function cleanJsonResponse(string $response): string {
    $response = trim($response);

    // 1. Extract content from markdown code fences (```json ... ``` or ``` ... ```).
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $response, $matches)) {
      return trim($matches[1]);
    }

    // 2. Find the first { and last } to extract the JSON object.
    $firstBrace = strpos($response, '{');
    $lastBrace = strrpos($response, '}');
    if ($firstBrace !== FALSE && $lastBrace !== FALSE && $lastBrace > $firstBrace) {
      return substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
    }

    return $response;
  }

}
