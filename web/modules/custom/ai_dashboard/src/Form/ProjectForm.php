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
}