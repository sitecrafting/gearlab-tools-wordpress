# GearLab Tools for WordPress

![Travis CI build status](https://api.travis-ci.org/sitecrafting/gearlab-tools-wordpress.svg?branch=master)

Integrate your WordPress site seamlessly with the GearLab Tools suite.

## Installation

### Installing Manually

Download the .zip archive of the latest release and place the extracted directory in `wp-content/plugins`. Activate the plugin from the WP Admin as you normally would.

### Installing via Composer

Add the requirement to your composer.json:

```sh
composer require sitecrafting/gearlab-tools-wordpress --prefer-dist
```

*NOTE:* if you are tracking your WordPress codebase as one big monorepo, the `--prefer-dist` flag is important! It tells Composer to find and download the .zip archive instead of the full Git repository. Without this flag, it will create the plugin directory as a [Git submodule](https://git-scm.com/book/en/v2/Git-Tools-Submodules) and strange things will happen.

## Usage

### Getting Started

After installing and activating the plugin, go to the *GearLab Tools* section of the WP Admin. Enter your *API Key*, *Collection ID*, and *Base URI* as provided by GearLab. All settings are required.

### Search

With GearLab Tools, you can override the default WordPress search functionality, which is extremely limited by default, with results from the ElasticSearch crawler that powers GearLab Search. To do this you must first enter your settings as described above, in **Getting Started**.

Once you've entered your GearLab Tools settings, but before enabling overriding WordPress search globally, you can test from the command line to see if you get results. To do this, run `wp gearlab search <search term>`. You should see a JSON object like this:

```json
{"results": [{"url": "https://www.example.com/example-page", "title": "Example Page", "snippet": "Some content"}, ...]}
```

Once you've entered the settings above correctly, you're ready to enable GearLab Tools Search to override the default WP search. Enable the option **Override default WordPress search** and save the settings again. You should now see a basic search results page rendered by GearLab Tools whenever you perform a search.

#### Search Shortcode

Out of the box, you can use the `[gearlab_search]` shortcode in any RTE that supports shortcodes. This is the recommended approach for most cases.

However, basic searches (using WordPress's standard `s` query param), will still render your theme's default search.php template (assuming there is one). You can redirect global searches to the page your shortcode lives on in the Settings. Go to **Settings > GearLab Tools** and select **Redirect searches to a specific page**. Type the URI, e.g. `/search`, in the text box that appears.

Save your changes and you're good to go! Default searches will now redirect to your page. Note that all query string parameters will be preserved *except* `s`, which will be renamed to `glt_search` to avoid conflicting with WordPress's default functionality.

#### Overriding Timber templates

This plugin has special support for [Timber](https://www.upstatement.com/timber/).

To override how Timber renders your search results, you can add Theme Overrides. These are files that the plugin looks for in your theme and loads it if finds them, falling back to the plugin's own templates if it does not. These files are (relative to your theme root):

* `gearlab-tools/search.php`: Main PHP template to run. If you want to support e.g. a UI for filtering on `metaTag`, override this file and see the examples below.
* `(Timber template path)/gearlab-tools/search.twig`: Main Twig template for rendering the search page. If you don't need to change the backend functionality (e.g. how search filters are applied) and only want to change how the overall search results page is rendered, override this file.
* `(Timber template path)/gearlab-tools/search-result.twig`: Render a single search result with Twig.

...where `(Timber template path)` is anywhere that Timber already knows to look for Twig templates. The most commone place is the `templates` or `views` directory in your theme.

#### Override the GearLab search.php template

Place something like the following in your theme at `gearlab-tools/search.php` (this example does not rely on Timber):

```php
// NOTE: the client may throw an exception!
use Swagger\Client\ApiException;

// Call out to the API
try {
  // search with some sensible defaults
  $response = GearLab\search();
} catch (ApiException $e) {
  error_log($e->getMessage());
  $response = [];
}

wp_header();

// Render results
foreach (($response['results'] ?? []) as $result) : ?>
  <article class="search-result">
    <h1><a href="<?= $result['url'] ?>><?= $result['title'] ?></a></h1>
    <p><?= $result['snippet'] ?></p>
  </article>
<?php endforeach; ?>

<?= GearLab\paginate_links($response) ?>

<?php wp_footer(); ?>
```

For more custom behavior, you can pass params directly to `GearLab\search()`:

```php
use Swagger\Client\ApiException;

// Override your site's pagination settings.
$count = 25;

// Note that we can't use $paged here, because WordPress core won't
// necessarily report the same number of pages as GearLab, leading to 404s
// in cases where GearLab has more result pages than WP would.
$pageOffset = ($_GET['page_num'] ?? 1) - 1;


// Call out to the API
try {
  $response = GearLab\search([
    // Pass the user's search term to the API.
    'query'     => get_query_var('s'),
    // Tell the API how many results we want per page.
    'resLength' => $count,
    // Tell the API which page of results we want.
    'resOffset' => $pageOffset * $count,
    // Tell the API to only return results of a certain type
    'metaKey'   => $_GET['my_content_type'],
  ]);
} catch (ApiException $e) {
  error_log($e->getMessage());
  $response = [];
}


// Render results
foreach (($response['results'] ?? []) as $result) : ?>
  <article class="search-result">
    <h1><a href="<?= $result['url'] ?>><?= $result['title'] ?></a></h1>
    <p><?= $result['snippet'] ?></p>
  </article>
<?php endforeach; ?>

<?= GearLab\paginate_links($response) ?>
```

### Search Autocomplete

In addition to providing superior search results, GearLab Tools also adds search autocomplete to your search template out of the box. You don't need to do anything to make this work, although you may want to override the default [`jquery-ui-autocomplete`](https://api.jqueryui.com/autocomplete/) styles.

The only assumption this module makes about your HTML is that the search input can be found at the selector `form [name="s"]`, i.e. a form element whose `name` attribute is `"s"`. Because of how WordPress search is implemented, this assumption will hold true unless your search functionality is overriding WordPress core in an advanced way.

For the curious, this feature works by registering a custom WP REST route at `/wp-json/gearlab/v2/completions` and telling `jquery-ui-autocomplete` to grab its autocomplete suggestions from that route.

### WP-CLI Custom Commands

The plugin implements WP-CLI commands for major GearLab Tools REST endpoints, such as search:

```bash
wp gearlab search tacos
```

This will automatically use the credentials you've configured in the plugin settings.

Run `wp gearlab --help` to list subcommands.

As with other WordPress options, you can configure the plugin options with `wp option`:

```bash
wp option get gearlab_api_key
wp option get gearlab_collection_id
wp option get gearlab_base_uri
wp option get gearlab_enabled
wp option set gearlab_api_key supersecure
wp option set gearlab_collection_id 12345
wp option https://prd.search-api-gateway.aws.gearlabnw.net
wp option set gearlab_enabled 1
```

## Development

To build a new release, choose the Git tag name and run:

```bash
bin/build-release.sh <TAG>
```

This will create a .tar.gz and a .zip archive which you can upload to a new release on GitHub.

If you have [`hub`](https://hub.github.com/) installed, the script will detect it and prompt you to optionally create a GitHub release directly.
