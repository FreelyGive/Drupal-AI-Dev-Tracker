<?php

namespace Drupal\ai_dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_dashboard\Service\IssueImportService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides a bulk operations form for module import configurations.
 */
class ModuleImportBulkForm extends FormBase {

  /**
   * The issue import service.
   *
   * @var \Drupal\ai_dashboard\Service\IssueImportService
   */
  protected $issueImportService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ModuleImportBulkForm object.
   *
   * @param \Drupal\ai_dashboard\Service\IssueImportService $issue_import_service
   *   The issue import service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(IssueImportService $issue_import_service, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    $this->issueImportService = $issue_import_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_dashboard.issue_import'),
      $container->get('entity_type.manager'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_dashboard_module_import_bulk_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Bulk operations will be placed below the table.
    $form['bulk_operations'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bulk-operations-container', 'bulk-operations-bottom']],
      '#weight' => 10,
    ];

    $form['bulk_operations']['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Select configurations below and choose an action to perform on multiple items at once.') . '</p>',
    ];

    $form['bulk_operations']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline', 'bulk-actions']],
    ];

    $form['bulk_operations']['actions']['select_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['select-controls']],
    ];

    $form['bulk_operations']['actions']['select_controls']['select_all'] = [
      '#type' => 'button',
      '#value' => $this->t('Select all'),
      '#attributes' => [
        'class' => ['button', 'js-select-all'],
        'data-target' => '.bulk-select',
      ],
    ];

    $form['bulk_operations']['actions']['select_controls']['select_none'] = [
      '#type' => 'button',
      '#value' => $this->t('Select none'),
      '#attributes' => [
        'class' => ['button', 'js-select-none'],
        'data-target' => '.bulk-select',
      ],
    ];

    $form['bulk_operations']['actions']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#title_display' => 'invisible',
      '#options' => [
        '' => $this->t('- Select action -'),
        'run_selected' => $this->t('Run selected imports'),
      ],
      '#default_value' => 'run_selected',
    ];

    $form['bulk_operations']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply to selected items'),
      '#attributes' => ['class' => ['button--primary']],
      '#states' => [
        'disabled' => [
          'select[name="action"]' => ['value' => ''],
        ],
      ],
    ];

    // The table and pager will be added by ModuleImportListBuilder.
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $action = $form_state->getValue('action');
    
    if (empty($action)) {
      $form_state->setErrorByName('action', $this->t('Please select an action to perform.'));
      return;
    }
    
    $selected_items = [];
    $input = $form_state->getUserInput();
    
    // Find selected checkboxes from the table rows.
    foreach ($input as $key => $value) {
      if (strpos($key, 'table') === 0 && is_array($value)) {
        foreach ($value as $row_key => $row_data) {
          if (isset($row_data['select']) && $row_data['select']) {
            $selected_items[] = $row_data['select'];
          }
        }
      }
    }

    if (empty($selected_items)) {
      $form_state->setErrorByName('action', $this->t('Please select at least one configuration to perform the action on.'));
      return;
    }

    // Store selected items for use in submit handler.
    $form_state->setValue('selected_items', $selected_items);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $action = $form_state->getValue('action');
    $selected_items = $form_state->getValue('selected_items');
    
    if (empty($selected_items)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('module_import');
    $configurations = $storage->loadMultiple($selected_items);

    switch ($action) {
      case 'run_selected':
        $this->runSelectedImports($configurations);
        break;
    }
  }

  /**
   * Run selected import configurations.
   *
   * @param array $configurations
   *   Array of ModuleImport entities.
   */
  protected function runSelectedImports(array $configurations) {
    $count = 0;
    $errors = 0;

    foreach ($configurations as $config) {
      /** @var \Drupal\ai_dashboard\Entity\ModuleImport $config */
      if (!$config->isActive()) {
        $this->messenger->addWarning($this->t('Skipped inactive configuration: @label', [
          '@label' => $config->label(),
        ]));
        continue;
      }

      try {
        // Use batch import for multiple configurations.
        $result = $this->issueImportService->import($config);
        
        if ($result['success']) {
          $count++;
          $this->messenger->addMessage($this->t('Started import for @label', [
            '@label' => $config->label(),
          ]));
        } else {
          $errors++;
          $this->messenger->addError($this->t('Failed to start import for @label: @message', [
            '@label' => $config->label(),
            '@message' => $result['message'] ?? 'Unknown error',
          ]));
        }
      }
      catch (\Exception $e) {
        $errors++;
        $this->messenger->addError($this->t('Error running import for @label: @message', [
          '@label' => $config->label(),
          '@message' => $e->getMessage(),
        ]));
      }
    }

    if ($count > 0) {
      $this->messenger->addMessage($this->t('Successfully started @count import(s).', ['@count' => $count]));
    }
    
    if ($errors > 0) {
      $this->messenger->addError($this->t('@count import(s) failed to start.', ['@count' => $errors]));
    }
  }

  /**
   * Clear configuration-related caches.
   */
  protected function clearConfigurationCache() {
    // Clear various caches that might affect the configuration listing.
    \Drupal::service('cache.render')->deleteAll();
    \Drupal::service('cache.discovery')->deleteAll();
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['config:module_import_list']);
  }

}