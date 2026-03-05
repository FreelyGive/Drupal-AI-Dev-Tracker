<?php

namespace Drupal\meta_issue_editor\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\meta_issue_editor\Service\MetaIssueParserService;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the Meta-Issue-Editor.
 */
class MetaIssueEditorController extends ControllerBase {

  /**
   * Maximum issues allowed per fetch request.
   */
  protected const MAX_ISSUES_PER_REQUEST = 20;

  /**
   * Site-wide load/fetch limit per hour.
   */
  protected const GLOBAL_LOAD_LIMIT = 300;

  /**
   * Window for the site-wide load/fetch limit.
   */
  protected const GLOBAL_LOAD_WINDOW = 3600;

  /**
   * Per-IP load/fetch limit per 10 minutes.
   */
  protected const IP_LOAD_LIMIT = 40;

  /**
   * Window for the per-IP load/fetch limit.
   */
  protected const IP_LOAD_WINDOW = 600;

  /**
   * Flood event name for global request limiting.
   */
  protected const GLOBAL_FETCH_EVENT = 'meta_issue_editor.fetch.global';

  /**
   * Flood event name for per-IP request limiting.
   */
  protected const IP_FETCH_EVENT = 'meta_issue_editor.fetch.ip';

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
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * Constructs a MetaIssueEditorController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\meta_issue_editor\Service\MetaIssueParserService $parser
   *   The meta issue parser service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MetaIssueParserService $parser,
    ClientInterface $http_client,
    CsrfTokenGenerator $csrf_token,
    FloodInterface $flood,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->parser = $parser;
    $this->httpClient = $http_client;
    $this->csrfToken = $csrf_token;
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('meta_issue_editor.parser'),
      $container->get('http_client'),
      $container->get('csrf_token'),
      $container->get('flood'),
    );
  }

  /**
   * Validate CSRF token from request header.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if token is valid, FALSE otherwise.
   */
  protected function validateCsrfToken(Request $request): bool {
    $token = $request->headers->get('X-CSRF-Token');
    return $token && $this->csrfToken->validate($token, 'meta_issue_editor');
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
  public function editor($issue_number = NULL): array {
    $canSave = $this->currentUser()->hasPermission('save meta issue drafts');
    $canLoadDraft = $canSave;
    // Public editor access requires public fetch access for "Load" to work.
    $canFetch = TRUE;

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
            'canLoadDraft' => $canLoadDraft,
            'canFetch' => $canFetch,
            'csrfToken' => $this->csrfToken->get('meta_issue_editor'),
          ],
        ],
      ],
    ];

    // If issue number provided, try to load existing draft or fetch from d.o.
    if ($issue_number && $canLoadDraft) {
      $draft = $this->loadDraftBySourceIssue($issue_number);
      if ($draft) {
        $build['#attached']['drupalSettings']['metaIssueEditor']['draft'] = [
          'nid' => $draft->id(),
          'editor_content' => $draft->get('field_editor_content')->value,
          'issue_cache' => $draft->get('field_issue_cache')->value,
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
  public function export(string $format, Request $request): array {
    // Content is displayed in a <textarea> which doesn't execute HTML,
    // so we pass it through unescaped. The textarea element itself
    // provides the necessary XSS protection.
    $content = $request->request->get('content', '');
    // Issue number should be numeric only.
    $issueNumber = preg_replace('/[^0-9]/', '', $request->request->get('issue_number', ''));

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
  public function fetchIssues(Request $request): JsonResponse {
    // Validate CSRF token to prevent cross-site request forgery.
    if (!$this->validateCsrfToken($request)) {
      return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $data = json_decode($request->getContent(), TRUE) ?? [];
    $issueNumbers = $data['issue_numbers'] ?? [];

    if (!is_array($issueNumbers)) {
      return new JsonResponse(['error' => 'Issue numbers must be an array'], 400);
    }

    $issueNumbers = array_values(array_unique(array_filter(array_map('intval', $issueNumbers), static function (int $issueNumber): bool {
      return $issueNumber >= 10000 && $issueNumber <= 99999999;
    })));

    if (empty($issueNumbers)) {
      return new JsonResponse(['error' => 'No issue numbers provided'], 400);
    }

    if (count($issueNumbers) > self::MAX_ISSUES_PER_REQUEST) {
      return new JsonResponse([
        'error' => 'Maximum ' . self::MAX_ISSUES_PER_REQUEST . ' issues per request',
      ], 400);
    }

    $clientIp = (string) ($request->getClientIp() ?? 'unknown');

    if (!$this->flood->isAllowed(self::GLOBAL_FETCH_EVENT, self::GLOBAL_LOAD_LIMIT, self::GLOBAL_LOAD_WINDOW, 'global')) {
      return new JsonResponse([
        'error' => 'Site-wide load limit reached. Please try again later.',
      ], 429);
    }

    if (!$this->flood->isAllowed(self::IP_FETCH_EVENT, self::IP_LOAD_LIMIT, self::IP_LOAD_WINDOW, $clientIp)) {
      return new JsonResponse([
        'error' => 'Too many loads from your IP. Please wait a few minutes.',
      ], 429);
    }

    // Weight flood entries by number of issues requested in this call.
    $weight = count($issueNumbers);
    for ($i = 0; $i < $weight; $i++) {
      $this->flood->register(self::GLOBAL_FETCH_EVENT, self::GLOBAL_LOAD_WINDOW, 'global');
      $this->flood->register(self::IP_FETCH_EVENT, self::IP_LOAD_WINDOW, $clientIp);
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
        // Keep a small delay between drupal.org requests to reduce burst pressure.
        usleep(250000);
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
  public function localIssues(Request $request): JsonResponse {
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

      // Find AI Issue node by issue number.
      // Using accessCheck(FALSE) is safe here because this endpoint is public
      // and returns only non-sensitive metadata for public AI Issue content.
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
  public function saveDraft(Request $request): JsonResponse {
    // Validate CSRF token to prevent cross-site request forgery.
    if (!$this->validateCsrfToken($request)) {
      return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);

    $sourceIssue = (int) ($data['source_issue'] ?? 0);
    $editorContent = $data['editor_content'] ?? '';
    $issueCache = $data['issue_cache'] ?? '';

    if (!$sourceIssue) {
      return new JsonResponse(['error' => 'Source issue number required'], 400);
    }

    // Check for existing draft.
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
      // Create new draft.
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
  public function loadDraft(int $source_issue): JsonResponse {
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
  protected function loadDraftBySourceIssue(int $source_issue): ?NodeInterface {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Using accessCheck(FALSE) is safe here because meta issue drafts are only
    // returned through protected routes/methods and never exposed publicly.
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
   * Note: This method fetches issue data directly via HTTP rather than using
   * the IssueImportService because we only need to display metadata, not
   * import the issue into the database. The IssueImportService's methods
   * are designed for full imports that create/update nodes.
   *
   * @param int $issue_number
   *   The issue number.
   *
   * @return array|null
   *   Issue data array, or NULL if not found.
   */
  protected function fetchIssueFromDrupalOrg(int $issue_number): ?array {
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
  protected function formatIssueData(NodeInterface $node): array {
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
  protected function getIssueTags(NodeInterface $node): array {
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
  protected function mapIssueStatus(string $status_id): string {
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
