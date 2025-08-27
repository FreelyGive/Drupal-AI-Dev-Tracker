<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for admin tools interface.
 */
class AdminToolsController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an AdminToolsController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Display the admin tools landing page.
   */
  public function adminToolsLanding() {
    $build = [];

    // Add admin navigation.
    $build['navigation'] = $this->buildAdminNavigation();

    // Add the main content.
    $build['content'] = [
      '#theme' => 'admin_tools_landing',
      '#attached' => [
        'library' => ['ai_dashboard/admin_tools'],
      ],
    ];

    return $build;
  }

  /**
   * Build the admin navigation component.
   */
  public function buildAdminNavigation($active_page = 'dashboard') {
    $navigation_items = [
      'dashboard' => [
        'title' => '← Back to Calendar',
        'url' => '/ai-dashboard/calendar',
        'icon' => '📅',
        'description' => 'Return to calendar view',
        'is_primary' => TRUE,
      ],
      'contributors' => [
        'title' => 'Contributors',
        'url' => '/ai-dashboard/admin/contributors',
        'icon' => '👥',
        'description' => 'Manage team members',
      ],
      'issues' => [
        'title' => 'Issues',
        'url' => '/ai-dashboard/admin/issues',
        'icon' => '🐛',
        'description' => 'View and manage issues',
      ],
      'import_contributors' => [
        'title' => 'Import Contributors',
        'url' => '/ai-dashboard/admin/contributor-import',
        'icon' => '📥',
        'description' => 'Bulk import from CSV',
      ],
      'import_issues' => [
        'title' => 'Import Issues',
        'url' => '/ai-dashboard/admin/import',
        'icon' => '🔄',
        'description' => 'Import from drupal.org',
      ],
      'tag_mappings' => [
        'title' => 'Tag Mappings',
        'url' => '/ai-dashboard/admin/tag-mappings',
        'icon' => '🏷️',
        'description' => 'Configure tag mappings and analyze tags',
      ],
      'documentation' => [
        'title' => 'Documentation',
        'url' => '/ai-dashboard/admin/documentation',
        'icon' => '📄',
        'description' => 'Technical documentation',
      ],
    ];

    return [
      '#theme' => 'admin_navigation',
      '#navigation_items' => $navigation_items,
      '#active_page' => $active_page,
      '#attached' => [
        'library' => ['ai_dashboard/admin_navigation'],
      ],
    ];
  }

  /**
   * Get stats for the dashboard.
   */
  protected function getAdminStats() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Get counts.
    $contributors_count = count($node_storage->loadByProperties(['type' => 'ai_contributor']));
    $issues_count = count($node_storage->loadByProperties(['type' => 'ai_issue']));
    $companies_count = count($node_storage->loadByProperties(['type' => 'ai_company']));
    $tag_mappings_count = count($node_storage->loadByProperties(['type' => 'ai_tag_mapping']));
    $import_configs_count = count($node_storage->loadByProperties(['type' => 'ai_import_config']));

    // Get recent activity.
    $recent_issues = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->sort('changed', 'DESC')
      ->range(0, 5)
      ->accessCheck(FALSE)
      ->execute();

    $recent_contributors = $node_storage->getQuery()
      ->condition('type', 'ai_contributor')
      ->sort('changed', 'DESC')
      ->range(0, 5)
      ->accessCheck(FALSE)
      ->execute();

    return [
      'counts' => [
        'contributors' => $contributors_count,
        'issues' => $issues_count,
        'companies' => $companies_count,
        'tag_mappings' => $tag_mappings_count,
        'import_configs' => $import_configs_count,
      ],
      'recent' => [
        'issues' => $recent_issues,
        'contributors' => $recent_contributors,
      ],
    ];
  }

}
