<?php

namespace Drupal\views\Plugin\views\display;

use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuParentFormSelectorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * The plugin that handles a full page.
 *
 * @ingroup views_display_plugins
 */
#[ViewsDisplay(
  id: "page",
  title: new TranslatableMarkup("Page"),
  help: new TranslatableMarkup("Display the view as a page, with a URL and menu links."),
  uses_menu_links: TRUE,
  uses_route: TRUE,
  contextual_links_locations: ["page"],
  theme: "views_view",
  admin: new TranslatableMarkup("Page"),
)]
class Page extends PathPluginBase {

  /**
   * The current page render array.
   *
   * @var array
   */
  protected static $pageRenderArray;

  /**
   * Whether the display allows attachments.
   *
   * @var bool
   */
  protected $usesAttachments = TRUE;

  /**
   * The menu storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $menuStorage;

  /**
   * The parent form selector service.
   *
   * @var \Drupal\Core\Menu\MenuParentFormSelectorInterface
   */
  protected $parentFormSelector;

  /**
   * Constructs a Page object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key value store.
   * @param \Drupal\Core\Entity\EntityStorageInterface $menu_storage
   *   The menu storage.
   * @param \Drupal\Core\Menu\MenuParentFormSelectorInterface $parent_form_selector
   *   The parent form selector service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteProviderInterface $route_provider, StateInterface $state, EntityStorageInterface $menu_storage, MenuParentFormSelectorInterface $parent_form_selector) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $route_provider, $state);
    $this->menuStorage = $menu_storage;
    $this->parentFormSelector = $parent_form_selector;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('router.route_provider'),
      $container->get('state'),
      $container->get('entity_type.manager')->getStorage('menu'),
      $container->get('menu.parent_form_selector')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getRoute($view_id, $display_id) {
    $route = parent::getRoute($view_id, $display_id);

    // Explicitly set HTML as the format for Page displays.
    $route->setRequirement('_format', 'html');

    if ($this->getOption('use_admin_theme')) {
      $route->setOption('_admin_route', TRUE);
    }

    return $route;
  }

  /**
   * Sets the current page views render array.
   *
   * @param array $element
   *   (optional) A render array. If not specified the previous element is
   *   returned.
   *
   * @return array
   *   The page render array.
   */
  public static function &setPageRenderArray(?array &$element = NULL) {
    if (isset($element)) {
      static::$pageRenderArray = &$element;
    }

    return static::$pageRenderArray;
  }

  /**
   * Gets the current views page render array.
   *
   * @return array
   *   The page render array.
   */
  public static function &getPageRenderArray() {
    return static::$pageRenderArray;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['menu'] = [
      'contains' => [
        'type' => ['default' => 'none'],
        'title' => ['default' => ''],
        'description' => ['default' => ''],
        'weight' => ['default' => 0],
        'enabled' => ['default' => TRUE],
        'menu_name' => ['default' => 'main'],
        'parent' => ['default' => ''],
        'context' => ['default' => ''],
        'expanded' => ['default' => FALSE],
      ],
    ];
    $options['tab_options'] = [
      'contains' => [
        'type' => ['default' => 'none'],
        'title' => ['default' => ''],
        'description' => ['default' => ''],
        'weight' => ['default' => 0],
      ],
    ];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public static function buildBasicRenderable($view_id, $display_id, array $args = [], ?Route $route = NULL) {
    $build = parent::buildBasicRenderable($view_id, $display_id, $args);

    if ($route) {
      $build['#view_id'] = $route->getDefault('view_id');
      $build['#view_display_plugin_id'] = $route->getOption('_view_display_plugin_id');
      $build['#view_display_show_admin_links'] = $route->getOption('_view_display_show_admin_links');
    }
    else {
      throw new \BadFunctionCallException('Missing route parameters.');
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    parent::execute();

    // And now render the view.
    $render = $this->view->render();

    // First execute the view so it's possible to get tokens for the title.
    // And the title, which is much easier.
    // @todo Figure out how to support custom response objects. Maybe for pages
    //   it should be dropped.
    if (is_array($render)) {
      $render += [
        '#title' => ['#markup' => $this->view->getTitle(), '#allowed_tags' => Xss::getHtmlTagList()],
      ];
    }
    return $render;
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(&$categories, &$options) {
    parent::optionsSummary($categories, $options);

    $menu = $this->getOption('menu');
    if (!is_array($menu)) {
      $menu = ['type' => 'none'];
    }
    switch ($menu['type']) {
      case 'none':
      default:
        $menu_str = $this->t('No menu');
        break;

      case 'normal':
        $menu_str = $this->t('Normal: @title', ['@title' => $menu['title']]);
        break;

      case 'tab':
      case 'default tab':
        $menu_str = $this->t('Tab: @title', ['@title' => $menu['title']]);
        break;
    }

    $options['menu'] = [
      'category' => 'page',
      'title' => $this->t('Menu'),
      'value' => Unicode::truncate($menu_str, 24, FALSE, TRUE),
    ];

    // This adds a 'Settings' link to the style_options setting if the style
    // has options.
    if ($menu['type'] == 'default tab') {
      $options['menu']['setting'] = $this->t('Parent menu link');
      $options['menu']['links']['tab_options'] = $this->t('Change settings for the parent menu');
    }

    // If the display path starts with 'admin/' the page will be rendered with
    // the Administration theme regardless of the 'use_admin_theme' option
    // therefore, we need to set the summary message to reflect this.
    if (str_starts_with($this->getOption('path') ?? '', 'admin/')) {
      $admin_theme_text = $this->t('Yes (admin path)');
    }
    elseif ($this->getOption('use_admin_theme')) {
      $admin_theme_text = $this->t('Yes');
    }
    else {
      $admin_theme_text = $this->t('No');
    }

    $options['use_admin_theme'] = [
      'category' => 'page',
      'title' => $this->t('Administration theme'),
      'value' => $admin_theme_text,
      'desc' => $this->t('Use the administration theme when rendering this display.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    switch ($form_state->get('section')) {
      case 'menu':
        $form['#title'] .= $this->t('Menu link entry');
        $form['menu'] = [
          '#prefix' => '<div class="clearfix">',
          '#suffix' => '</div>',
          '#tree' => TRUE,
        ];
        $menu = $this->getOption('menu');
        if (empty($menu)) {
          $menu = ['type' => 'none', 'title' => '', 'weight' => 0, 'expanded' => FALSE];
        }
        $form['menu']['type'] = [
          '#prefix' => '<div class="views-left-30">',
          '#suffix' => '</div>',
          '#title' => $this->t('Type'),
          '#type' => 'radios',
          '#options' => [
            'none' => $this->t('No menu entry'),
            'normal' => $this->t('Normal menu entry'),
            'tab' => $this->t('Menu tab'),
            'default tab' => $this->t('Default menu tab'),
          ],
          '#default_value' => $menu['type'],
        ];

        $form['menu']['title'] = [
          '#prefix' => '<div class="views-left-50">',
          '#title' => $this->t('Menu link title'),
          '#type' => 'textfield',
          '#default_value' => $menu['title'],
          '#states' => [
            'visible' => [
              [
                ':input[name="menu[type]"]' => ['value' => 'normal'],
              ],
              [
                ':input[name="menu[type]"]' => ['value' => 'tab'],
              ],
              [
                ':input[name="menu[type]"]' => ['value' => 'default tab'],
              ],
            ],
          ],
        ];
        $form['menu']['description'] = [
          '#title' => $this->t('Description'),
          '#type' => 'textfield',
          '#default_value' => $menu['description'],
          '#description' => $this->t("Shown when hovering over the menu link."),
          '#states' => [
            'visible' => [
              [
                ':input[name="menu[type]"]' => ['value' => 'normal'],
              ],
              [
                ':input[name="menu[type]"]' => ['value' => 'tab'],
              ],
              [
                ':input[name="menu[type]"]' => ['value' => 'default tab'],
              ],
            ],
          ],
        ];
        $form['menu']['expanded'] = [
          '#title' => $this->t('Show as expanded'),
          '#type' => 'checkbox',
          '#default_value' => !empty($menu['expanded']),
          '#description' => $this->t('If selected and this menu link has children, the menu will always appear expanded.'),
        ];

        $menu_parent = $menu['menu_name'] . ':' . $menu['parent'];
        $menu_link = 'views_view:views.' . $form_state->get('view')->id() . '.' . $form_state->get('display_id');
        $form['menu']['parent'] = $this->parentFormSelector->parentSelectElement($menu_parent, $menu_link);
        $form['menu']['parent'] += [
          '#title' => $this->t('Parent'),
          '#description' => $this->t('The maximum depth for a link and all its children is fixed. Some menu links may not be available as parents if selecting them would exceed this limit.'),
          '#attributes' => ['class' => ['menu-title-select']],
          '#states' => [
            'visible' => [
              [
                ':input[name="menu[type]"]' => ['value' => 'normal'],
              ],
              [
                ':input[name="menu[type]"]' => ['value' => 'tab'],
              ],
            ],
          ],
        ];
        $form['menu']['weight'] = [
          '#title' => $this->t('Weight'),
          '#type' => 'textfield',
          '#default_value' => $menu['weight'] ?? 0,
          '#description' => $this->t('In the menu, the heavier links will sink and the lighter links will be positioned nearer the top.'),
          '#states' => [
            'visible' => [
              [
                ':input[name="menu[type]"]' => ['value' => 'normal'],
              ],
              [
                ':input[name="menu[type]"]' => ['value' => 'tab'],
              ],
              [
                ':input[name="menu[type]"]' => ['value' => 'default tab'],
              ],
            ],
          ],
        ];
        $form['menu']['context'] = [
          '#title' => $this->t('Context'),
          '#suffix' => '</div>',
          '#type' => 'checkbox',
          '#default_value' => !empty($menu['context']),
          '#description' => $this->t('Displays the link in contextual links'),
          '#states' => [
            'visible' => [
              ':input[name="menu[type]"]' => ['value' => 'tab'],
            ],
          ],
        ];
        break;

      case 'tab_options':
        $form['#title'] .= $this->t('Default tab options');
        $tab_options = $this->getOption('tab_options');
        if (empty($tab_options)) {
          $tab_options = ['type' => 'none', 'title' => '', 'weight' => 0];
        }

        $form['tab_markup'] = [
          '#markup' => '<div class="js-form-item form-item description">' . $this->t('When providing a menu link as a tab, Drupal needs to know what the parent menu link of that tab will be. Sometimes the parent will already exist, but other times you will need to have one created. The path of a parent link will always be the same path with the last part left off. i.e, if the path to this view is <em>foo/bar/baz</em>, the parent path would be <em>foo/bar</em>.') . '</div>',
        ];

        $form['tab_options'] = [
          '#prefix' => '<div class="clearfix">',
          '#suffix' => '</div>',
          '#tree' => TRUE,
        ];
        $form['tab_options']['type'] = [
          '#prefix' => '<div class="views-left-25">',
          '#suffix' => '</div>',
          '#title' => $this->t('Parent menu link'),
          '#type' => 'radios',
          '#options' => ['none' => $this->t('Already exists'), 'normal' => $this->t('Normal menu link'), 'tab' => $this->t('Menu tab')],
          '#default_value' => $tab_options['type'],
        ];
        $form['tab_options']['title'] = [
          '#prefix' => '<div class="views-left-75">',
          '#title' => $this->t('Title'),
          '#type' => 'textfield',
          '#default_value' => $tab_options['title'],
          '#description' => $this->t('If creating a parent menu link, enter the title of the link.'),
          '#states' => [
            'visible' => [
              [
                ':input[name="tab_options[type]"]' => ['value' => 'normal'],
              ],
              [
                ':input[name="tab_options[type]"]' => ['value' => 'tab'],
              ],
            ],
          ],
        ];
        $form['tab_options']['description'] = [
          '#title' => $this->t('Description'),
          '#type' => 'textfield',
          '#default_value' => $tab_options['description'],
          '#description' => $this->t('If creating a parent menu link, enter the description of the link.'),
          '#states' => [
            'visible' => [
              [
                ':input[name="tab_options[type]"]' => ['value' => 'normal'],
              ],
              [
                ':input[name="tab_options[type]"]' => ['value' => 'tab'],
              ],
            ],
          ],
        ];
        $form['tab_options']['weight'] = [
          '#suffix' => '</div>',
          '#title' => $this->t('Tab weight'),
          '#type' => 'textfield',
          '#default_value' => $tab_options['weight'],
          '#size' => 5,
          '#description' => $this->t('If the parent menu link is a tab, enter the weight of the tab. Heavier tabs will sink and the lighter tabs will be positioned nearer to the first menu link.'),
          '#states' => [
            'visible' => [
              ':input[name="tab_options[type]"]' => ['value' => 'tab'],
            ],
          ],
        ];
        break;

      case 'use_admin_theme':
        $form['#title'] .= $this->t('Administration theme');
        $form['use_admin_theme'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use the administration theme'),
          '#default_value' => $this->getOption('use_admin_theme'),
        ];
        if (str_starts_with($this->getOption('path') ?? '', 'admin/')) {
          $form['use_admin_theme']['#description'] = $this->t('Paths starting with "@admin" always use the administration theme.', ['@admin' => 'admin/']);
          $form['use_admin_theme']['#default_value'] = TRUE;
          $form['use_admin_theme']['#attributes'] = ['disabled' => 'disabled'];
        }
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    parent::validateOptionsForm($form, $form_state);

    if ($form_state->get('section') == 'menu') {
      $path = $this->getOption('path');
      $menu_type = $form_state->getValue(['menu', 'type']);
      if ($menu_type == 'normal' && str_contains($path, '%')) {
        $form_state->setError($form['menu']['type'], $this->t('Views cannot create normal menu links for paths with a % in them.'));
      }

      if ($menu_type == 'default tab' || $menu_type == 'tab') {
        $bits = explode('/', $path);
        $last = array_pop($bits);
        if ($last == '%') {
          $form_state->setError($form['menu']['type'], $this->t('A display whose path ends with a % cannot be a tab.'));
        }
      }

      if ($menu_type != 'none' && $form_state->isValueEmpty(['menu', 'title'])) {
        $form_state->setError($form['menu']['title'], $this->t('Title is required for this menu type.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);

    switch ($form_state->get('section')) {
      case 'menu':
        $menu = $form_state->getValue('menu');
        [$menu['menu_name'], $menu['parent']] = explode(':', $menu['parent'], 2);
        $this->setOption('menu', $menu);
        // Send ajax form to options page if we use it.
        if ($form_state->getValue(['menu', 'type']) == 'default tab') {
          $form_state->get('view')->addFormToStack('display', $this->display['id'], 'tab_options');
        }
        break;

      case 'tab_options':
        $this->setOption('tab_options', $form_state->getValue('tab_options'));
        break;

      case 'use_admin_theme':
        if ($form_state->getValue('use_admin_theme')) {
          $this->setOption('use_admin_theme', $form_state->getValue('use_admin_theme'));
        }
        else {
          unset($this->options['use_admin_theme']);
          unset($this->display['display_options']['use_admin_theme']);
        }
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validate() {
    $errors = parent::validate();

    $menu = $this->getOption('menu');
    if (!empty($menu['type']) && $menu['type'] != 'none' && empty($menu['title'])) {
      $errors[] = $this->t('Display @display is set to use a menu but the menu link text is not set.', ['@display' => $this->display['display_title']]);
    }

    if ($menu['type'] == 'default tab') {
      $tab_options = $this->getOption('tab_options');
      if (!empty($tab_options['type']) && $tab_options['type'] != 'none' && empty($tab_options['title'])) {
        $errors[] = $this->t('Display @display is set to use a parent menu but the parent menu link text is not set.', ['@display' => $this->display['display_title']]);
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function getArgumentText() {
    return [
      'filter value not present' => $this->t('When the filter value is <em>NOT</em> in the URL'),
      'filter value present' => $this->t('When the filter value <em>IS</em> in the URL or a default is provided'),
      'description' => $this->t('The contextual filter values are provided by the URL.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPagerText() {
    return [
      'items per page title' => $this->t('Items per page'),
      'items per page description' => $this->t('Enter 0 for no limit.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();

    $menu = $this->getOption('menu');
    if ($menu['type'] === 'normal' && ($menu_entity = $this->menuStorage->load($menu['menu_name']))) {
      $dependencies[$menu_entity->getConfigDependencyKey()][] = $menu_entity->getConfigDependencyName();
    }

    return $dependencies;
  }

}
