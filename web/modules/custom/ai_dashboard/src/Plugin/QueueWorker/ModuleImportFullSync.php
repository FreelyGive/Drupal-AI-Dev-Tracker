<?php

namespace Drupal\ai_dashboard\Plugin\QueueWorker;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\ai_dashboard\Service\IssueImportProcessService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Performs full syncronization of module issues from drupal.org.
 */
#[QueueWorker(
  id: "module_import_full_do",
  title: new TranslatableMarkup("Full import of module issues from drupal.org.")
)]
class ModuleImportFullSync extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * @var \Drupal\ai_dashboard\Service\IssueImportProcessService
   */
  protected IssueImportProcessService $issueProcessService;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->issueProcessService = $container->get('ai_dashboard.issue_import_process');
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $config = $this->entityTypeManager->getStorage('module_import')
      ->load($data[0]);
    assert($config instanceof ModuleImport);
    foreach ($data[1] as $issueData) {
      $this->issueProcessService->processIssue($issueData, $config);
    }
    // Last item in the queue should contain initial timestamp.
    if (!empty($data[2])) {
      $this->state->set('ai_dashboard:last_import:' . $config->id(), $data[2]);
    }
  }

}
