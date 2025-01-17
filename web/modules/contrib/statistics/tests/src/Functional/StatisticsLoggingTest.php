<?php

declare(strict_types=1);

namespace Drupal\Tests\statistics\Functional;

use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests request logging for cached and uncached pages.
 *
 * We subclass BrowserTestBase rather than StatisticsTestBase, because we
 * want to test requests from an anonymous user.
 *
 * @group statistics
 */
class StatisticsLoggingTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['node', 'statistics', 'block', 'locale'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * User with permissions to create and edit pages.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $authUser;

  /**
   * Associative array representing a hypothetical Drupal language.
   *
   * @var array
   */
  protected $language;

  /**
   * The Guzzle HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * A test node.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $node;

  /**
   * Verifies node hit counter logging and script placement.
   */
  public function testLogging() {
    $path = 'node/' . $this->node->id();
    $module_path = $this->getModulePath('statistics');
    $stats_path = base_path() . $module_path . '/statistics.php';
    $lib_path = base_path() . $module_path . '/statistics.js';
    $expected_library = '/<script src=".*?' . preg_quote($lib_path, '/.') . '.*?">/is';

    // Verify that logging scripts are not found on a non-node page.
    $this->drupalGet('node');
    $settings = $this->getDrupalSettings();
    // Statistics library JS should not be present.
    $this->assertSession()->responseNotMatches($expected_library);
    $this->assertFalse(isset($settings['statistics']), 'Statistics settings not found on node page.');

    // Verify that logging scripts are not found on a non-existent node page.
    $this->drupalGet('node/9999');
    $settings = $this->getDrupalSettings();
    // Statistics library JS should not be present.
    $this->assertSession()->responseNotMatches($expected_library);
    $this->assertFalse(isset($settings['statistics']), 'Statistics settings not found on node page.');

    // Verify that logging scripts are found on a valid node page.
    $this->drupalGet($path);
    $settings = $this->getDrupalSettings();
    $this->assertSession()->responseMatches($expected_library);
    $this->assertSame($settings['statistics']['data']['nid'], $this->node->id(), 'Found statistics settings on node page.');

    // Verify the same when loading the site in a non-default language.
    $this->drupalGet($this->language['langcode'] . '/' . $path);
    $settings = $this->getDrupalSettings();
    $this->assertSession()->responseMatches($expected_library);
    $this->assertSame($settings['statistics']['data']['nid'], $this->node->id(), 'Found statistics settings on valid node page in a non-default language.');

    // Manually call statistics.php to simulate ajax data collection behavior.
    global $base_root;
    $post = ['nid' => $this->node->id()];
    $this->client->post($base_root . $stats_path, ['form_params' => $post]);
    $node_counter = \Drupal::service('statistics.storage.node')
      ->fetchView($this->node->id());
    $this->assertSame(1, $node_counter->getTotalCount());

    // Try fetching statistics for an invalid node ID and verify it returns
    // FALSE.
    $node_id = 1000000;
    $node = Node::load($node_id);
    $this->assertNull($node);

    // This is a test specifically for the deprecated statistics_get() function
    // and so should remain unconverted until that function is removed.
    $result = \Drupal::service('statistics.storage.node')->fetchView($node_id);
    $this->assertFalse($result);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create Basic page node type.
    if ($this->profile != 'standard') {
      $this->drupalCreateContentType([
        'type' => 'page',
        'name' => 'Basic page',
      ]);
    }

    $this->authUser = $this->drupalCreateUser([
      // For node creation.
      'access content',
      'create page content',
      'edit own page content',
      // For language negotiation administration.
      'administer languages',
      'access administration pages',
    ]);

    // Ensure we have a node page to access.
    $this->node = $this->drupalCreateNode([
      'title' => $this->randomMachineName(255),
      'uid' => $this->authUser->id(),
    ]);

    // Add a custom language and enable path-based language negotiation.
    $this->drupalLogin($this->authUser);
    $this->language = [
      'predefined_langcode' => 'custom',
      'langcode' => 'xx',
      'label' => $this->randomMachineName(16),
      'direction' => 'ltr',
    ];
    $this->drupalGet('admin/config/regional/language/add');
    $this->submitForm($this->language, 'Add custom language');
    $this->drupalGet('admin/config/regional/language/detection');
    $this->submitForm(['language_interface[enabled][language-url]' => 1], 'Save settings');
    $this->drupalLogout();

    // Enable access logging.
    $this->config('statistics.settings')
      ->set('count_content_views', 1)
      ->save();

    $this->client = \Drupal::httpClient();
  }

}
