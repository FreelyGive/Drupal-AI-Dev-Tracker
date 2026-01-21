<?php

namespace Drupal\meta_issue_editor\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for parsing issue references from meta-issue body text.
 */
class MetaIssueParserService {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a MetaIssueParserService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Parse issue references from HTML/text content.
   *
   * Finds patterns like [#1234567] or #1234567 in the text.
   *
   * @param string $content
   *   The HTML or text content to parse.
   *
   * @return array
   *   Array of unique issue numbers found.
   */
  public function parseIssueReferences(string $content): array {
    $issues = [];

    // Match [#1234567] format (with brackets).
    if (preg_match_all('/\[#(\d{5,8})\]/', $content, $matches)) {
      $issues = array_merge($issues, $matches[1]);
    }

    // Also match standalone #1234567 format (without brackets, word boundary).
    if (preg_match_all('/(?<!\[)#(\d{5,8})(?!\])/', $content, $matches)) {
      $issues = array_merge($issues, $matches[1]);
    }

    // Return unique issue numbers as integers.
    $issues = array_unique($issues);
    $issues = array_map('intval', $issues);
    sort($issues);

    $this->loggerFactory->get('meta_issue_editor')->debug('Parsed @count issue references from content', [
      '@count' => count($issues),
    ]);

    return $issues;
  }

  /**
   * Convert issue references in HTML to placeholder markers.
   *
   * This prepares content for the TipTap editor by marking where
   * issue blocks should be inserted.
   *
   * @param string $content
   *   The HTML content.
   *
   * @return string
   *   Content with issue references marked for TipTap.
   */
  public function markIssueReferences(string $content): string {
    // Replace [#1234567] with a data attribute marker.
    $content = preg_replace(
      '/\[#(\d{5,8})\]/',
      '<span data-issue-ref="$1" class="issue-reference">[#$1]</span>',
      $content
    );

    return $content;
  }

  /**
   * Extract issue references with context (list item, heading, etc.).
   *
   * @param string $content
   *   The HTML content.
   *
   * @return array
   *   Array of issue data with context information.
   */
  public function parseIssueReferencesWithContext(string $content): array {
    $results = [];

    // Load as DOM to understand structure.
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $xpath = new \DOMXPath($dom);

    // Find all text nodes containing issue references.
    $textNodes = $xpath->query('//text()[contains(., "#")]');

    foreach ($textNodes as $textNode) {
      $text = $textNode->nodeValue;
      if (preg_match_all('/\[#(\d{5,8})\]/', $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as $match) {
          $issueNumber = (int) $match[0];
          $parent = $textNode->parentNode;
          $context = $this->determineContext($parent);

          $results[] = [
            'issue_number' => $issueNumber,
            'context' => $context,
            'parent_tag' => $parent->nodeName,
          ];
        }
      }
    }

    return $results;
  }

  /**
   * Determine the context type for a DOM node.
   *
   * @param \DOMNode $node
   *   The DOM node.
   *
   * @return string
   *   Context type: 'list_item', 'heading', 'paragraph', 'unknown'.
   */
  protected function determineContext(\DOMNode $node): string {
    $current = $node;

    while ($current && $current->nodeType === XML_ELEMENT_NODE) {
      $tagName = strtolower($current->nodeName);

      if ($tagName === 'li') {
        return 'list_item';
      }
      if (in_array($tagName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
        return 'heading';
      }
      if ($tagName === 'p') {
        return 'paragraph';
      }

      $current = $current->parentNode;
    }

    return 'unknown';
  }

}
