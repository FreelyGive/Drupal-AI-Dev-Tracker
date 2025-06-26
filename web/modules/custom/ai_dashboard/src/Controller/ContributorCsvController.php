<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\ai_dashboard\Controller\AdminToolsController;
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

    // Add admin navigation
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('import_contributors');

    // Add the form
    $form = \Drupal::formBuilder()->getForm('Drupal\ai_dashboard\Form\ContributorCsvImportForm');
    $build['form'] = $form;

    return $build;
  }

  /**
   * Download CSV template.
   */
  public function downloadTemplate() {
    // Get sample data for template
    $companies = $this->getCompanyList();
    
    $headers = [
      'full_name',
      'drupal_username',
      'company_name',
      'role',
      'skills',
      'weekly_commitment'
    ];

    // Create sample rows
    $sample_rows = [
      [
        'John Doe',
        'john_doe',
        array_key_exists('Acquia', $companies) ? 'Acquia' : array_keys($companies)[0] ?? 'Example Company',
        'Senior Developer',
        'PHP, JavaScript, Drupal',
        '5'
      ],
      [
        'Jane Smith', 
        'jane_smith',
        array_key_exists('Lullabot', $companies) ? 'Lullabot' : array_keys($companies)[1] ?? 'Another Company',
        'Technical Lead',
        'AI/ML, Python, DevOps',
        '3'
      ]
    ];

    // Generate CSV content
    $csv_content = $this->generateCsvContent($headers, $sample_rows);

    // Create response
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
      
      // Check if file was uploaded via FormData
      $file = $request->files->get('csv_file');
      
      \Drupal::logger('ai_dashboard')->info('File upload check: @file', ['@file' => $file ? 'Found' : 'Not found']);
      
      if (empty($file)) {
        throw new \Exception('No file uploaded');
      }
      $file_path = $file->getPathname();
      
      if (!file_exists($file_path)) {
        throw new \Exception('Uploaded file not found');
      }

      // Parse CSV
      $results = $this->parseCsv($file_path);
      
      return new JsonResponse([
        'success' => true,
        'message' => sprintf('Successfully processed %d contributors. Created: %d, Updated: %d, Errors: %d', 
          $results['total'], $results['created'], $results['updated'], $results['errors']),
        'results' => $results
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('CSV import error: @message', ['@message' => $e->getMessage()]);
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Import failed: ' . $e->getMessage()
      ]);
    }
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
      'error_details' => []
    ];

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
          } else {
            $results['updated']++;
          }
          
        } catch (\Exception $e) {
          $results['errors']++;
          $results['error_details'][] = "Row {$row_number}: " . $e->getMessage();
        }
      }
      fclose($handle);
    }

    return $results;
  }

  /**
   * Validate CSV headers.
   */
  protected function validateCsvHeaders($headers) {
    $required_headers = [
      'full_name',
      'drupal_username',
      'company_name',
      'role',
      'skills',
      'weekly_commitment'
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
    // Clean data
    $full_name = trim($data['full_name']);
    $drupal_username = trim($data['drupal_username']);
    $company_name = trim($data['company_name']);
    $role = trim($data['role']);
    $skills = trim($data['skills']);
    $weekly_commitment = floatval($data['weekly_commitment']);

    if (empty($full_name) || empty($drupal_username)) {
      throw new \Exception('Full name and Drupal username are required');
    }

    // Find company
    $company_id = $this->findOrCreateCompany($company_name);

    // Check if contributor exists (by drupal username)
    $existing = $this->findExistingContributor($drupal_username);
    
    if ($existing) {
      // Update existing contributor
      $existing->setTitle($full_name);
      $existing->set('field_drupal_username', $drupal_username);
      $existing->set('field_contributor_company', $company_id);
      $existing->set('field_contributor_role', $role);
      $existing->set('field_contributor_skills', $skills);
      $existing->set('field_weekly_commitment', $weekly_commitment);
      $existing->save();
      
      return FALSE; // Updated
    } else {
      // Create new contributor
      $contributor = Node::create([
        'type' => 'ai_contributor',
        'title' => $full_name,
        'field_drupal_username' => $drupal_username,
        'field_contributor_company' => $company_id,
        'field_contributor_role' => $role,
        'field_contributor_skills' => $skills,
        'field_weekly_commitment' => $weekly_commitment,
        'status' => 1,
      ]);
      $contributor->save();
      
      return TRUE; // Created
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
   * Find or create company by name.
   */
  protected function findOrCreateCompany($company_name) {
    if (empty($company_name)) {
      return NULL;
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    
    // Look for existing company
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_company')
      ->condition('title', $company_name)
      ->accessCheck(FALSE)
      ->range(0, 1);
    
    $result = $query->execute();
    
    if (!empty($result)) {
      return reset($result);
    }
    
    // Create new company
    $company = Node::create([
      'type' => 'ai_company',
      'title' => $company_name,
      'status' => 1,
    ]);
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
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data rows
    foreach ($rows as $row) {
      fputcsv($output, $row);
    }
    
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);
    
    return $csv_content;
  }
}