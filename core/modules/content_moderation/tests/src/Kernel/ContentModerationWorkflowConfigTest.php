<?php

namespace Drupal\Tests\content_moderation\Kernel;

use Drupal\Core\Config\ConfigImporterException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\workflows\Entity\Workflow;

/**
 * Tests how Content Moderation handles workflow config changes.
 *
 * @group content_moderation
 */
class ContentModerationWorkflowConfigTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'content_moderation',
    'user',
    'system',
    'text',
    'workflows',
  ];

  /**
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\workflows\Entity\Workflow
   */
  protected $workflow;

  /**
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorage
   */
  protected $workflowStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig('content_moderation');

    NodeType::create([
      'type' => 'example',
    ])->save();

    $workflow = Workflow::load('editorial');
    $workflow->getTypePlugin()
      ->addState('test1', 'Test one')
      ->addState('test2', 'Test two')
      ->addState('test3', 'Test three')
      ->addEntityTypeAndBundle('node', 'example');
    $workflow->save();
    $this->workflow = $workflow;

    $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));
  }

  /**
   * Test deleting a state via config import.
   */
  public function testDeletingStateViaConfiguration() {
    $config_data = $this->config('workflows.workflow.editorial')->get();
    unset($config_data['type_settings']['states']['test1']);
    \Drupal::service('config.storage.sync')->write('workflows.workflow.editorial', $config_data);

    // There are no Nodes with the moderation state test1, so this should run
    // with no errors.
    $this->configImporter()->reset()->import();

    $node = Node::create([
      'type' => 'example',
      'title' => 'Test title',
      'moderation_state' => 'test2',
    ]);
    $node->save();

    $config_data = $this->config('workflows.workflow.editorial')->get();
    unset($config_data['type_settings']['states']['test2']);
    unset($config_data['type_settings']['states']['test3']);
    \Drupal::service('config.storage.sync')->write('workflows.workflow.editorial', $config_data);

    // Now there is a Node with the moderation state test2, this will fail.
    try {
      $this->configImporter()->reset()->import();
      $this->fail('ConfigImporterException not thrown, invalid import was not stopped due to deleted state.');
    }
    catch (ConfigImporterException $e) {
      $this->assertEqual($e->getMessage(), 'There were errors validating the config synchronization.');
      $error_log = $this->configImporter->getErrors();
      $expected = ['The moderation state Test two is being used, but is not in the source storage.'];
      $this->assertEqual($expected, $error_log);
    }

    \Drupal::service('config.storage.sync')->delete('workflows.workflow.editorial');

    // An error should be thrown when trying to delete an in use workflow.
    try {
      $this->configImporter()->reset()->import();
      $this->fail('ConfigImporterException not thrown, invalid import was not stopped due to deleted workflow.');
    }
    catch (ConfigImporterException $e) {
      $this->assertEqual($e->getMessage(), 'There were errors validating the config synchronization.');
      $error_log = $this->configImporter->getErrors();
      $expected = [
        'The moderation state Test two is being used, but is not in the source storage.',
        'The workflow Editorial is being used, and cannot be deleted.',
      ];
      $this->assertEqual($expected, $error_log);
    }
  }

}
