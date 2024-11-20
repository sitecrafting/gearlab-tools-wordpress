<?php

/**
 * Plugin Name: GearLab Tools
 * Description: Integrate your WordPress site with the GearLab Tools platform
 * Plugin URI: https://gearlab.tools
 * Author: Coby Tamayo <ctamayo@sitecrafting.com>
 * Author URI: https://www.sitecrafting.com/
 * Version: 1.1.0
 */

// no script kiddiez
if (!defined('ABSPATH')) {
  return;
}

// Require the composer autoloader, making educated guesses as to where it is.
// If it exists, honor the project-wide autoloader first, but do not treat it
// as mutually exclusive from the plugin's autoloader, since you can't assume
// the project pulls in the GLT plugin as a dependency.
if (file_exists(ABSPATH . 'vendor/autoload.php')) {
  require_once ABSPATH . 'vendor/autoload.php';
}
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/wp-api.php';

use GearLab\Api\Client;
use GearLab\Plugin\AdminPage;
use GearLab\Plugin\Rest\GearLabRestController;
use GearLab\Plugin\TimberTwigHelper;

use Swagger\Client\ApiException;
use Timber\Timber;


define('GEARLAB_PLUGIN_WEB_PATH', plugin_dir_url(__FILE__));
define('GEARLAB_PLUGIN_JS_ROOT', GEARLAB_PLUGIN_WEB_PATH . 'js');
define('GEARLAB_PLUGIN_VIEW_PATH', __DIR__ . '/views');

// "1" for backwards compatibility, from when this was a single toggle setting
define('GEARLAB_OVERRIDE_METHOD_TIMBER', '1');
define('GEARLAB_OVERRIDE_METHOD_SHORTCODE', 'shortcode');

/*
 * Add the main hook for getting an API client instance
 */
add_filter('gearlab/api/client', function() {
  // avoid instantiating the same object twice
  static $client;

  // instantiate a Client instance if we don't have one already
  return $client ?: new Client([
    'key'        => get_option('gearlab_api_key'),
    'collection' => get_option('gearlab_collection_id'),
    'baseUri'    => get_option('gearlab_base_uri'),
  ]);
});


/*
 * Disable default search
 */
add_action('init', GearLab\disable_default_wp_search::class);


/*
 * Add WP Admin pages (just the one page, actually 🐦)
 */
add_action('admin_menu', function() {
  // Create an admin page responsible for managing the main
  // GearLab Tools credentials
  $page = AdminPage::add_options_page([
    'option_keys' => [
      'gearlab_api_key',
      'gearlab_collection_id',
      'gearlab_base_uri',
      'gearlab_search_enabled',
      'gearlab_search_redirect',
    ],
  ]);
  // Process any user updates
  if ($_POST) {
    $page->save_settings($_POST);
  }
  // Render the page
  $page->init()->add_meta_boxes();
});

add_action('admin_enqueue_scripts', function() {
  wp_enqueue_style(
    'gearlab-tools-admin-styles',
    GEARLAB_PLUGIN_WEB_PATH . 'css/gearlab-tools-admin.css'
  );
});

/*
 * Add REST Routes
 */
add_action('rest_api_init', function() {
  $controller = new GearLabRestController();
  $controller->register_routes();
});

/*
 * Add JS for autocomplete suggestions.
 */
add_action('wp_enqueue_scripts', function() {
  $enqueue = apply_filters('gearlab/search/enqueue_js', !is_admin());
  if ($enqueue) {
    GearLab\enqueue_scripts();
  }
});

if (class_exists(WP_CLI::class)) {
  $command = new GearLab\WpCli\GearLabCommand();
  WP_CLI::add_command('gearlab', $command);
}

// Inject Timber-specific specializations.
add_action('plugins_loaded', function() {
  if (class_exists(Timber::class)) {
    // Timber is running. Extend it!
    add_filter('timber/twig/functions', function ($functions) {
      $functions['gearlab_paginate_links'] = [
          'callable' => GearLab\paginate_links::class,
      ];
  
      return $functions;
    });

    // Add our views/twig to Timber's list of locations.
    add_filter('timber/locations', function($templatePaths) {
      $templatePaths[] = GEARLAB_PLUGIN_VIEW_PATH . '/twig';
      return $templatePaths;
    });

    // Load our plugin's default.
    add_filter('gearlab/timber/search_template', function($searchTpl) {
      if (!file_exists($searchTpl)) {
        $searchTpl = GEARLAB_PLUGIN_VIEW_PATH . '/timber/search.php';
      }

      return $searchTpl;
    });

    // Tell our plugin how to render the search view, calling Timber::render()
    // by default.
    add_action('gearlab/timber/render_search', [Timber::class, 'render'], 10, 3);
  }
});


/*
 * Add support for the Search UI shortcode.
 */

add_filter('gearlab/render', function($tpl, $data = []) {
  $path = get_template_directory() . '/gearlab-tools/' . $tpl;

  if (!file_exists($path)) {
    $path = GEARLAB_PLUGIN_VIEW_PATH . '/frontend/' . $tpl;
  }

  if (file_exists($path)) {
    ob_start();
    require $path;
    return ob_get_clean();
  }
}, 10, 2);

add_action('init', function() {
  global $wp;
  $wp->add_query_var('glt_search');
  $wp->add_query_var('glt_meta_tag');
  $wp->add_query_var('glt_page_num');

  add_shortcode('gearlab_search', function($atts = []) {
    global $post;

    // Override how search paramaters are set in shortcode context.
    add_filter('gearlab/search/query', function() {
      return get_query_var('glt_search');
    });
    add_filter('gearlab/search/meta_tag', function() {
      return get_query_var('glt_meta_tag');
    });
    add_filter('gearlab/search/page_num', function() {
      return get_query_var('glt_page_num') ?: 1;
    });
    add_filter('gearlab/search/page_num_param', function() {
      return 'glt_page_num';
    });

    $searchQuery = apply_filters('gearlab/search/query', '');

    try {
      $response = GearLab\search();
    } catch (ApiException $e) {
      do_action('gearlab/api/error/api_exception', sprintf(
        'GearLab API error: %s',
        $e->getMessage()
      ));
      $response = [];
    } catch (InvalidArgumentException $e) {
      do_action('gearlab/api/error/invalid_client_args', sprintf(
        'Error setting up GearLab client: %s',
        $e->getMessage()
      ));
      $response = [];
    }

    return apply_filters('gearlab/render', 'search-results.php', [
      'post'     => $post,
      'query'    => $searchQuery,
      'response' => $response,
    ]);
  });

  /*
   * Redirect to the configured search page
   */
  add_action('template_redirect', function() {
    if (!GearLab\shortcode_redirect_enabled()) {
      return;
    }

    global $wp_query;
    $dest = get_option('gearlab_search_redirect');
    if ($dest && $wp_query->is_search()) {
      $params = array_merge($_GET, [
        'glt_search' => get_query_var('s')
      ]);
      unset($params['s']);

      wp_redirect($dest . '?' . http_build_query($params));
      exit;
    }
  });
});