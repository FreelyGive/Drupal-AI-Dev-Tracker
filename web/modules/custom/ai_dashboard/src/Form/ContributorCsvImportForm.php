<?php

namespace Drupal\ai_dashboard\Form;

use Symfony\Component\HttpFoundation\Request;
use Drupal\ai_dashboard\Controller\ContributorCsvController;
use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for importing contributors from CSV.
 */
class ContributorCsvImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'contributor_csv_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#attributes'] = [
      'enctype' => 'multipart/form-data',
      'class' => ['contributor-csv-import-form'],
    ];

    $form['#attached']['library'][] = 'ai_dashboard/admin_forms';

    // Page header.
    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['page-header']],
    ];

    $form['header']['title'] = [
      '#markup' => '<h1 class="page-title">Import Contributors from CSV</h1>',
    ];

    $form['header']['description'] = [
      '#markup' => '<p class="page-description">Upload a CSV file to bulk import or update contributors. Use the template below to ensure proper formatting.</p>',
    ];

    // Instructions section.
    $form['instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructions'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['instructions-section']],
    ];

    $form['instructions']['steps'] = [
      '#markup' => '
        <div class="instruction-steps">
          <h3>How to use the CSV importer:</h3>
          <ol>
            <li><strong>Download the template:</strong> Click the "Download CSV Template" button below to get a properly formatted CSV file.</li>
            <li><strong>Edit the template:</strong> Open the CSV file in Excel, Google Sheets, or any spreadsheet application. Replace the sample data with your contributor information.</li>
            <li><strong>Required fields:</strong> Make sure to fill in at least the Full Name and Drupal Username for each contributor.</li>
            <li><strong>Company matching:</strong> Use exact company names. If a company doesn\'t exist, it will be created automatically.</li>
            <li><strong>Upload and import:</strong> Save your CSV file and upload it using the form below.</li>
          </ol>
        </div>',
    ];

    // CSV template download.
    $form['template'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['template-section']],
    ];

    $form['template']['download'] = [
      '#type' => 'link',
      '#title' => $this->t('📥 Download CSV Template'),
      '#url' => Url::fromRoute('ai_dashboard.contributor_csv_template'),
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'download-template-btn'],
        'target' => '_blank',
      ],
    ];

    $form['template']['info'] = [
      '#markup' => '<p class="template-info">The template contains sample data and shows the expected format. Replace the sample rows with your actual contributor data.</p>',
    ];

    // CSV format info.
    $form['format_info'] = [
      '#type' => 'details',
      '#title' => $this->t('CSV Format Details'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['format-info-section']],
    ];

    $form['format_info']['fields'] = [
      '#markup' => '
        <div class="csv-format-info">
          <h4>Required CSV Columns (in exact order):</h4>
          <ol>
            <li><strong>Name:</strong> The contributor\'s full name (required)</li>
            <li><strong>Username (d.o):</strong> Their drupal.org username (required, used for duplicate detection)</li>
            <li><strong>Organization:</strong> Company name (will be created if it doesn\'t exist)</li>
            <li><strong>AI Maker?:</strong> Is this an AI Maker? (Yes/No, Y/N, 1/0, True/False)</li>
            <li><strong>Tracker Role:</strong> One or more roles separated by commas (Developer, Front-end, Management, Designer, QA/Testing, DevOps, Project Manager)</li>
            <li><strong>Skills:</strong> Comma-separated skills (e.g., "PHP, JavaScript, AI/ML")</li>
            <li><strong>Commitment (days/week):</strong> Number of days per week (e.g., 5, 2.5)</li>
            <li><strong>Company Drupal Profile:</strong> Company name as it appears in drupal.org URLs (used as company unique identifier)</li>
            <li><strong>GitLab Username or Email:</strong> GitLab username or email address</li>
          </ol>
          <p><strong>Note:</strong> Existing contributors are identified by their Drupal username. Companies are identified by their Drupal profile name. Re-running the import will update existing records. AI Maker status can be set on both individuals and companies.</p>
        </div>',
    ];

    // File upload section.
    $form['upload_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Upload CSV File'),
      '#attributes' => ['class' => ['upload-section']],
    ];

    $form['upload_section']['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Choose CSV File'),
      '#description' => $this->t('Select the CSV file containing contributor data. Maximum file size: 2MB.'),
      '#attributes' => [
        'accept' => '.csv,text/csv',
        'class' => ['csv-file-input'],
      ],
    ];

    // Import options.
    $form['upload_section']['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Import Options'),
      '#open' => FALSE,
    ];

    $form['upload_section']['options']['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run (preview only)'),
      '#description' => $this->t('Check this to preview what would be imported without actually creating or updating contributors.'),
      '#default_value' => FALSE,
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['form-actions']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Contributors'),
      '#attributes' => ['class' => ['btn', 'btn-primary', 'import-btn']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $files = $this->getRequest()->files->get('files');

    if (empty($files['csv_file'])) {
      $form_state->setErrorByName('csv_file', $this->t('Please select a CSV file to upload.'));
      return;
    }

    $file = $files['csv_file'];

    // Check file type.
    $allowed_types = ['text/csv', 'application/csv', 'text/plain'];
    if (!in_array($file->getClientMimeType(), $allowed_types)) {
      $form_state->setErrorByName('csv_file', $this->t('Please upload a valid CSV file.'));
      return;
    }

    // Check file size (2MB limit)
    if ($file->getSize() > 2 * 1024 * 1024) {
      $form_state->setErrorByName('csv_file', $this->t('File is too large. Maximum size is 2MB.'));
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $files = $this->getRequest()->files->get('files');
    $file = $files['csv_file'];

    if ($file) {
      // Process the CSV file.
      $csv_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(ContributorCsvController::class);

      try {
        // Create a temporary request with the file.
        $request = new Request();
        $request->files->set('csv_file', $file);

        $response = $csv_controller->processImport($request);
        $result = json_decode($response->getContent(), TRUE);

        if ($result['success']) {
          // @phpcs:ignore
          $this->messenger()->addMessage($this->t($result['message']));

          if (!empty($result['results']['error_details'])) {
            foreach ($result['results']['error_details'] as $error) {
              $this->messenger()->addWarning($error);
            }
          }
        }
        else {
          // @phpcs:ignore
          $this->messenger()->addError($this->t($result['message']));
        }

      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Import failed: @message', ['@message' => $e->getMessage()]));
      }
    }
  }

}
