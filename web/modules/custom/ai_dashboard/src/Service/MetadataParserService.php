<?php

namespace Drupal\ai_dashboard\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for parsing AI Tracker metadata from issue summaries.
 */
class MetadataParserService {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a MetadataParserService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Parse AI Tracker metadata from issue summary text.
   *
   * @param string $summary
   *   The issue summary HTML content.
   *
   * @return array
   *   Array of parsed metadata fields.
   */
  public function parseMetadata($summary) {
    if (empty($summary)) {
      return [];
    }

    $metadata = [];
    $metadata_block = '';

    // Try new [Tracker] format first
    $pattern_new = '/\[Tracker\](.*?)\[\/Tracker\]/s';

    // Try legacy format
    $pattern_legacy = '/--- AI TRACKER METADATA ---(.*?)--- END METADATA ---/s';

    if (preg_match($pattern_new, $summary, $matches)) {
      $metadata_block = $matches[1];
      $this->loggerFactory->get('ai_dashboard')->debug('Found [Tracker] format metadata block');
    }
    elseif (preg_match($pattern_legacy, $summary, $matches)) {
      $metadata_block = $matches[1];
      $this->loggerFactory->get('ai_dashboard')->debug('Found legacy AI TRACKER METADATA format block');
    }

    if (!empty($metadata_block)) {
      // Parse individual metadata fields
      $metadata = $this->parseMetadataFields($metadata_block);

      // Validate parsed data to prevent dummy/template imports
      if ($this->isTemplateData($metadata)) {
        $this->loggerFactory->get('ai_dashboard')->debug('Ignoring template/dummy metadata');
        return [];
      }

      $this->loggerFactory->get('ai_dashboard')->info('Parsed AI Tracker metadata with @count fields', [
        '@count' => count($metadata),
      ]);
    } else {
      // Log when no metadata block is found
      $this->loggerFactory->get('ai_dashboard')->debug('No AI Tracker metadata block found in issue summary');
    }

    return $metadata;
  }

  /**
   * Parse individual metadata fields from the metadata block.
   *
   * @param string $metadata_block
   *   The raw metadata block content.
   *
   * @return array
   *   Array of parsed metadata fields.
   */
  protected function parseMetadataFields($metadata_block) {
    $metadata = [];

    // Define the expected metadata fields and their patterns.
    // Handle both HTML line breaks and actual newlines.
    $field_patterns = [
      'update_summary' => '/Update Summary:\s*(.+?)(?=<br|\\n|$)/i',
      'short_title' => '/Short Title:\s*(.+?)(?=<br|\\n|$)/i',
      'short_description' => '/Short Description:\s*(.+?)(?=<br|\\n|$)/i',
      'checkin_date' => '/Check-in Date:\s*(.+?)(?=<br|\\n|$)/i',
      'due_date' => '/Due Date:\s*(.+?)(?=<br|\\n|$)/i',
      'blocked_by' => '/Blocked by:\s*(.+?)(?=<br|\\n|$)/i',
      'additional_collaborators' => '/Additional Collaborators:\s*(.+?)(?=<br|\\n|$)/i',
    ];

    foreach ($field_patterns as $field_name => $pattern) {
      if (preg_match($pattern, $metadata_block, $matches)) {
        $value = trim(strip_tags($matches[1]));
        
        // Clean up HTML entities
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        
        // Special processing for certain fields
        switch ($field_name) {
          case 'blocked_by':
            $metadata[$field_name] = $this->parseBlockedByField($value);
            break;
            
          case 'additional_collaborators':
            $metadata[$field_name] = $this->parseCollaboratorsField($value);
            break;
            
          case 'checkin_date':
          case 'due_date':
            $metadata[$field_name] = $this->parseDateField($value);
            break;
            
          default:
            $metadata[$field_name] = $value;
        }
        
        $this->loggerFactory->get('ai_dashboard')->debug('Parsed metadata field @field: @value', [
          '@field' => $field_name,
          '@value' => is_array($metadata[$field_name]) ? implode(', ', $metadata[$field_name]) : $metadata[$field_name],
        ]);
      }
    }

    return $metadata;
  }

  /**
   * Parse the blocked by field to extract issue numbers.
   *
   * @param string $value
   *   The raw blocked by field value.
   *
   * @return string
   *   Comma-separated issue numbers.
   */
  protected function parseBlockedByField($value) {
    if (empty($value)) {
      return '';
    }

    // Extract issue numbers like #3412340, #3412341
    preg_match_all('/#?(\d{7,})/', $value, $matches);
    
    if (!empty($matches[1])) {
      return implode(', ', array_unique($matches[1]));
    }
    // No digits found: treat placeholders (e.g., "[#XXXXXX@]") as no data
    return '';
  }

  /**
   * Parse the additional collaborators field to extract usernames.
   *
   * @param string $value
   *   The raw collaborators field value.
   *
   * @return string
   *   Comma-separated usernames.
   */
  protected function parseCollaboratorsField($value) {
    if (empty($value)) {
      return '';
    }

    // Extract usernames like @username1, @username2
    preg_match_all('/@([a-zA-Z0-9_-]+)/', $value, $matches);
    
    if (!empty($matches[1])) {
      return implode(', ', array_unique($matches[1]));
    }
    // If no usernames detected, treat as empty (ignore template placeholders)
    return '';
  }

  /**
   * Parse date field to normalize format.
   *
   * @param string $value
   *   The raw date field value.
   *
   * @return string
   *   Normalized date string.
   */
  protected function parseDateField($value) {
    if (empty($value)) {
      return '';
    }

    // Try to parse common date formats
    $formats = [
      'MM/dd/yyyy',
      'm/d/Y',
      'Y-m-d',
      'd/m/Y',
      'M j, Y',
      'F j, Y'
    ];

    foreach ($formats as $format) {
      $date = \DateTime::createFromFormat($format, $value);
      if ($date !== false) {
        return $date->format('Y-m-d');
      }
    }

    // If no format matches, check if it's template text
    if (preg_match('/^(MM\/DD\/YYYY|DD\/MM\/YYYY|\[.*\])/', $value)) {
      return '';
    }

    return $value;
  }

  /**
   * Check if metadata contains template/dummy data.
   *
   * @param array $metadata
   *   The parsed metadata array.
   *
   * @return bool
   *   TRUE if this appears to be template data, FALSE otherwise.
   */
  protected function isTemplateData(array $metadata) {
    // Check for common template patterns.
    $template_patterns = [
      '/\[One-line.*?\]/',
      '/\[Simple.*?\]/',
      '/\[#XXXXXX\]/',
      '/\[@username[12]\]/',
      '/MM\/DD\/YYYY/',
      '/\[.*stakeholders.*\]/',
      '/\[.*summary.*\]/',
      '/\[.*Drupalisms.*\]/i',
    ];

    foreach ($metadata as $field => $value) {
      if (empty($value)) {
        continue;
      }

      // Check each pattern
      foreach ($template_patterns as $pattern) {
        if (preg_match($pattern, $value)) {
          $this->loggerFactory->get('ai_dashboard')->debug('Template data detected in field @field: @value', [
            '@field' => $field,
            '@value' => $value,
          ]);
          return TRUE;
        }
      }
    }

    // Also check if all required fields are template text
    if (!empty($metadata['update_summary']) &&
        preg_match('/^\[.*\]$/', trim($metadata['update_summary']))) {
      return TRUE;
    }

    return FALSE;
  }

}
