<?php

namespace Drupal\ai_dashboard;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of Module import entities.
 */
class ModuleImportListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Name');
    $header['source_type'] = $this->t('Source Type');
    $header['project_id'] = $this->t('Project ID');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ai_dashboard\Entity\ModuleImport $entity */
    $row['label'] = $entity->label();

    // Get source type label.
    $source_types = [
      'drupal_org' => $this->t('Drupal.org'),
      'gitlab' => $this->t('GitLab'),
      'github' => $this->t('GitHub'),
    ];
    $source_type = $entity->getSourceType();
    $row['source_type'] = $source_types[$source_type] ?? $source_type;

    $row['project_id'] = $entity->getProjectId();
    $row['status'] = $entity->isActive() ? $this->t('Active') : $this->t('Inactive');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

    // Add a run import operation.
    $operations['run'] = [
      'title' => $this->t('Run import'),
      'weight' => 10,
      'url' => Url::fromRoute('ai_dashboard.module_import.run', ['module_import' => $entity->id()]),
    ];

    return $operations;
  }

}
