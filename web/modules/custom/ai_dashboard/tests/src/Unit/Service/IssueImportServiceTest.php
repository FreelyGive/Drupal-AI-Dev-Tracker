<?php

namespace Drupal\Tests\ai_dashboard\Unit\Service;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\ai_dashboard\Service\IssueImportService;
use Drupal\ai_dashboard\Service\TagMappingService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;

/**
 * Tests the IssueImportService component filtering functionality.
 *
 * @group ai_dashboard
 */
class IssueImportServiceTest extends TestCase {

  use ProphecyTrait;

  /**
   * The mocked HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $httpClient;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $entityTypeManager;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $loggerFactory;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * The mocked tag mapping service.
   *
   * @var \Drupal\ai_dashboard\Service\TagMappingService|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $tagMappingService;

  /**
   * The mocked entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $entityStorage;

  /**
   * The mocked module import config.
   *
   * @var \Drupal\ai_dashboard\Entity\ModuleImport|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $moduleImport;

  /**
   * The issue import service under test.
   *
   * @var \Drupal\ai_dashboard\Service\IssueImportService
   */
  protected $issueImportService;

  /**
   * Create a mock stream that returns the given content.
   */
  private function createMockStream($content) {
    $stream = $this->prophesize(\Psr\Http\Message\StreamInterface::class);
    $stream->getContents()->willReturn($content);
    return $stream->reveal();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mocked dependencies.
    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->entityStorage = $this->prophesize(EntityStorageInterface::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $this->tagMappingService = $this->prophesize(TagMappingService::class);
    $this->moduleImport = $this->prophesize(ModuleImport::class);

    // Setup entity type manager to return mocked storage.
    $this->entityTypeManager->getStorage('taxonomy_term')->willReturn($this->entityStorage->reveal());
    $this->entityStorage->loadByProperties(Argument::any())->willReturn([]);

    // Setup logger factory to return mocked logger.
    $this->loggerFactory->get('ai_dashboard')->willReturn($this->logger->reveal());

    // Create service instance.
    $this->issueImportService = new IssueImportService(
      $this->entityTypeManager->reveal(),
      $this->httpClient->reveal(),
      $this->loggerFactory->reveal(),
      $this->tagMappingService->reveal()
    );
  }

  /**
   * Test that component filter is added to API request parameters.
   */
  public function testComponentFilterInApiRequest() {
    // Setup module import config with component filter.
    $this->moduleImport->getProjectId()->willReturn('12345');
    $this->moduleImport->getFilterTags()->willReturn([]);
    $this->moduleImport->getFilterComponent()->willReturn('AI');
    $this->moduleImport->getStatusFilter()->willReturn(['1', '8']);
    $this->moduleImport->getMaxIssues()->willReturn(100);
    $this->moduleImport->getDateFilter()->willReturn(NULL);
    $this->moduleImport->id()->willReturn('test_config');

    // Mock successful API response with no issues (empty list).
    $response_data = [
      'list' => [],
      'last' => '',
    ];
    $response = new Response(200, [], json_encode($response_data));
    
    // Use Argument::that() for flexible matching of the API request.
    $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json', Argument::that(function ($options) {
      $query = $options['query'] ?? [];
      // Verify the component filter is included in the API request.
      return isset($query['field_issue_component']) && 
             $query['field_issue_component'] === 'AI' &&
             isset($query['field_project']) &&
             $query['field_project'] === '12345';
    }))->willReturn($response);

    // Build the batch - this should include component filter in API request.
    $batch = $this->issueImportService->buildImportBatch($this->moduleImport->reveal());

    // Verify batch was created (indicates API call was made with correct parameters).
    $this->assertIsArray($batch);
    $this->assertArrayHasKey('operations', $batch);
  }

  /**
   * Test that empty component filter is not added to API request.
   */
  public function testEmptyComponentFilterNotInApiRequest() {
    // Setup module import config without component filter.
    $this->moduleImport->getProjectId()->willReturn('12345');
    $this->moduleImport->getFilterTags()->willReturn([]);
    $this->moduleImport->getFilterComponent()->willReturn('');
    $this->moduleImport->getStatusFilter()->willReturn(['1']);
    $this->moduleImport->getMaxIssues()->willReturn(50);
    $this->moduleImport->getDateFilter()->willReturn(NULL);
    $this->moduleImport->id()->willReturn('test_config');

    // Mock API response.
    $response_data = ['list' => []];
    $response = new Response(200, [], json_encode($response_data));

    // Expect API call WITHOUT component filter parameter.
    $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json', [
      'query' => [
        'type' => 'project_issue',
        'field_project' => '12345',
        'limit' => 50,
        'page' => 0,
        'sort' => 'created',
        'direction' => 'DESC',
        'field_issue_status' => '1',
        // Note: no 'field_issue_component' parameter
      ],
      'timeout' => 60,
      'headers' => [
        'User-Agent' => 'AI Dashboard Module/1.0',
      ],
    ])->willReturn($response);

    // Build batch should work without component filter.
    $batch = $this->issueImportService->buildImportBatch($this->moduleImport->reveal());
    $this->assertIsArray($batch);
  }

  /**
   * Test component filter with tag filter combination.
   */
  public function testComponentFilterWithTagFilter() {
    // Setup module import with both component and tag filters.
    $this->moduleImport->getProjectId()->willReturn('12345');
    $this->moduleImport->getFilterTags()->willReturn(['AI Initiative']);
    $this->moduleImport->getFilterComponent()->willReturn('AI');
    $this->moduleImport->getStatusFilter()->willReturn(['1']);
    $this->moduleImport->getMaxIssues()->willReturn(50);
    $this->moduleImport->getDateFilter()->willReturn(NULL);
    $this->moduleImport->id()->willReturn('test_config');

    // Mock tag mapping service to return tag IDs.
    $this->tagMappingService->processTags(['AI Initiative'])->willReturn(['category' => 'development']);

    // Mock API response with proper format expected by the service.
    $response_data = [
      'list' => [],
      'last' => '',
    ];
    
    // Create a mock response to avoid stream consumption issues.
    $response = $this->prophesize(ResponseInterface::class);
    $response->getBody()->willReturn($this->createMockStream(json_encode($response_data)));
    $response->getStatusCode()->willReturn(200);

    // Use flexible matching for HTTP client - the buildTagIds method is complex but we want to test the overall flow.
    $this->httpClient->request('GET', Argument::any(), Argument::any())->willReturn($response->reveal());

    $batch = $this->issueImportService->buildImportBatch($this->moduleImport->reveal());
    $this->assertIsArray($batch);
  }

  /**
   * Test component filter with date filter combination.
   */
  public function testComponentFilterWithDateFilter() {
    // Setup module import with component and date filters.
    $this->moduleImport->getProjectId()->willReturn('12345');
    $this->moduleImport->getFilterTags()->willReturn([]);
    $this->moduleImport->getFilterComponent()->willReturn('Core');
    $this->moduleImport->getStatusFilter()->willReturn(['1']);
    $this->moduleImport->getMaxIssues()->willReturn(50);
    $this->moduleImport->getDateFilter()->willReturn('2024-01-01');
    $this->moduleImport->id()->willReturn('test_config');

    // Mock API response.
    $response_data = ['list' => []];
    $response = new Response(200, [], json_encode($response_data));

    // Expect API call with both component and date filters.
    $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json', [
      'query' => [
        'type' => 'project_issue',
        'field_project' => '12345',
        'limit' => 50,
        'page' => 0,
        'sort' => 'created',
        'direction' => 'DESC',
        'field_issue_status' => '1',
        'field_issue_component' => 'Core',
        'created' => '>=' . strtotime('2024-01-01'),
      ],
      'timeout' => 60,
      'headers' => [
        'User-Agent' => 'AI Dashboard Module/1.0',
      ],
    ])->willReturn($response);

    $batch = $this->issueImportService->buildImportBatch($this->moduleImport->reveal());
    $this->assertIsArray($batch);
  }

  /**
   * Test component filter parameter validation.
   * 
   * This test ensures that various component filter values are handled correctly.
   */
  public function testComponentFilterParameterValidation() {
    $test_cases = [
      ['AI', 'AI'],
      ['Core', 'Core'],
      ['Experience Builder', 'Experience Builder'],
      ['AI Initiative', 'AI Initiative'],
    ];

    foreach ($test_cases as [$input_component, $expected_parameter]) {
      // Setup module import for each test case.
      $this->moduleImport->getProjectId()->willReturn('12345');
      $this->moduleImport->getFilterTags()->willReturn([]);
      $this->moduleImport->getFilterComponent()->willReturn($input_component);
      $this->moduleImport->getStatusFilter()->willReturn(['1']);
      $this->moduleImport->getMaxIssues()->willReturn(50);
      $this->moduleImport->getDateFilter()->willReturn(NULL);
      $this->moduleImport->id()->willReturn('test_config');

      // Mock API response.
      $response_data = ['list' => []];
      $response = new Response(200, [], json_encode($response_data));

      // Verify the component parameter is passed correctly to the API.
      $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json', \Prophecy\Argument::that(function ($options) use ($expected_parameter) {
        return isset($options['query']['field_issue_component']) && 
               $options['query']['field_issue_component'] === $expected_parameter;
      }))->willReturn($response);

      $batch = $this->issueImportService->buildImportBatch($this->moduleImport->reveal());
      $this->assertIsArray($batch, "Failed for component: $input_component");
    }
  }

  /**
   * Test that buildImportBatch handles multiple status filters with component filter.
   */
  public function testMultipleStatusFiltersWithComponentFilter() {
    // Setup module import with multiple status filters and component filter.
    $this->moduleImport->getProjectId()->willReturn('12345');
    $this->moduleImport->getFilterTags()->willReturn([]);
    $this->moduleImport->getFilterComponent()->willReturn('AI');
    $this->moduleImport->getStatusFilter()->willReturn(['1', '8', '13']);
    $this->moduleImport->getMaxIssues()->willReturn(50);
    $this->moduleImport->getDateFilter()->willReturn(NULL);
    $this->moduleImport->id()->willReturn('test_config');

    // Mock API response.
    $response_data = ['list' => []];
    $response = new Response(200, [], json_encode($response_data));

    // Expect API call with multiple status filters and component filter.
    $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json', [
      'query' => [
        'type' => 'project_issue',
        'field_project' => '12345',
        'limit' => 50,
        'page' => 0,
        'sort' => 'created',
        'direction' => 'DESC',
        'field_issue_status' => ['1', '8', '13'],
        'field_issue_component' => 'AI',
      ],
      'timeout' => 60,
      'headers' => [
        'User-Agent' => 'AI Dashboard Module/1.0',
      ],
    ])->willReturn($response);

    $batch = $this->issueImportService->buildImportBatch($this->moduleImport->reveal());
    $this->assertIsArray($batch);
    $this->assertArrayHasKey('operations', $batch);
  }

  /**
   * Test error handling when API request fails with component filter.
   */
  public function testApiErrorHandlingWithComponentFilter() {
    // Setup module import with component filter.
    $this->moduleImport->getProjectId()->willReturn('12345');
    $this->moduleImport->getFilterTags()->willReturn([]);
    $this->moduleImport->getFilterComponent()->willReturn('AI');
    $this->moduleImport->getStatusFilter()->willReturn(['1']);
    $this->moduleImport->getMaxIssues()->willReturn(50);
    $this->moduleImport->getDateFilter()->willReturn(NULL);
    $this->moduleImport->id()->willReturn('test_config');

    // Mock HTTP client to throw exception.
    $this->httpClient->request(\Prophecy\Argument::cetera())
      ->willThrow(new \Exception('API request failed'));

    // Verify that buildImportBatch handles the exception.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('API request failed');

    $this->issueImportService->buildImportBatch($this->moduleImport->reveal());
  }

}