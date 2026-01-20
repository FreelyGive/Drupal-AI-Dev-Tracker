<?php

namespace Drupal\meta_issue_editor\Controller;

use Drupal\ai_dashboard\Service\IssueImportService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\meta_issue_editor\Service\MetaIssueParserService;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the Meta-Issue-Editor.
 */
class MetaIssueEditorController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The meta issue parser service.
   *
   * @var \Drupal\meta_issue_editor\Service\MetaIssueParserService
   */
  protected $parser;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a MetaIssueEditorController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\meta_issue_editor\Service\MetaIssueParserService $parser
   *   The meta issue parser service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MetaIssueParserService $parser,
    ClientInterface $http_client
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->parser = $parser;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('meta_issue_editor.parser'),
      $container->get('http_client')
    );
  }

  /**
   * Displays the main editor page.
   *
   * @param int|null $issue_number
   *   Optional issue number to load.
   *
   * @return array
   *   A render array.
   */
  public function editor($issue_number = NULL) {
    $canSave = $this->currentUser()->hasPermission('save meta issue drafts');
    $canFetch = $this->currentUser()->hasPermission('fetch issues from drupal org');

    $build = [
      '#theme' => 'meta_issue_editor',
      '#issue_number' => $issue_number,
      '#can_save' => $canSave,
      '#can_fetch' => $canFetch,
      '#attached' => [
        'library' => [
          'meta_issue_editor/editor',
        ],
        'drupalSettings' => [
          'metaIssueEditor' => [
            'issueNumber' => $issue_number,
            'canSave' => $canSave,
            'canFetch' => $canFetch,
            'csrfToken' => \Drupal::csrfToken()->get('meta_issue_editor'),
          ],
        ],
      ],
    ];

    // If issue number provided, try to load existing draft or fetch from d.o.
    if ($issue_number) {
      $draft = $this->loadDraftBySourceIssue($issue_number);
      if ($draft) {
        $build['#attached']['drupalSettings']['metaIssueEditor']['draft'] = [
          'nid' => $draft->id(),
          'content' => $draft->get('field_editor_content')->value,
          'issueCache' => $draft->get('field_issue_cache')->value,
        ];
      }
    }

    return $build;
  }

  /**
   * Handle export request.
   *
   * @param string $format
   *   Export format: 'html' or 'markdown'.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array.
   */
  public function export(string $format, Request $request) {
    $content = $request->request->get('content', '');
    $issueNumber = $request->request->get('issue_number', '');

    return [
      '#theme' => 'meta_issue_export',
      '#format' => $format,
      '#content' => $content,
      '#issue_number' => $issueNumber,
    ];
  }

  /**
   * API: Fetch issues from drupal.org.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with fetched issue data.
   */
  public function fetchIssues(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $issueNumbers = $data['issue_numbers'] ?? [];

    if (empty($issueNumbers)) {
      return new JsonResponse(['error' => 'No issue numbers provided'], 400);
    }

    $results = [];
    $errors = [];

    foreach ($issueNumbers as $issueNumber) {
      try {
        $issueData = $this->fetchIssueFromDrupalOrg((int) $issueNumber);
        if ($issueData) {
          $results[$issueNumber] = $issueData;
        }
        else {
          $errors[$issueNumber] = 'Issue not found';
        }
        // Rate limiting: 0.5s between requests
        usleep(500000);
      }
      catch (\Exception $e) {
        $errors[$issueNumber] = $e->getMessage();
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'issues' => $results,
      'errors' => $errors,
    ]);
  }

  /**
   * API: Get local issue data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with local issue data.
   */
  public function localIssues(Request $request) {
    $issueNumbers = $request->query->get('issue_numbers', '');
    $issueNumbers = array_filter(explode(',', $issueNumbers));

    if (empty($issueNumbers)) {
      return new JsonResponse(['error' => 'No issue numbers provided'], 400);
    }

    $results = [];
    $notFound = [];

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($issueNumbers as $issueNumber) {
      $issueNumber = (int) $issueNumber;

      // Find AI Issue node by issue number
      $query = $nodeStorage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('field_issue_number', $issueNumber)
        ->accessCheck(FALSE)
        ->range(0, 1);

      $nids = $query->execute();

      if (!empty($nids)) {
        $node = $nodeStorage->load(reset($nids));
        $results[$issueNumber] = $this->formatIssueData($node);
      }
      else {
        $notFound[] = $issueNumber;
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'issues' => $results,
      'not_found' => $notFound,
    ]);
  }

  /**
   * API: Save draft.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function saveDraft(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    $sourceIssue = (int) ($data['source_issue'] ?? 0);
    $editorContent = $data['editor_content'] ?? '';
    $issueCache = $data['issue_cache'] ?? '';

    if (!$sourceIssue) {
      return new JsonResponse(['error' => 'Source issue number required'], 400);
    }

    // Check for existing draft
    $existing = $this->loadDraftBySourceIssue($sourceIssue);

    if ($existing) {
      // Update existing draft (creates revision)
      $existing->set('field_editor_content', $editorContent);
      $existing->set('field_issue_cache', $issueCache);
      $existing->setNewRevision(TRUE);
      $existing->setRevisionLogMessage('Updated via Meta-Issue-Editor');
      $existing->save();

      return new JsonResponse([
        'success' => TRUE,
        'nid' => $existing->id(),
        'message' => 'Draft updated',
      ]);
    }
    else {
      // Create new draft
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $node = $nodeStorage->create([
        'type' => 'meta_issue_draft',
        'title' => 'Meta #' . $sourceIssue,
        'field_source_issue' => $sourceIssue,
        'field_editor_content' => $editorContent,
        'field_issue_cache' => $issueCache,
      ]);
      $node->save();

      return new JsonResponse([
        'success' => TRUE,
        'nid' => $node->id(),
        'message' => 'Draft created',
      ]);
    }
  }

  /**
   * API: Load draft by source issue.
   *
   * @param int $source_issue
   *   The source issue number.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function loadDraft(int $source_issue) {
    $draft = $this->loadDraftBySourceIssue($source_issue);

    if ($draft) {
      return new JsonResponse([
        'success' => TRUE,
        'draft' => [
          'nid' => $draft->id(),
          'title' => $draft->label(),
          'source_issue' => $source_issue,
          'editor_content' => $draft->get('field_editor_content')->value,
          'issue_cache' => $draft->get('field_issue_cache')->value,
        ],
      ]);
    }

    return new JsonResponse([
      'success' => FALSE,
      'message' => 'No draft found for issue #' . $source_issue,
    ]);
  }

  /**
   * Load a draft node by source issue number.
   *
   * @param int $source_issue
   *   The source issue number.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The draft node, or NULL if not found.
   */
  protected function loadDraftBySourceIssue(int $source_issue) {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $query = $nodeStorage->getQuery()
      ->condition('type', 'meta_issue_draft')
      ->condition('field_source_issue', $source_issue)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $nids = $query->execute();

    if (!empty($nids)) {
      return $nodeStorage->load(reset($nids));
    }

    return NULL;
  }

  /**
   * Fetch issue data from drupal.org API.
   *
   * @param int $issue_number
   *   The issue number.
   *
   * @return array|null
   *   Issue data array, or NULL if not found.
   */
  protected function fetchIssueFromDrupalOrg(int $issue_number) {
    $url = 'https://www.drupal.org/api-d7/node/' . $issue_number . '.json';

    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'User-Agent' => 'Meta Issue Editor/1.0',
        ],
        'timeout' => 30,
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);

      if (empty($data) || ($data['type'] ?? '') !== 'project_issue') {
        return NULL;
      }

      return [
        'issue_number' => $issue_number,
        'title' => $data['title'] ?? '',
        'status' => $this->mapIssueStatus($data['field_issue_status'] ?? ''),
        'status_id' => $data['field_issue_status'] ?? '',
        'priority' => $data['field_issue_priority'] ?? '',
        'component' => $data['field_issue_component'] ?? '',
        'assigned' => $data['field_issue_assigned']['name'] ?? '',
        'body' => $data['body']['value'] ?? '',
        'url' => 'https://www.drupal.org/node/' . $issue_number,
      ];
    }
    catch (\Exception $e) {
      $this->getLogger('meta_issue_editor')->error('Failed to fetch issue @num: @error', [
        '@num' => $issue_number,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Format local AI Issue node data for API response.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The AI Issue node.
   *
   * @return array
   *   Formatted issue data.
   */
  protected function formatIssueData($node) {
    return [
      'issue_number' => (int) $node->get('field_issue_number')->value,
      'title' => $node->label(),
      'status' => $node->get('field_issue_status')->value ?? '',
      'priority' => $node->get('field_issue_priority')->value ?? '',
      'component' => $node->get('field_issue_category')->value ?? '',
      'assigned' => $node->get('field_issue_do_assignee')->value ?? '',
      'module' => $node->get('field_issue_module')->value ?? '',
      'tags' => $this->getIssueTags($node),
      'update_summary' => $node->get('field_update_summary')->value ?? '',
      'url' => 'https://www.drupal.org/node/' . $node->get('field_issue_number')->value,
    ];
  }

  /**
   * Get tags from an AI Issue node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The AI Issue node.
   *
   * @return array
   *   Array of tag names.
   */
  protected function getIssueTags($node) {
    $tags = [];
    if ($node->hasField('field_issue_tags') && !$node->get('field_issue_tags')->isEmpty()) {
      foreach ($node->get('field_issue_tags') as $item) {
        $tags[] = $item->value;
      }
    }
    return $tags;
  }

  /**
   * Map drupal.org status ID to human-readable status.
   *
   * @param string $status_id
   *   The status ID from drupal.org.
   *
   * @return string
   *   Human-readable status.
   */
  protected function mapIssueStatus($status_id) {
    $statuses = [
      '1' => 'Active',
      '2' => 'Fixed',
      '3' => 'Closed (duplicate)',
      '4' => 'Postponed',
      '5' => 'Closed (won\'t fix)',
      '6' => 'Closed (works as designed)',
      '7' => 'Closed (fixed)',
      '8' => 'Needs review',
      '13' => 'Needs work',
      '14' => 'Reviewed & tested by the community',
      '15' => 'Patch (to be ported)',
      '16' => 'Postponed (maintainer needs more info)',
      '18' => 'Closed (cannot reproduce)',
    ];

    return $statuses[$status_id] ?? 'Unknown';
  }

}
