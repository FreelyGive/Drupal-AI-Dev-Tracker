<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for CSV import/export of contributors.
 */
class ContributorCsvController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ContributorCsvController object.
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
   * Display the CSV import form.
   */
  public function importForm() {
    $build = [];

    // Add admin navigation.
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('import_contributors');

    // Add the form.
    $form = \Drupal::formBuilder()->getForm('Drupal\ai_dashboard\Form\ContributorCsvImportForm');
    $build['form'] = $form;

    return $build;
  }

  /**
   * Download CSV template.
   */
  public function downloadTemplate() {
    // Get sample data for template.
    $companies = $this->getCompanyList();

    $headers = [
      'name',
      'drupal_username', 
      'organization',
      'ai_maker',
      'tracker_role',
      'skills',
      'weekly_commitment',
      'company_drupal_profile',
      'current_focus',
      'org_role',
      'drupal_slack_username',
      'time_zone',
      'supporting_modules',
      'gitlab_username',
    ];

    // Create sample rows.
    $sample_rows = [
      [
        'John Doe',
        'john_doe',
        array_key_exists('Acquia', $companies) ? 'Acquia' : array_keys($companies)[0] ?? 'Example Company',
        'Yes',
        'Developer, Organizational',
        'PHP, JavaScript, Drupal',
        '5',
        'acquia',
        'Working on AI provider integrations and core infrastructure',
        'Senior Developer',
        'john_doe',
        'UTC',
        'ai, ai_provider_openai, search_api',
        'john.doe@example.com',
      ],
      [
        'Jane Smith',
        'jane_smith',
        array_key_exists('Lullabot', $companies) ? 'Lullabot' : array_keys($companies)[1] ?? 'Another Company',
        'No',
        'Organizational',
        'AI/ML, Python, DevOps',
        '3',
        'lullabot',
        'Managing project timelines and stakeholder communications',
        'Programme Manager',
        'jsmith_slack',
        'EST',
        'ai, project_management',
        'jsmith_gitlab',
      ],
      [
        'Alex Taylor',
        'alex_taylor',
        array_keys($companies)[2] ?? 'Third Company',
        'No',
        'Developer',
        'AI/ML, Python, DevOps',
        '3',
        'example_profile',
        'Frontend development and UX improvements',
        'Frontend Developer',
        'alex_t',
        'PST',
        'ai, frontend',
        'ataylor@example.com',
      ],
    ];

    // Generate CSV content.
    $csv_content = $this->generateCsvContent($headers, $sample_rows);

    // Create response.
    $response = new Response($csv_content);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="contributors_template.csv"');

    return $response;
  }

  /**
   * Process CSV import.
   */
  public function processImport(Request $request) {
    try {
      \Drupal::logger('ai_dashboard')->info('CSV import request received');

      // Check if file was uploaded via FormData.
      $file = $request->files->get('csv_file');

      \Drupal::logger('ai_dashboard')->info('File upload check: @file', ['@file' => $file ? 'Found' : 'Not found']);

      if (empty($file)) {
        throw new \Exception('No file uploaded');
      }
      $file_path = $file->getPathname();

      if (!file_exists($file_path)) {
        throw new \Exception('Uploaded file not found');
      }

      // Parse CSV.
      $results = $this->parseCsv($file_path);

      return new JsonResponse([
        'success' => TRUE,
        'message' => sprintf('Successfully processed %d contributors. Created: %d, Updated: %d, Errors: %d',
          $results['total'], $results['created'], $results['updated'], $results['errors']),
        'results' => $results,
      ]);

    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('CSV import error: @message', ['@message' => $e->getMessage()]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Import failed: ' . $e->getMessage(),
      ]);
    }
  }

  /**
   * Import contributors from a CSV file path.
   *
   * This method is designed to be called from drush commands or other code
   * that already has access to a file path (not an uploaded file).
   *
   * @param string $file_path
   *   The path to the CSV file to import.
   *
   * @return array
   *   Results array with keys: total, created, updated, errors, error_details.
   *
   * @throws \Exception
   *   If the file doesn't exist or has invalid format.
   */
  public function importFromFilePath($file_path) {
    if (!file_exists($file_path)) {
      throw new \Exception("CSV file not found: {$file_path}");
    }

    return $this->parseCsv($file_path);
  }

  /**
   * Parse CSV file and create/update contributors.
   */
  protected function parseCsv($file_path) {
    $results = [
      'total' => 0,
      'created' => 0,
      'updated' => 0,
      'errors' => 0,
      'error_details' => [],
    ];

    // Handle CSV encoding issues (Mac Excel, etc.)
    $content = file_get_contents($file_path);
    
    // Fix common character corruptions
    $content = str_replace('‡', 'á', $content);
    $content = str_replace('â€¡', 'á', $content);
    $content = str_replace('Â', 'ý', $content);
    
    // Convert from common encodings if needed
    if (!mb_check_encoding($content, 'UTF-8')) {
      $encodings = ['Windows-1252', 'ISO-8859-1', 'CP1252'];
      
      foreach ($encodings as $encoding) {
        $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
        if ($converted && mb_check_encoding($converted, 'UTF-8')) {
          $content = $converted;
          // Apply character fixes after conversion
          $content = str_replace('‡', 'á', $content);
          $content = str_replace('â€¡', 'á', $content);
          $content = str_replace('Â', 'ý', $content);
          break;
        }
      }
      
      $temp_file = tempnam(sys_get_temp_dir(), 'csv_utf8_');
      file_put_contents($temp_file, $content);
      $file_path = $temp_file;
    }
    
    if (($handle = fopen($file_path, 'r')) !== FALSE) {
      $header = fgetcsv($handle);

      if (!$header || !$this->validateCsvHeaders($header)) {
        throw new \Exception('Invalid CSV format. Please use the provided template.');
      }

      $row_number = 1;
      while (($data = fgetcsv($handle)) !== FALSE) {
        $row_number++;
        $results['total']++;

        try {
          $contributor_data = array_combine($header, $data);

          if ($this->processContributorRow($contributor_data)) {
            $results['created']++;
          }
          else {
            $results['updated']++;
          }

        }
        catch (\Exception $e) {
          $results['errors']++;
          $results['error_details'][] = "Row {$row_number}: " . $e->getMessage();
        }
      }
      fclose($handle);
    }

    // Clean up temporary file if created
    if (isset($temp_file) && file_exists($temp_file)) {
      unlink($temp_file);
    }

    return $results;
  }

  /**
   * Validate CSV headers.
   */
  protected function validateCsvHeaders($headers) {
    $required_headers = [
      'name',
      'drupal_username',
      'organization',
      'ai_maker',
      'tracker_role',
      'skills',
      'weekly_commitment',
      'company_drupal_profile',
      'current_focus',
      'org_role',
      'drupal_slack_username',
      'time_zone',
      'supporting_modules',
      'gitlab_username',
    ];

    foreach ($required_headers as $required) {
      if (!in_array($required, $headers)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Process a single contributor row.
   */
  protected function processContributorRow($data) {
    // Clean data using new column names.
    $name = trim($data['name']);
    $drupal_username = trim($data['drupal_username']);
    $organization = trim($data['organization']);
    $ai_maker = trim(strtolower($data['ai_maker']));
    $tracker_role = trim($data['tracker_role']);
    $skills = trim($data['skills']);
    $weekly_commitment = floatval($data['weekly_commitment']);
    $company_drupal_profile = trim($data['company_drupal_profile']);
    $current_focus = isset($data['current_focus']) ? trim($data['current_focus']) : '';
    $org_role = isset($data['org_role']) ? trim($data['org_role']) : '';
    $drupal_slack_username = isset($data['drupal_slack_username']) ? trim($data['drupal_slack_username']) : '';
    $time_zone = isset($data['time_zone']) ? trim($data['time_zone']) : '';
    $supporting_modules = isset($data['supporting_modules']) ? trim($data['supporting_modules']) : '';
    $gitlab_username = trim($data['gitlab_username']);

    if (empty($name) || empty($drupal_username)) {
      throw new \Exception('Name and Drupal username are required');
    }

    // Convert AI maker string to boolean.
    $is_ai_maker = in_array($ai_maker, ['yes', 'y', '1', 'true']);

    // Parse Developer / Organizational (comma-separated) from tracker_role.
    $contrib_types = [];
    if (!empty($tracker_role)) {
      $parts = explode(',', $tracker_role);
      foreach ($parts as $part) {
        $val = strtolower(trim($part));
        if (in_array($val, ['developer', 'dev'])) {
          $contrib_types[] = 'dev';
        }
        elseif (in_array($val, ['organisational', 'organizational', 'non-developer', 'nondeveloper', 'management', 'manager', 'org'])) {
          $contrib_types[] = 'non_dev';
        }
      }
      $contrib_types = array_values(array_unique($contrib_types));
    }

    // Find or create company using drupal profile as unique identifier.
    $company_id = $this->findOrCreateCompanyByProfile($organization, $company_drupal_profile, $is_ai_maker);

    // Check if contributor exists (by drupal username)
    $existing = $this->findExistingContributor($drupal_username);

    if ($existing) {
      // Update existing contributor.
      $existing->setTitle($name);
      $existing->set('field_drupal_username', $drupal_username);
      $existing->set('field_contributor_company', $company_id);
      // Set Role/Title field.
      $existing->set('field_contributor_role', $org_role);
      $existing->set('field_contributor_skills', $skills);
      $existing->set('field_weekly_commitment', $weekly_commitment);

      // Set new fields if they exist.
      if ($existing->hasField('field_current_focus')) {
        $existing->set('field_current_focus', $current_focus);
      }
      if ($existing->hasField('field_drupal_slack_username')) {
        $existing->set('field_drupal_slack_username', $drupal_slack_username);
      }
      if ($existing->hasField('field_time_zone')) {
        $existing->set('field_time_zone', $time_zone);
      }
      if ($existing->hasField('field_supporting_modules') && !empty($supporting_modules)) {
        // Split supporting modules by comma and create separate values.
        $modules = array_map('trim', explode(',', $supporting_modules));
        $existing->set('field_supporting_modules', array_filter($modules));
      }
      if ($existing->hasField('field_tracker_role')) {
        // Map to existing tracker_role values.
        $mapped = [];
        if (in_array('dev', $contrib_types, TRUE)) { $mapped[] = 'developer'; }
        if (in_array('non_dev', $contrib_types, TRUE)) { $mapped[] = 'management'; }
        $existing->set('field_tracker_role', $mapped);
      }
      if ($existing->hasField('field_gitlab_username')) {
        $existing->set('field_gitlab_username', $gitlab_username);
      }
      if ($existing->hasField('field_contributor_type') && !empty($contrib_types)) {
        $existing->set('field_contributor_type', array_map(function ($v) { return ['value' => $v]; }, $contrib_types));
      }

      $existing->save();

      // Updated.
      return FALSE;
    }
    else {
      // Create new contributor.
      $contributor_data = [
        'type' => 'ai_contributor',
        'title' => $name,
        'field_drupal_username' => $drupal_username,
        'field_contributor_company' => $company_id,
        'field_contributor_role' => $org_role,
        'field_contributor_skills' => $skills,
        'field_weekly_commitment' => $weekly_commitment,
        'status' => 1,
      ];

      // Add new fields if they exist in the system.
      $field_storage_manager = \Drupal::entityTypeManager()->getStorage('field_storage_config');
      if ($field_storage_manager->load('node.field_current_focus')) {
        $contributor_data['field_current_focus'] = $current_focus;
      }
      if ($field_storage_manager->load('node.field_drupal_slack_username')) {
        $contributor_data['field_drupal_slack_username'] = $drupal_slack_username;
      }
      if ($field_storage_manager->load('node.field_time_zone')) {
        $contributor_data['field_time_zone'] = $time_zone;
      }
      if ($field_storage_manager->load('node.field_supporting_modules') && !empty($supporting_modules)) {
        // Split supporting modules by comma and create separate values.
        $modules = array_map('trim', explode(',', $supporting_modules));
        $contributor_data['field_supporting_modules'] = array_filter($modules);
      }
      if ($field_storage_manager->load('node.field_tracker_role')) {
        $mapped = [];
        if (in_array('dev', $contrib_types, TRUE)) { $mapped[] = 'developer'; }
        if (in_array('non_dev', $contrib_types, TRUE)) { $mapped[] = 'management'; }
        $contributor_data['field_tracker_role'] = $mapped;
      }
      if ($field_storage_manager->load('node.field_gitlab_username')) {
        $contributor_data['field_gitlab_username'] = $gitlab_username;
      }
      if ($field_storage_manager->load('node.field_contributor_type') && !empty($contrib_types)) {
        $contributor_data['field_contributor_type'] = array_map(function ($v) { return ['value' => $v]; }, $contrib_types);
      }

      $contributor = Node::create($contributor_data);
      $contributor->save();

      // Created.
      return TRUE;
    }
  }

  /**
   * Find existing contributor by Drupal username.
   */
  protected function findExistingContributor($drupal_username) {
    $node_storage = $this->entityTypeManager->getStorage('node');

    $query = $node_storage->getQuery()
      ->condition('type', 'ai_contributor')
      ->condition('field_drupal_username', $drupal_username)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    if (!empty($result)) {
      return $node_storage->load(reset($result));
    }

    return NULL;
  }

  /**
   * Find or create company by name and set AI maker status.
   */
  protected function findOrCreateCompany($company_name, $is_ai_maker = FALSE) {
    if (empty($company_name)) {
      return NULL;
    }

    $node_storage = $this->entityTypeManager->getStorage('node');

    // Look for existing company.
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_company')
      ->condition('title', $company_name)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    if (!empty($result)) {
      // Update existing company with AI maker status.
      $company_id = reset($result);
      $company = $node_storage->load($company_id);
      if ($company && $company->hasField('field_company_ai_maker')) {
        $company->set('field_company_ai_maker', $is_ai_maker);
        $company->save();
      }
      return $company_id;
    }

    // Create new company.
    $company_data = [
      'type' => 'ai_company',
      'title' => $company_name,
      'status' => 1,
    ];

    // Add AI maker field if it exists.
    if (\Drupal::entityTypeManager()->getStorage('field_storage_config')->load('node.field_company_ai_maker')) {
      $company_data['field_company_ai_maker'] = $is_ai_maker;
    }

    $company = Node::create($company_data);
    $company->save();

    return $company->id();
  }

  /**
   * Find or create company by Drupal profile and set AI maker status.
   */
  protected function findOrCreateCompanyByProfile($company_name, $drupal_profile, $is_ai_maker = FALSE) {
    if (empty($company_name)) {
      return NULL;
    }

    $node_storage = $this->entityTypeManager->getStorage('node');

    // First, try to find by Drupal profile if it's provided and not empty.
    if (!empty($drupal_profile)) {
      // Check if the field exists before querying.
      $field_storage_manager = \Drupal::entityTypeManager()->getStorage('field_storage_config');
      if ($field_storage_manager->load('node.field_company_drupal_profile')) {
        try {
          $query = $node_storage->getQuery()
            ->condition('type', 'ai_company')
            ->condition('field_company_drupal_profile', $drupal_profile)
            ->accessCheck(FALSE)
            ->range(0, 1);

          $result = $query->execute();

          if (!empty($result)) {
            // Update existing company.
            $company_id = reset($result);
            $company = $node_storage->load($company_id);
            if ($company) {
              $needs_save = FALSE;

              // Update name if different.
              if ($company->getTitle() !== $company_name) {
                $company->setTitle($company_name);
                $needs_save = TRUE;
              }

              // Update AI maker status if field exists.
              if ($company->hasField('field_company_ai_maker')) {
                $current_ai_maker = (bool) $company->get('field_company_ai_maker')->value;
                if ($current_ai_maker !== $is_ai_maker) {
                  $company->set('field_company_ai_maker', $is_ai_maker);
                  $needs_save = TRUE;
                }
              }

              if ($needs_save) {
                $company->save();
              }
            }
            return $company_id;
          }
        }
        catch (\Exception $e) {
          // Log the error but continue with fallback.
          \Drupal::logger('ai_dashboard')->error('Error querying company by drupal profile: @message', ['@message' => $e->getMessage()]);
        }
      }
    }

    // Fallback for backwards compatibility:
    // If not found by profile, try by name.
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_company')
      ->condition('title', $company_name)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    if (!empty($result)) {
      // Update existing company with profile and AI maker status.
      $company_id = reset($result);
      $company = $node_storage->load($company_id);
      if ($company) {
        $needs_save = FALSE;

        // Update Drupal profile if provided and field exists.
        if (!empty($drupal_profile) && $company->hasField('field_company_drupal_profile')) {
          $current_profile = $company->get('field_company_drupal_profile')->value;
          if ($current_profile !== $drupal_profile) {
            $company->set('field_company_drupal_profile', $drupal_profile);
            $needs_save = TRUE;
          }
        }

        // Update AI maker status if field exists.
        if ($company->hasField('field_company_ai_maker')) {
          $current_ai_maker = (bool) $company->get('field_company_ai_maker')->value;
          if ($current_ai_maker !== $is_ai_maker) {
            $company->set('field_company_ai_maker', $is_ai_maker);
            $needs_save = TRUE;
          }
        }

        if ($needs_save) {
          $company->save();
        }
      }
      return $company_id;
    }

    // Create new company.
    $company_data = [
      'type' => 'ai_company',
      'title' => $company_name,
      'status' => 1,
    ];

    // Add fields if they exist.
    $field_storage_manager = \Drupal::entityTypeManager()->getStorage('field_storage_config');
    if ($field_storage_manager->load('node.field_company_ai_maker')) {
      $company_data['field_company_ai_maker'] = $is_ai_maker;
    }
    if (!empty($drupal_profile) && $field_storage_manager->load('node.field_company_drupal_profile')) {
      $company_data['field_company_drupal_profile'] = $drupal_profile;
    }

    $company = Node::create($company_data);
    $company->save();

    return $company->id();
  }

  /**
   * Get list of companies.
   */
  protected function getCompanyList() {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $companies = $node_storage->loadByProperties(['type' => 'ai_company']);

    $company_list = [];
    foreach ($companies as $company) {
      $company_list[$company->getTitle()] = $company->id();
    }

    return $company_list;
  }

  /**
   * Generate CSV content.
   */
  protected function generateCsvContent($headers, $rows) {
    $output = fopen('php://temp', 'r+');

    // Write headers.
    fputcsv($output, $headers);

    // Write data rows.
    foreach ($rows as $row) {
      fputcsv($output, $row);
    }

    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);

    return $csv_content;
  }

}
