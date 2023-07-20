<?php
/*
Plugin Name: Plugin Check
Description: A plugin that checks the status of WordPress and plugin updates, then cyclical sending to the entered API address..
Author: Katarzyna Piekarska
Version: 0.1
*/


if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

class PluginCheck
{
    private $options;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu_create']);
        add_action('admin_init', [$this, 'page_init']);

        //Cron
        add_action('init', [$this, 'wp_cron_activate']);
        add_action('hourly_cron_job_api', [$this, 'hourly_cron_job']);

    }


    /**
     * Adding the Plugin check item to the admin menu and calling the create_page method
     */
    public function admin_menu_create()
    {
        add_options_page(
            'Plugin Check | Setup',
            'Plugin Check',
            'manage_options',
            'plugin-check',
            array($this, 'create_page')
        );
    }

    /**
     * Form page
     */

    public function create_page()
    {

        $this->options = get_option('plugin_check_name');
        ?>
        <div class="wrap">
            <h1>Plugin Check</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('plugin_check_group');
                do_settings_sections('setting-admin');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Download wordpress plugin from api
     * @return mixed
     */

    public function get_plugin_version_from_repository($slug)
    {
        $url = "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slugs][]={$slug}";
        $plugins = $this->get_api_info($url);
        $version = '';
        foreach ($plugins as $key => $plugin) {
            if (!empty($plugin->requires))
                $version = $plugin->version;

        }
        return $version;
    }

    /**
     * Assembling a board with plugins
     * @return array
     */

    public function active_plugins_versions()
    {
        $allPlugins = get_plugins();
        $activePlugins = get_option('active_plugins');


        $res = [];

        foreach ($allPlugins as $key => $value) {
            if (in_array($key, $activePlugins)) { // display active only

                $slug = explode('/', $key)[0];
                $res[$slug]['current'] = $value['Version'];

                $repoVersion = $this->get_plugin_version_from_repository($slug);
                $res[$slug]['latest'] = $repoVersion == '' ? $value['Version'] : $repoVersion;
                $res[$slug]['requires_update'] = $res[$slug]['current'] != $res[$slug]['latest'];
            }
        }

        return $res;

    }

    /**
     * Post to url
     * @param $url
     * @return mixed
     */
    public function get_api_info($url)
    {
        $response = wp_remote_get($url);
        $json = $response['body'];
        return json_decode($json);


    }

    /**
     * Wordpress info table
     * @return array
     */
    public function get_wordpress_info()
    {
        global $wp_version;

        $obj = $this->get_api_info('https://api.wordpress.org/core/version-check/1.7/');
        $upgrade = $obj->offers[0];
        return [
            'current' => $wp_version,
            'last' => $upgrade->version,
            'requires_update' => $wp_version != $upgrade->version
        ];

    }


    /**
     * Returns the entire array to send to the api
     * @return array
     */

    public function get_info_plugins_and_wordpress()
    {

        $res = [];

        $res['wordpress'] = $this->get_wordpress_info();
        $res['plugins'] = $this->active_plugins_versions();


        return $res;

    }


    /**
     * Page init
     */
    public function page_init()
    {

        register_setting(
            'plugin_check_group',
            'plugin_check_name',
            array($this, 'validate_form')  // Callback
        );

        add_settings_section(
            'plugin_check_id',
            'Ustawienia Plugin Check ',
            '',
            'setting-admin'
        );

        add_settings_field(
            'is_active',
            'Aktywne',
            array($this, 'is_active_callback'), // Callback
            'setting-admin',
            'plugin_check_id'
        );

        add_settings_field(
            'url_api',
            'Adres url api',
            array($this, 'url_api_callback'),
            'setting-admin',
            'plugin_check_id'
        );
    }


    /**
     * Callback first field
     */
    public function is_active_callback()
    {
        ?>
        <input type="checkbox" name="plugin_check_name[is_active]"
               value="1"<?php checked(1 == $this->options['is_active']); ?> />
        <?php
    }

    /**
     * Callback second field
     */
    public function url_api_callback()
    {
        ?>
        <input id="url_api" placeholder="Podaj adres url http://" type="url" name="plugin_check_name[url_api]"
               value="<?= $this->options['url_api'] ?>"/>

        <?php
    }

    /**
     * Cron
     */
    public function wp_cron_activate()
    {

        $group_option = get_option('plugin_check_name');
        if (!empty($group_option) && $group_option['is_active']) {
            if (!wp_next_scheduled('hourly_cron_job_api')) {
                wp_schedule_event(time(), 'hourly', 'hourly_cron_job_api');
            }

        } else {
            if (wp_next_scheduled('hourly_cron_job_api')) {
                wp_clear_scheduled_hook('hourly_cron_job_api');
            }
        }
    }

    /**
     * The method to call cron
     */
    public function hourly_cron_job()
    {
         $group_option = get_option('plugin_check_name');
        if (!empty($group_option) && $group_option['is_active'] && $group_option['url_api']) {
            $data = json_encode($this->get_info_plugins_and_wordpress());

            wp_remote_post($group_option['url_api'], array(
                    'method' => 'POST',
                    'httpversion' => '1.0',
                    'sslverify' => false,
                    'body' => $data)
            );
        }
    }


}


if (is_admin())
    $my_settings_page = new PluginCheck();


