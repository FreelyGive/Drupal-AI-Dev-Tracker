<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for displaying AI Dashboard documentation.
 */
class DocumentationController extends ControllerBase {

  /**
   * Display the technical documentation.
   */
  public function view() {
    $module_path = \Drupal::service('extension.list.module')->getPath('ai_dashboard');
    $doc_file = $module_path . '/AI_DASHBOARD_DOCUMENTATION.md';

    if (!file_exists($doc_file)) {
      return [
        '#markup' => '<div class="messages messages--error">Documentation file not found.</div>',
      ];
    }

    $content = file_get_contents($doc_file);

    // Convert markdown to HTML (basic conversion)
    $content = $this->markdownToHtml($content);

    $build = [];

    // Add admin navigation.
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('documentation');

    // Documentation content.
    $build['documentation'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-dashboard-documentation']],
      'content' => [
        '#markup' => $content,
      ],
    ];

    // Add some basic styling.
    $build['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .ai-dashboard-documentation {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
          }
          .ai-dashboard-documentation h1 { color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
          .ai-dashboard-documentation h2 { color: #333; margin-top: 30px; }
          .ai-dashboard-documentation h3 { color: #666; }
          .ai-dashboard-documentation code { 
            background: #f4f4f4; 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-family: Monaco, Consolas, monospace;
          }
          .ai-dashboard-documentation pre { 
            background: #f8f8f8; 
            padding: 15px; 
            border-radius: 5px; 
            overflow-x: auto;
            border-left: 4px solid #0073aa;
          }
          .ai-dashboard-documentation pre code { background: none; padding: 0; }
          .ai-dashboard-documentation table { 
            border-collapse: collapse; 
            width: 100%; 
            margin: 15px 0;
          }
          .ai-dashboard-documentation th, .ai-dashboard-documentation td { 
            border: 1px solid #ddd; 
            padding: 8px 12px; 
            text-align: left;
          }
          .ai-dashboard-documentation th { background: #f5f5f5; font-weight: bold; }
          .ai-dashboard-documentation blockquote {
            border-left: 4px solid #ddd;
            margin: 15px 0;
            padding: 10px 20px;
            background: #f9f9f9;
          }
          .ai-dashboard-documentation a { color: #0073aa; text-decoration: none; }
          .ai-dashboard-documentation a:hover { text-decoration: underline; }
          .ai-dashboard-documentation ul, .ai-dashboard-documentation ol { margin: 10px 0; padding-left: 30px; }
          .ai-dashboard-documentation li { margin: 5px 0; }
        ',
      ],
      'ai-dashboard-documentation-styles',
    ];

    return $build;
  }

  /**
   * Basic markdown to HTML conversion.
   */
  private function markdownToHtml($content) {
    if (empty($content)) {
      return '<p>No documentation content available.</p>';
    }

    // Escape HTML first for security.
    $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

    // Convert headers - be more careful with regex.
    $content = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $content);
    $content = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $content);
    $content = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $content);

    // Convert code blocks - handle multiline more carefully and preserve HTML in specific blocks.
    $content = preg_replace_callback('/```[\w]*\n(.*?)\n```/s', function($matches) {
      $code_content = $matches[1];
      
      // For AI Tracker metadata blocks, keep HTML tags as displayable text but render line breaks
      if (strpos($code_content, '--- AI TRACKER METADATA ---') !== false) {
        // Convert newlines to <br> tags for proper display while keeping HTML tags as text
        $code_content = nl2br($code_content);
        return '<div class="ai-tracker-metadata-template" style="background: #f8f8f8; padding: 15px; border-radius: 5px; border-left: 4px solid #0073aa; font-family: Monaco, Consolas, monospace; cursor: text; user-select: all; border: 1px solid #ddd;">' . 
               '<div style="margin-bottom: 10px; font-weight: bold; color: #0073aa;">📋 Copy this HTML template for Drupal.org issues:</div>' .
               '<div style="background: white; padding: 10px; border: 1px dashed #ccc; border-radius: 3px;">' . $code_content . '</div>' .
               '</div>';
      }
      
      return '<pre><code>' . $code_content . '</code></pre>';
    }, $content);

    // Convert inline code.
    $content = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $content);

    // Convert links - be more specific.
    $content = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $content);

    // Convert bold.
    $content = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $content);

    // Convert lists - simple approach.
    $lines = explode("\n", $content);
    $in_list = FALSE;
    $result_lines = [];

    foreach ($lines as $line) {
      if (preg_match('/^- (.+)$/', $line, $matches)) {
        if (!$in_list) {
          $result_lines[] = '<ul>';
          $in_list = TRUE;
        }
        $result_lines[] = '<li>' . $matches[1] . '</li>';
      }
      else {
        if ($in_list) {
          $result_lines[] = '</ul>';
          $in_list = FALSE;
        }
        $result_lines[] = $line;
      }
    }

    if ($in_list) {
      $result_lines[] = '</ul>';
    }

    $content = implode("\n", $result_lines);

    // Convert line breaks to paragraphs - simpler approach.
    $paragraphs = explode("\n\n", $content);
    $html_paragraphs = [];

    foreach ($paragraphs as $paragraph) {
      $paragraph = trim($paragraph);
      if (!empty($paragraph)) {
        // Don't wrap if it's already an HTML element.
        if (!preg_match('/^<(h[1-6]|pre|ul|ol|div|table)/i', $paragraph)) {
          $paragraph = '<p>' . nl2br($paragraph) . '</p>';
        }
        $html_paragraphs[] = $paragraph;
      }
    }

    return implode("\n", $html_paragraphs);
  }

}
