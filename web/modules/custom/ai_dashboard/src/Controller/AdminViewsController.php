<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;

/**
 * Controller for admin views with navigation.
 */
class AdminViewsController extends ControllerBase {

  /**
   * Display contributors admin page with navigation.
   */
  public function contributorsAdmin() {
    $build = [];

    // Add admin navigation
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('contributors');

    // Load and render the view
    try {
      $view = Views::getView('ai_contributors_admin');
      if ($view && $view->access('page_1')) {
        $view->setDisplay('page_1');
        $view->preExecute();
        $view->execute();
        $build['view'] = $view->buildRenderable('page_1');
      } else {
        $build['error'] = [
          '#markup' => '<div class="messages messages--error">Contributors view not found or access denied.</div>',
        ];
      }
    } catch (\Exception $e) {
      $build['error'] = [
        '#markup' => '<div class="messages messages--error">Error loading contributors view: ' . $e->getMessage() . '</div>',
      ];
    }

    return $build;
  }

  /**
   * Display issues admin page with navigation.
   */
  public function issuesAdmin() {
    $build = [];

    // Add admin navigation
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('issues');

    // Load and render the view
    try {
      $view = Views::getView('ai_issues_admin');
      if ($view && $view->access('page_1')) {
        $view->setDisplay('page_1');
        $view->preExecute();
        $view->execute();
        $build['view'] = $view->buildRenderable('page_1');
      } else {
        $build['error'] = [
          '#markup' => '<div class="messages messages--error">Issues view not found or access denied.</div>',
        ];
      }
    } catch (\Exception $e) {
      $build['error'] = [
        '#markup' => '<div class="messages messages--error">Error loading issues view: ' . $e->getMessage() . '</div>',
      ];
    }

    return $build;
  }

  /**
   * Display tag mappings admin page with navigation.
   */
  public function tagMappingsAdmin() {
    $build = [];

    // Add admin navigation
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('tag_mappings');

    // Load and render the view
    try {
      $view = Views::getView('ai_tag_mappings_admin');
      if ($view && $view->access('page_1')) {
        $view->setDisplay('page_1');
        $view->preExecute();
        $view->execute();
        $build['view'] = $view->buildRenderable('page_1');
      } else {
        $build['error'] = [
          '#markup' => '<div class="messages messages--error">Tag mappings view not found or access denied.</div>',
        ];
      }
    } catch (\Exception $e) {
      $build['error'] = [
        '#markup' => '<div class="messages messages--error">Error loading tag mappings view: ' . $e->getMessage() . '</div>',
      ];
    }

    return $build;
  }
}