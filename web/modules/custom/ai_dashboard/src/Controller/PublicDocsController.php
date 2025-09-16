<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Controller for public documentation page.
 */
class PublicDocsController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a PublicDocsController.
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
   * Displays the public documentation page.
   */
  public function view() {
    $build = [
      '#theme' => 'ai_dashboard_public_docs',
      '#import_configs' => [], // No longer needed, using link instead
      '#attached' => [
        'library' => [
          'ai_dashboard/shared_components',
        ],
      ],
      '#cache' => [
        'tags' => [
          'config:import_configuration_list',
        ],
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

  /**
   * Title callback for the docs page.
   */
  public function title() {
    return 'AI Dashboard Documentation';
  }
}