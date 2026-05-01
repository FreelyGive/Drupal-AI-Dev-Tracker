<?php

namespace Drupal\issue_analysis\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Thin wrapper around the Drupal AI provider for one-shot chat completions.
 *
 * Callers build the prompt; this class handles provider resolution, the API
 * call, and stripping markdown code fences from the response.
 */
class AiSummariserService {

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * Sends a prompt to the default chat provider and returns the response text.
   *
   * @param string $prompt
   *   The full prompt to send.
   * @param string[] $tags
   *   Optional tags forwarded to the provider for logging/routing.
   *
   * @return string
   *   Raw text response from the LLM.
   *
   * @throws \RuntimeException
   *   When no default chat provider is configured.
   */
  public function complete(string $prompt, array $tags = []): string {
    $providerData = $this->aiProvider->getDefaultProviderForOperationType('chat');
    if (!$providerData) {
      throw new \RuntimeException('No default AI provider configured for chat operations.');
    }

    $provider = $this->aiProvider->createInstance($providerData['provider_id']);
    $model = $providerData['model_id'];

    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $output = $provider->chat($input, $model, $tags);
    $text = trim($output->getNormalized()->getText());

    // Strip markdown code fences if the LLM wraps output in them.
    $text = preg_replace('/^```(?:json|markdown|text)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/m', '', $text);

    return trim($text);
  }

  /**
   * Like complete(), but additionally JSON-decodes and validates the response.
   *
   * @return array<string, mixed>
   *   Decoded JSON response.
   *
   * @throws \RuntimeException
   *   When the LLM returns text that is not valid JSON.
   */
  public function completeJson(string $prompt, array $tags = []): array {
    $text = $this->complete($prompt, $tags);
    $data = json_decode($text, TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('LLM returned invalid JSON: ' . $text);
    }
    return $data;
  }

}
