<?php

namespace Drupal\issue_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Displays a Daily Digest node detail page.
 */
class DailyDigestViewController extends ControllerBase {

  /**
   * Renders the daily digest detail page for a given node.
   */
  public function view(NodeInterface $node): array {
    if ($node->bundle() !== 'daily_digest') {
      throw new AccessDeniedHttpException();
    }

    $filePath = '';
    if (!$node->get('field_data_file')->isEmpty()) {
      /** @var \Drupal\file\Entity\File $file */
      $file = $node->get('field_data_file')->entity;
      if ($file) {
        $filePath = $file->getFileUri();
      }
    }

    return [
      '#theme' => 'daily_digest_view',
      '#title' => $node->label(),
      '#file_path' => $filePath,
      '#node' => $node,
      '#cache' => [
        'tags' => $node->getCacheTags(),
      ],
    ];
  }

}
