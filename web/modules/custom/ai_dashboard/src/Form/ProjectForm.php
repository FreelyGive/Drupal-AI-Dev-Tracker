<?php

namespace Drupal\ai_dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

/**
 * Form for creating AI Projects.
 */
class ProjectForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_dashboard_project_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#title'] = $this->t('Add New Project');
    
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Enter the name of your project. This will be used in the URL.'),
    ];

    $form['tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Issue Tags'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Enter the tag(s) to filter issues for this project. Separate multiple tags with commas (e.g., "Strategic Evolution, AI").'),
      '#placeholder' => $this->t('e.g., Strategic Evolution, priority'),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Optional description of the project.'),
      '#rows' => 4,
    ];

    // Add deliverable field
    $deliverable_options = $this->getDeliverableOptions();
    $form['deliverable'] = [
      '#type' => 'select',
      '#title' => $this->t('Primary Deliverable'),
      '#options' => $deliverable_options,
      '#empty_option' => $this->t('- None -'),
      '#description' => $this->t('Optionally link this project to a primary deliverable (AI Deliverable tagged issues).'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Project'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('ai_dashboard.projects'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $title = $form_state->getValue('title');
    
    // Check if project with this name already exists
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $existing = $storage->loadByProperties([
      'type' => 'ai_project',
      'title' => $title,
    ]);
    
    if (!empty($existing)) {
      $form_state->setErrorByName('title', $this->t('A project with this name already exists. Please choose a different name.'));
    }

    // Validate the slug will be valid
    $slug = $this->generateSlug($title);
    if (empty($slug)) {
      $form_state->setErrorByName('title', $this->t('Project name must contain at least one letter or number.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Create the project node
    $node = Node::create([
      'type' => 'ai_project',
      'title' => $values['title'],
      'field_project_tags' => [
        'value' => $values['tags'],
      ],
    ]);

    // Add description if provided
    if (!empty($values['description'])) {
      $node->set('body', [
        'value' => $values['description'],
        'format' => 'basic_html',
      ]);
    }

    // Add deliverable if selected
    if (!empty($values['deliverable'])) {
      $node->set('field_project_deliverable', [
        'target_id' => $values['deliverable'],
      ]);
    }

    $node->save();
    
    // Invalidate cache tags to ensure the projects list updates
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list:ai_project']);

    // Show success message
    $this->messenger()->addStatus($this->t('Project "@title" has been created.', [
      '@title' => $values['title'],
    ]));

    // Redirect to the projects list page
    $url = Url::fromRoute('ai_dashboard.projects');
    $form_state->setRedirectUrl($url);
  }

  /**
   * Generate URL-friendly slug from title.
   */
  protected function generateSlug($title) {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
  }

  /**
   * Get options for deliverable select field.
   */
  protected function getDeliverableOptions() {
    $options = [];

    // Load all AI Issues with "AI Deliverable" tag
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_tags', 'AI Deliverable', 'CONTAINS')
      ->sort('title', 'ASC')
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (!empty($nids)) {
      $nodes = $storage->loadMultiple($nids);
      foreach ($nodes as $node) {
        // Include issue number if available
        $issue_number = '';
        if ($node->hasField('field_issue_number') && !$node->get('field_issue_number')->isEmpty()) {
          $issue_number = '#' . $node->get('field_issue_number')->value . ' - ';
        }
        $options[$node->id()] = $issue_number . $node->getTitle();
      }
    }

    return $options;
  }
}