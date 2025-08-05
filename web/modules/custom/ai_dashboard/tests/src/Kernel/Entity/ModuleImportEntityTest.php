<?php

namespace Drupal\Tests\ai_dashboard\Kernel\Entity;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the ModuleImport config entity.
 *
 * @group ai_dashboard
 */
class ModuleImportEntityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user', 
    'field',
    'text',
    'node',
    'taxonomy',
    'options',
    'datetime',
    'ai_dashboard',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    
    // Install required entity schemas.
    $this->installEntitySchema('user');
    
    // Install only the specific configurations we need for ModuleImport entity.
    // Skip full ai_dashboard config to avoid dependency issues.
    $this->installConfig(['system', 'field']);
  }

  /**
   * Test basic CRUD operations on ModuleImport entity.
   */
  public function testModuleImportCrud() {
    // Test creation.
    $import = ModuleImport::create([
      'id' => 'test_import',
      'label' => 'Test Import',
      'source_type' => 'drupal_org',
      'project_name' => 'ai',
      'project_id' => '12345',
      'filter_tags' => 'AI, Testing',
      'filter_component' => 'AI',
      'status_filter' => ['1', '8'],
      'max_issues' => 100,
      'date_filter' => '2024-01-01',
      'active' => TRUE,
    ]);

    $this->assertTrue($import->isNew());
    $import->save();
    $this->assertFalse($import->isNew());

    // Test reading.
    $loaded_import = ModuleImport::load('test_import');
    $this->assertInstanceOf(ModuleImport::class, $loaded_import);
    $this->assertEquals('Test Import', $loaded_import->label());
    $this->assertEquals('drupal_org', $loaded_import->getSourceType());
    $this->assertEquals('ai', $loaded_import->getProjectMachineName());
    $this->assertEquals('12345', $loaded_import->getProjectId());
    $this->assertEquals(['AI', 'Testing'], $loaded_import->getFilterTags());
    $this->assertEquals('AI', $loaded_import->getFilterComponent());
    $this->assertEquals(['1', '8'], $loaded_import->getStatusFilter());
    $this->assertEquals(100, $loaded_import->getMaxIssues());
    $this->assertEquals('2024-01-01', $loaded_import->getDateFilter());
    $this->assertTrue($loaded_import->isActive());

    // Test updating.
    $loaded_import->setFilterComponent('Core');
    $loaded_import->setMaxIssues(200);
    $loaded_import->save();

    $updated_import = ModuleImport::load('test_import');
    $this->assertEquals('Core', $updated_import->getFilterComponent());
    $this->assertEquals(200, $updated_import->getMaxIssues());

    // Test deletion.
    $loaded_import->delete();
    $deleted_import = ModuleImport::load('test_import');
    $this->assertNull($deleted_import);
  }

  /**
   * Test component filter field functionality.
   */
  public function testComponentFilterField() {
    $import = ModuleImport::create([
      'id' => 'component_test',
      'label' => 'Component Test',
      'source_type' => 'drupal_org',
      'project_name' => 'experience_builder',
    ]);

    // Test default empty value.
    $this->assertEquals('', $import->getFilterComponent());

    // Test setting component filter.
    $import->setFilterComponent('AI');
    $this->assertEquals('AI', $import->getFilterComponent());

    // Test saving and loading.
    $import->save();
    $loaded = ModuleImport::load('component_test');
    $this->assertEquals('AI', $loaded->getFilterComponent());

    // Test clearing component filter.
    $loaded->setFilterComponent('');
    $loaded->save();
    $reloaded = ModuleImport::load('component_test');
    $this->assertEquals('', $reloaded->getFilterComponent());
  }

  /**
   * Test status filter processing including 'all_open' handling.
   */
  public function testStatusFilterProcessing() {
    $import = ModuleImport::create([
      'id' => 'status_test',
      'label' => 'Status Test',
      'source_type' => 'drupal_org',
      'project_name' => 'ai',
    ]);

    // Test specific status filters.
    $import->setStatusFilter(['1', '8']);
    $this->assertEquals(['1', '8'], $import->getStatusFilter());

    // Test 'all_open' filter expansion.
    $import->setStatusFilter(['all_open']);
    $expected_open = ['1', '13', '8', '14', '15', '2', '4', '16'];
    $this->assertEquals($expected_open, $import->getStatusFilter());

    // Test mixed filters (all_open should take precedence).
    $import->setStatusFilter(['all_open', '1', '8']);
    $this->assertEquals($expected_open, $import->getStatusFilter());
  }

  /**
   * Test tag filtering array conversion.
   */
  public function testTagFilterProcessing() {
    $import = ModuleImport::create([
      'id' => 'tag_test',
      'label' => 'Tag Test',
      'source_type' => 'drupal_org',
      'project_name' => 'ai',
    ]);

    // Test empty tags.
    $import->setFilterTags('');
    $this->assertEquals([], $import->getFilterTags());

    // Test single tag.
    $import->setFilterTags('AI');
    $this->assertEquals(['AI'], $import->getFilterTags());

    // Test multiple tags.
    $import->setFilterTags('AI,Core,Testing');
    $this->assertEquals(['AI', 'Core', 'Testing'], $import->getFilterTags());

    // Test tags with spaces.
    $import->setFilterTags('AI Initiative, Core API, Testing Suite');
    $this->assertEquals(['AI Initiative', 'Core API', 'Testing Suite'], $import->getFilterTags());

    // Test filtering empty values.
    $import->setFilterTags('AI,,Core,');
    $this->assertEquals(['AI', 'Core'], $import->getFilterTags());
  }

  /**
   * Test configuration export/import functionality.
   */
  public function testConfigurationExportImport() {
    // Create and save entity.
    $import = ModuleImport::create([
      'id' => 'export_test',
      'label' => 'Export Test',
      'source_type' => 'drupal_org',
      'project_name' => 'ai',
      'filter_component' => 'AI',
      'filter_tags' => 'AI,Testing',
      'status_filter' => ['1', '8'],
      'max_issues' => 500,
      'active' => TRUE,
    ]);
    $import->save();

    // Export configuration.
    $config = \Drupal::configFactory()->get('ai_dashboard.module_import.export_test');
    
    // Verify all fields are exported.
    $this->assertEquals('export_test', $config->get('id'));
    $this->assertEquals('Export Test', $config->get('label'));
    $this->assertEquals('drupal_org', $config->get('source_type'));
    $this->assertEquals('ai', $config->get('project_name'));
    $this->assertEquals('AI', $config->get('filter_component'));
    $this->assertEquals('AI,Testing', $config->get('filter_tags'));
    $this->assertEquals(['1', '8'], $config->get('status_filter'));
    $this->assertEquals(500, $config->get('max_issues'));
    $this->assertTrue($config->get('active'));

    // Delete entity and verify it's gone.
    $import->delete();
    $this->assertNull(ModuleImport::load('export_test'));

    // Import from configuration.
    $config_data = [
      'id' => 'export_test',
      'label' => 'Export Test',
      'source_type' => 'drupal_org',
      'project_name' => 'ai',
      'filter_component' => 'AI',
      'filter_tags' => 'AI,Testing',
      'status_filter' => ['1', '8'],
      'max_issues' => 500,
      'active' => TRUE,
    ];
    
    $imported = ModuleImport::create($config_data);
    $imported->save();

    // Verify imported entity.
    $loaded = ModuleImport::load('export_test');
    $this->assertInstanceOf(ModuleImport::class, $loaded);
    $this->assertEquals('AI', $loaded->getFilterComponent());
    $this->assertEquals(['AI', 'Testing'], $loaded->getFilterTags());
  }

  /**
   * Test default values.
   */
  public function testDefaultValues() {
    $import = ModuleImport::create([
      'id' => 'defaults_test',
      'label' => 'Defaults Test',
      'source_type' => 'drupal_org',
      'project_name' => 'ai',
    ]);

    // Test defaults.
    $this->assertEquals('', $import->getFilterComponent());
    $this->assertEquals([], $import->getFilterTags());
    $this->assertEquals([], $import->getStatusFilter());
    $this->assertNull($import->getMaxIssues());
    $this->assertNull($import->getDateFilter());
    $this->assertTrue($import->isActive());
  }

  /**
   * Test method chaining for setters.
   */
  public function testMethodChaining() {
    $import = ModuleImport::create([
      'id' => 'chaining_test',
      'label' => 'Chaining Test',
      'source_type' => 'drupal_org',
    ]);

    // Test that setters return the entity for chaining.
    $result = $import
      ->setProjectMachineName('ai')
      ->setFilterComponent('AI')
      ->setFilterTags('AI,Testing')
      ->setStatusFilter(['1', '8'])
      ->setMaxIssues(100)
      ->setDateFilter('2024-01-01')
      ->setActive(FALSE);

    $this->assertSame($import, $result);
    $this->assertEquals('ai', $import->getProjectMachineName());
    $this->assertEquals('AI', $import->getFilterComponent());
    $this->assertEquals(['AI', 'Testing'], $import->getFilterTags());
    $this->assertEquals(['1', '8'], $import->getStatusFilter());
    $this->assertEquals(100, $import->getMaxIssues());
    $this->assertEquals('2024-01-01', $import->getDateFilter());
    $this->assertFalse($import->isActive());
  }

}