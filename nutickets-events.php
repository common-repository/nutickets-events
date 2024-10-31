<?php
/*
Plugin Name: Nutickets Events
Plugin URI: http://nutickets.com/wordpress
Description: The Nutickets Events Plugin allows Nutickets' clients to embed their ticketshop and other Nutickets enabled pages to their website seamlessly.
Author: Nuweb Systems Ltd.
Version: 1.0.6
Author URI: http://nutickets.com
License: GPL2

Copyright 2015 Nuweb  (email : support@nuweb.co.uk)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Define Constants
 */
if (!defined('NUTICKETS_PLUGIN_URL')) {
    define('NUTICKETS_PLUGIN_URL', plugins_url('/nutickets-events'));
}
if (!defined('NUTICKETS_BASE_URI')) {
    define('NUTICKETS_BASE_URI', 'https://admin.nutickets.com/events/login/');
}
if (!defined('NUTICKETS_SA_BASE_URI')) {
    define('NUTICKETS_SA_BASE_URI', 'https://admin.nutickets.co.za/events/login/');
}

// maps local settings key => api param name
$settings_map = array(
    'nutickets_width' => 'nutickets_width',
    'nutickets_height' => 'nutickets_height',
    'nutickets_type' => 'nutickets_type',
    'nutickets_style' => 'nutickets_style',
    'nutickets_created' => 'nutickets_created',
);

/**
 * Nutickets WP Class
 */
class WP_NuticketsEvents {

    public $nutickets_options; //Nutickets options array
    public $nutickets_settings_page; //Nutickets settings page
    static $instance; //allows plugin to be called externally without re-constructing

    /**
     * Register hooks with WP Core
     */
    function __construct() {
        global $wpdb;
        self::$instance = $this;

        // init settings array
        $this->nutickets_options = array(
            'active'                    => true,
            'nutickets_company_id'      => false,
            'nutickets_companies'       => false,
            'nutickets_created'         => false,
            'nutickets_width'           => false,
            'nutickets_height'          => false,
            'nutickets_autoscroll'      => true,
            'nutickets_region'          => 'uk', //uk, za
            'nutickets_type'            => 'ticketshop',
            'nutickets_style'           => 'transition: all 0.3s ease-in-out;margin-left:auto;margin-right:auto;display:block;',
        );

        //i18n
        add_action('init', array(
            $this,
            'i18n'
        ));

        //Write default options to database
        add_option('nutickets_settings', $this->nutickets_options);

        //Update options from database
        $this->nutickets_options = get_option('nutickets_settings');

        register_deactivation_hook(__FILE__, array(
            $this,
            'nutickets_deactivate'
        ));

        //Admin settings page actions
        add_action('admin_menu', array(
            $this,
            'nutickets_add_settings_page'
        ));

        add_action('admin_print_styles', array(
            $this,
            'nutickets_enqueue_admin'
        ));

        add_action('admin_enqueue_scripts', array(
            $this,
            'nutickets_localize_config'
        ));

        // action notifies user on admin menu if they don't have a key
        add_action( 'admin_menu', array(
            $this,
            'nutickets_notify_user_icon'
        ));

        add_action('wp_ajax_nutickets_update_option', array(
            $this,
            'nutickets_ajax_update_option'
        ));
        add_action('wp_ajax_nutickets_save_account', array(
            $this,
            'nutickets_save_account',
        ));

        add_action('wp_ajax_nutickets_disconnect_account', array(
            $this,
            'nutickets_disconnect_account',
        ));

        add_action('admin_head', array(
            $this,
            'nutickets_embed_page_button'
        ));

        add_action('admin_enqueue_scripts', array(
            $this,
            'nutickets_embed_page_button_css'
        ));

        add_shortcode( 'nutickets', array(
            $this,
            'nutickets_shortcode'
        ));
 
    }

    function nutickets_shortcode($atts) {
        $atts = shortcode_atts(array(
         'width'        => false,
         'height'       => false,
         'style'        => false,
         'url'          => false,
         'type'         => false,
         'autoscroll'   => false,
        ), $atts, 'nutickets');


        $width = $atts['width'];
        empty($width) && $width = $this->get_value_nutickets_width();
        empty($width) && $width = '100%';

        $height = $atts['height'];
        empty($height) && $height = $this->get_value_nutickets_height();

        $style = $atts['style'];
        empty($style) && $style = $this->get_value_nutickets_style();

        $autoscroll = $atts['autoscroll'];
        empty($autoscroll) && $autoscroll = $this->nutickets_options['nutickets_autoscroll'];
        $autoscroll = $this->nutickets_get_validated_value('autoscroll', $autoscroll);
        if ($autoscroll === 'false' || $autoscroll == 0 || $autoscroll == '0') {
            $autoscroll = false;
        }

        $url = $atts['url'];
        empty($url) && $url = $this->get_value_nutickets_url($atts['type']);
        $url = $this->nutickets_format_url($url);
        if (!$url) return '';

        $domain = $this->nutickets_get_domain_from_url($url);

        $id = random_token(10);

        $return = '<iframe id="nutickets-frame-'.$id.'" class="nutickets-frame" width="'.$width.'"';
        $script = '';
        $http   = 'http://'.$domain;
        $https  = 'https://'.$domain;
        if ($height === false || $height == '') {
            $return .=' scrolling="no" height="1200px"';
            $script .= '<script type="text/javascript">var _nu_iframe=document.getElementById("nutickets-frame-'.$id.'");function setDialogSize(a){window.onresize&&window.onresize();a.origin.match(/^http(s)?:\/\/(.*).nutickets.(com|co.za)(\/)?$/i)!==null?_nu_iframe.style.height=a.data+"px":(_nu_iframe.style.height=Math.min(900,Math.max(getHeight(),450))+"px",_nu_iframe.style.overflowY="scroll",_nu_iframe.setAttribute("scrolling","yes"))}window.addEventListener("message",setDialogSize,!1);_nu_iframe.addEventListener("load",nuScrollToiFrame);';
            if ($autoscroll) {
                $script .= 'function nuScrollToiFrame(){var a=0,b=_nu_iframe;if(b.offsetParent){do a+=b.offsetTop;while(b=b.offsetParent)}else a=_nu_iframe.offsetTop;"undefined"==typeof jQuery?window.scrollTo(0,a):jQuery("html, body").animate({scrollTop:a},800)}';
            }
            $script .= 'function getHeight(){return window.innerHeight||document.documentElement&&document.documentElement.clientHeight||document.body.clientHeight};</script>';
        } else {
            $return .=' height="'.$height.'"';
        }
        $return .= ' frameborder="0" allowtransparency="yes" src="'.esc_url($url).'" style="'.$style.'"></iframe>'.$script;

        return $return;
    }

    function nutickets_embed_page_button_css() {
        wp_enqueue_style('nutickets_button_css', plugins_url('/css/editor-plugin.min.css', __FILE__));
    }

    function nutickets_embed_page_button() {
        global $typenow;
        // check user permissions
        if ( !current_user_can('edit_posts') && !current_user_can('edit_pages') ) {
            return;
        }
        
        // verify the post type
        if (!in_array($typenow, array( 'post', 'page' )))
            return;
        
        // check if WYSIWYG is enabled
        if ( get_user_option('rich_editing') == 'true') {
            add_filter('mce_buttons', array($this, 'nutickets_register_embed_nt_button'));
            add_filter('mce_external_plugins', array($this, 'nutickets_add_tinymce_plugin'));
        }
    }

    function nutickets_add_tinymce_plugin($plugin_array) {
        $plugin_array['nutickets_button'] = plugins_url( '/js/editor-plugin.min.js', __FILE__ ); // CHANGE THE BUTTON SCRIPT HERE
        return $plugin_array;
    }

    function nutickets_register_embed_nt_button($buttons) {
       array_push($buttons, "nutickets_button");
       return $buttons;
    }

    /**
    * receives Nutickets account data from connection request
    **/
    function nutickets_save_account() {
        // not validating the analytics_key for security.
        // analytics calls will just fail if it's invalid.
        //@todo If API key needed?
        if(isset($_POST) && !empty($_POST)) {
            $company_id         = $this->nutickets_get_validated_value('company_id', $_POST['company_id']);
            $companies          = $this->nutickets_get_validated_value('companies', $_POST['companies']);
            $nutickets_region   = $this->nutickets_get_validated_value('region', $_POST['region']);

            $this->nutickets_save_option('nutickets_region', $nutickets_region);
            $this->nutickets_save_option('nutickets_company_id', $company_id);
            $this->nutickets_save_option('nutickets_companies', json_encode($companies));
            $this->nutickets_save_option('nutickets_created', date('Y-m-d H:m:i'));
            $this->nutickets_save_option('nutickets_autoscroll', 1);

            // better than returning some ambiguous boolean type
            echo 'true';
            wp_die();
        }
        echo 'false';
        wp_die();
    }

    /**
    * Disconnects already connected Nutickets account and removes its data
    **/
    function nutickets_disconnect_account() {
        // not validating the analytics_key for security.
        // analytics calls will just fail if it's invalid.
        //@todo If API key needed?
        $this->nutickets_save_option('nutickets_company_id', false);
        $this->nutickets_save_option('nutickets_companies', false);
        $this->nutickets_save_option('nutickets_created', false);

        // better than returning some ambiguous boolean type
        echo 'true';
        wp_die();
    }

    /**
    * handles request from frontend to update a card setting
    **/
    function nutickets_ajax_update_option() {
        if(!isset($_POST) || empty($_POST)) {
          echo 'ajax-error';
          wp_die();
        }

        $key = $_POST['key'];
        $value = $_POST['value'];

        switch($key) {
            case 'nutickets_width':
            case 'nutickets_height':
                $this->nutickets_save_option($key, $this->handle_width_input($value));
                break;
            case 'nutickets_company_id':
            case 'nutickets_style':
            case 'nutickets_autoscroll':
                // access to the $_POST from the ajax call data object
                $this->nutickets_save_option($key, $this->nutickets_get_validated_value($key, $value));
        }

        wp_die();
    }

    /**
     * Load plugin translation
     */
    function i18n() {
        load_plugin_textdomain('nutickets', false, dirname(plugin_basename(__FILE__)) . '/lang/');
    }

    /**
     * Deactivation Hook
     **/
    function nutickets_deactivate() {
        delete_option('nutickets_settings');
    }


    /**
    * Warns user if setup is not complete
    **/
    function nutickets_notify_user_icon() {
        if( !empty($this->nutickets_options['key'])) {
            return;
        }

        global $menu;
        if ( !$this->valid_key() ) {
            foreach ( $menu as $key => $value ) {
                if ($menu[$key][2] == 'nutickets-events') {
                    // accesses the menu item html
                    $menu[$key][0] .= ' <span class="update-plugins count-1">'.
                      '<span class="plugin-count"'.
                      'title="'.esc_html__('Please log in to your Nutickets account to use this plugin', 'nutickets') . '">'.
                      '!</span></span>';
                    return;
                }
            }
        }
    }

    /**
     * Adds toplevel Nutickets settings page
     **/
    function nutickets_add_settings_page() {
        $icon = 'dashicons-admin-generic';
        if( version_compare( $GLOBALS['wp_version'], '4.1', '>' ) ) {
           $icon = 'none';
        }

        $this->nutickets_settings_page = add_menu_page('Nutickets Events', 'Nutickets Events', 'activate_plugins', 'nutickets-events', array(
                $this,
                'nutickets_settings_page'
            ), $icon);
    }


    /**
     * Enqueue styles/scripts for Nutickets page(s) only
     **/
    function nutickets_enqueue_admin() {
        $screen = get_current_screen();
        if ($screen->id == $this->nutickets_settings_page) {
            wp_enqueue_style('dashicons');
            wp_enqueue_style('nutickets_admin_styles', NUTICKETS_PLUGIN_URL . '/css/nutickets-admin.min.css');
        }
        return;
    }

    /**
    *  Localizes the configuration settings for the user, making NUTICKETS_CONFIG available
    *  prior to loading nutickets.js
    **/
    function nutickets_localize_config() {
        global $settings_map;

        $ajax_url = admin_url( 'admin-ajax.php', 'relative' );

        $current = array();
        foreach ($settings_map as $setting => $api_param) {
            if(isset($this->nutickets_options[$setting])) {
                if( is_bool($this->nutickets_options[$setting])) {
                    $current[$setting] = $this->nutickets_options[$setting] ? '1' : '0';
                } else {
                    $current[$setting] = $this->nutickets_options[$setting];
                }
            }
        }

        $nutickets_config = array(
            'ajaxurl' => $ajax_url,
            'created' => $current['created'],
            'current' => $current,
        );

        wp_register_script('nutickets_admin_scripts', NUTICKETS_PLUGIN_URL . '/js/nutickets.min.js', array(
                'jquery'
            ), '1.0', true);
        wp_localize_script('nutickets_admin_scripts', 'NUTICKETS_CONFIG', $nutickets_config);
        wp_localize_script('nutickets_admin_scripts', 'objectL10n', array(
            'nu_wysiwyg_embed_button'           => esc_html__('Embed Nutickets Box Office', 'nutickets'),
            'nu_connect_error_text'             => esc_html__('There was a problem connecting to your Nutickets account. Please contact info@nutickets.com', 'nutickets'),
            'nu_connect_login_error_warning'    => esc_html__("Log in to your Nutickets account", 'nutickets'),
            'nu_connect_redirect_button_text'   => esc_html__("REDIRECTING TO NUTICKETS...", 'nutickets'),
            'nu_connect_connect_button_text'    => esc_html__("CONNECTING...", 'nutickets'),
            'nu_disconnect_warning'             => esc_html__('If you disconnect your account, all of your embedded Nutickets pages will be hidden to your customers on your site until you connect to a new account. Are you sure you want to disconnect?', 'nutickets'),
            'nu_disconnect_error_text'          => esc_html__('There was a problem disconnecting your Nutickets account. Please contact info@nutickets.com', 'nutickets'),
        ));
        wp_enqueue_script('nutickets_admin_scripts');
    }

    /**
    * update nutickets_options with a given key: value pair
    **/
    function nutickets_save_option($key, $value) {
       $this->nutickets_options[$key] = $value;
       update_option('nutickets_settings', $this->nutickets_options);
       $this->nutickets_options = get_option('nutickets_settings');
    }

    /**
    * removes a setting
    **/
    function nutickets_delete_option($key) {
        unset($this->nutickets_options[$key]);
        update_option('nutickets_settings', $this->nutickets_options);
        $this->nutickets_options = get_option('nutickets_settings');
    }


    /**
    * handles 'max width' input for card defaults
    * returns the string corresponding to the correct cards_width
    * card parameter
    **/
    function handle_width_input($input) {
        // width can be '%' or 'px'
        // first check if '100%',
        $percent = $this->int_before_substring($input, '%');
        if ($percent != 0 && $percent <= 100) {
            return $percent . '%';
        }

        // try for a value like 300px (platform can only handle >200px?)
        $pixels = $this->int_before_substring($input, 'px');
        if ($pixels > 0) {
            return max($pixels, 200);
        }

        // try solitary int value.
        $int = intval($input);
        if ($int > 0) {
            return max($int, 200);
        }

        return false;
    }


    /**
    * returns valid integer (not inclusive of 0, which indicates failure)
    * preceding a given token $substring.
    * given '100%', '%' returns 100
    * given 'asdf', '%', returns 0
    **/
    function int_before_substring($whole, $substring) {
        $pos = strpos($whole, $substring);
        if($pos != false) {
            $preceding = substr($whole, 0, $pos);
            return $percent = intval($preceding);
        }
    }

    /**
    * setup check
    **/
    function valid_key() {
        if (!$this->nutickets_options['nutickets_created']) {
          return false;
        }

        return true;
    }

    /////////////////////////// BEGIN TEMPLATE FUNCTIONS FOR FORM LOGIC


    /**
    * returns max width setting as a css value
    **/
    function get_value_nutickets_width() {
        $value = false;
        if(isset($this->nutickets_options['nutickets_width']) && strlen($this->nutickets_options['nutickets_width']) > 0) {
          $width = $this->nutickets_get_validated_value('width', $this->nutickets_options['nutickets_width']);
          return $width;
        }
    }

    /**
    * returns max height setting as a css value
    **/
    function get_value_nutickets_height() {
        $value = false;
        if(isset($this->nutickets_options['nutickets_height']) && strlen($this->nutickets_options['nutickets_height']) > 0) {
          $height = $this->nutickets_get_validated_value('height', $this->nutickets_options['nutickets_height']);
          return $height;
        }
    }

    /**
    * returns style setting as a html value attr
    **/
    function get_value_nutickets_style() {
        $value = $this->nutickets_get_validated_value('style', $this->nutickets_options['nutickets_style']);
        if($value === false) {
            $value = 'transition: all 0.3s ease-in-out;margin-left:auto;margin-right:auto;display:block;';
        }
        return $value;
    }

    function nutickets_get_validated_value($field = false, $value = false){
        if ($field === false || $value === false) { return false; }

        $field = strpos($field, 'nutickets_') === 0 ? substr($field, 10) : $field;
        $value = is_string($value) ? trim($value) : $value;

        if (($field == 'company') && (is_object($value) || is_array($value))) {
            foreach ($value as $key => $val) {
                if (is_string($key) && stripos($key, 'url') !== false && stripos($val, '://') === false) {
                    $val = 'http://' . $val;
                }
                if (is_object($value)) {
                    $value->{$key} = $this->nutickets_get_validated_value($key, $val);
                } else {
                    $value[$key] = $this->nutickets_get_validated_value($key, $val);
                }
            }
            return $value;
        }

        switch ($field):
            case 'company_id':
                return (intval($value) > 0) ? intval($value) : false;
                break;

            case 'companies':
                //If for some reason we would get a single companies data, not a list of companies, put them in an array
                if (!empty($value['company_id'])) {
                    $value = array($value);
                }
                if (!is_array($value)) { return false; }
                foreach ($value as $key => $val) {
                    $value[$key] = $this->nutickets_get_validated_value('company', $val);
                }
                return $value;
                break;

            case 'style':
                $value = sanitize_text_field(str_replace(array('\'', '"'), '', $value));
                return empty($value) ? false : $value;
                break;

            case 'company_name':
                return sanitize_text_field($value);
                break;

            case 'url':
            case 'ticketshop_url':
            case 'employees_url':
            case 'reps_url':
            case 'shop_url':
                return filter_var($value, FILTER_VALIDATE_URL);
                break;

            case 'width':
            case 'height':
                if (strpos($value, '%') !== false) {
                    return $value;
                } else if (intval($value) > 0) {
                    return intval($value) . "px";
                } else if ($value == 'auto') {
                    return 'auto';
                }
                return false;
                break;

            case 'autoscroll':
                return $value ? 1 : 0;
                break;

            case 'region':
                return in_array($value, array('com', 'za')) ? $value : 'com';
                break;

            case 'type':
                return in_array($value, array('ticketshop', 'employees', 'reps', 'shop')) ? $value : 'ticketshop';
                break;
        endswitch;

        return false;
    }

    /**
    * returns max_width setting as a html value attr
    **/
    function get_value_nutickets_url($type = false) {
        $company = $this->get_company();
        if (!$company) return false;
        $value = $company->ticketshop_url;
        switch ($type) {
            case 'employees':
                $value = $company->employees_url;
                break;
            case 'reps':
                $value = $company->reps_url;
                break;
            case 'shop':
                $value = $company->shop_url;
                break;
        }
        return $value;
    }


    /**
    * returns admin's URL
    **/
    function get_nutickets_admin_url() {
        if ($this->nutickets_options['nutickets_region'] == 'za') {
            return 'https://admin.nutickets.co.za';
        }
        //Fall back to .com on every other value
        return 'https://admin.nutickets.com';
    }

    function nutickets_format_url($url = false) {
        $url = $this->nutickets_get_validated_value('url', $url);
        if (!$url) { return false; }

        if (strpos($url, 'http') === 0) {
            $url = str_replace(
                array('http://', 'https://'), 
                array('//', '//'),
                $url
            );
        } elseif (strpos($url, 'www') === 0) {
            $url = '//'.$url;
        } elseif (strpos($url, '//') === 0) {
            //Good as it is!
        } else {
            $url = '//'.$url;
        }
        return $url;
    }

    function nutickets_get_domain_from_url($url = false) {
        if (!$url) { return false; }

        $url = parse_url('http:'.$url);

        return $url['host'];
    }

    /**
    * returns array of companies
    **/
    function get_companies() {
        return $this->nutickets_get_validated_value('companies', json_decode($this->nutickets_options['nutickets_companies']));
    }

    /**
    * returns current company
    **/
    function get_company() {
        $companies = $this->nutickets_get_validated_value('companies', json_decode($this->nutickets_options['nutickets_companies']));
        $id = $this->nutickets_get_validated_value('company_id', $this->nutickets_options['nutickets_company_id']);

        if ($companies !== false && $id !== false) {
            if (!$id && count($companies) == 1) {
                $id = $companies[0]['company_id'];
            }
            $this->nutickets_save_option('nutickets_company_id', $id);
            
            if ($id) {
                $selected = array_filter($companies, function($obj) use ($id) {
                    if (isset($obj->company_id) && $obj->company_id == $id) {
                        return true;
                    }
                    return false;
                });
                if ($selected && count($selected) == 1) {
                    return reset($selected);
                }
            }
        }
        return false;
    }


    /**
    * Setup content
    **/
    function get_setup_content($company, $companies) {
        ?><div class="nutickets-highlight">
            <h1><?php _e('Congratulations!', 'nutickets'); ?></h1>
            <h2>
                <?php printf( 
                    esc_html__("You've successfully installed your %s ticketshop to WordPress!", 'nutickets'), 
                    ('<a class="nutickets-link" target="_blank" href="http:'. $this->nutickets_format_url($this->get_value_nutickets_url()) .'">'.
                        stripslashes($company->company_name).
                    '</a>')); ?>
            </h2>
        </div>
        <div class="nutickets-content">
            <div class="nutickets-first-steps">
                <h2><?php _e('How to use it', 'nutickets'); ?></h2>
                <ol>
                    <li class="clearfix">
                        <img src="<?php echo NUTICKETS_PLUGIN_URL . "/img/howtostep1@2x.png" ?>">
                        <h3><?php _e('Add it to a page', 'nutickets'); ?></h3>
                        <p>
                            <?php _e("You can add your ticketshop to any page just with a click. Just add or edit a page, or a post, and click on the Nutickets button in the editor.", 'nutickets'); ?>
                        </p>
                    </li>
                    <li class="clearfix">
                        <img src="<?php echo NUTICKETS_PLUGIN_URL . "/img/howtostep2@2x.png" ?>">
                        <h3><?php _e('Any questions?', 'nutickets'); ?></h3>
                        <p>
                            <?php printf( 
                                esc_html__("For more information about the setup or installation please %scheck out our tutorial%s.", 'nutickets'), 
                                ('<a target="_blank" href="http://nutickets.com/wordpress">'),
                                '</a>'); ?>
                        </p>
                    </li>
                </ol>
            </div>
        </div>

        <div class="nutickets-content">
            <a href=".nutickets-advanced-settings-toggles" class="nutickets-advanced-settings-toggles nutickets-toggle-content nutickets-toggle-active" data-nutickets-target-toggle=""><?php _e('Show advanced settings...', 'nutickets'); ?></a>
            <a href=".nutickets-advanced-settings-toggles" class="nutickets-advanced-settings-toggles nutickets-toggle-content" data-nutickets-target-toggle=""><?php _e('Hide advanced settings...', 'nutickets'); ?></a>
        </div>

        <div class="nutickets-content">
            <div class="nutickets-advanced-settings-toggles nutickets-toggle-content" id="nutickets-tab-settings">
              <div class="nutickets-panel">
                  <h3 class="nutickets-panel-title">
                    <?php _e('Advanced Settings', 'nutickets'); ?>
                </h3>
                  <div class="nutickets-panel-status">
                    <p>
                        <?php _e('Changing these settings will modify all embedded Nutickets pages on your site. All settings are saved automatically.', 'nutickets');?> <span id="nutickets-settings-saved" class="nutickets-settings-saved nutickets-settings-notification"><?php _e('Settings updated successfully', 'nutickets'); ?></span>
                   </p>
                  </div>
                  <div class="nutickets-panel-body">
                    <div class="advanced-selections">
                      <ul>
                        <li>
                          <div>
                            <label><?php _e('Width', 'nutickets'); ?></label>
                            <input data-nutickets-input-autosave="" id='nutickets-width' type="text" name="nutickets_width" placeholder="<?php _e('Responsive if left blank', 'nutickets'); ?>"
                              value="<?php esc_html($this->get_value_nutickets_width()); ?>" />
                              <p><i><?php _e('Example: 400px or 80%.', 'nutickets'); ?></i></p>
                          </div>
                        </li>
                        <li>
                          <div>
                            <label><?php _e('Height', 'nutickets'); ?></label>
                            <input data-nutickets-input-autosave="" id='nutickets-height' type="text" name="nutickets_height" placeholder="<?php _e('If left blank, height changes depending on the content.', 'nutickets'); ?>" 
                              value="<?php esc_html($this->get_value_nutickets_height()); ?>" />
                              <p><i><?php _e('Example: 400px or 80%. If left blank height changes depending on content.', 'nutickets'); ?></i></p>
                          </div>
                        </li>
                        <li>
                          <div>
                            <label>
                                <input data-nutickets-checkbox-autosave="" value="1" id='nutickets-nutickets_autoscroll' type="checkbox" name="nutickets_autoscroll" <?php $this->nutickets_get_validated_value('autoscroll', $this->nutickets_options['nutickets_autoscroll']) ? ' checked="checked"' : ''?> > 
                                <?php _e('Autoscroll', 'nutickets'); ?>
                            </label>
                              <p>
                                <i>
                                    <?php _e('If height is left blank the Nutickets block changes its height depending on the content.', 'nutickets'); ?><br>
                                    <?php _e('On page load, and navigating between pages, your site would autoscroll to the top of the Nutickets page for easier navigation for customers.', 'nutickets'); ?>
                                </i>
                            </p>
                          </div>
                        </li>
                        <li>
                          <div>
                            <label><?php _e('Frame CSS', 'nutickets'); ?></label>
                            <textarea data-nutickets-input-autosave="" id='nutickets-style' type="text" name="nutickets_style" style="height:107px;"><?php esc_textarea($this->get_value_nutickets_style()); ?></textarea>
                              <p><i><?php _e('Default value: transition: all 0.3s ease-in-out;margin-left:auto;margin-right:auto;display:block;', 'nutickets'); ?></i></p>
                          </div>
                        </li>
                        <?php if ($company): ?>
                            <li>
                              <div>
                                <label><?php _e('Change account:', 'nutickets'); ?></label>
                                <select name="nutickets_company_id"<?= (count($companies) == 1) ? ' readonly="readonly"' : ''?>  data-nutickets-input-autosave="">
                                <?php
                                    foreach ($companies as $item) {
                                        echo '<option value="'.$item->company_id.'"';
                                        echo ($item->company_id == $company->company_id) ? ' selected="selected"' : '';
                                        echo '>'.stripslashes($item->company_name).'</option>';
                                    }
                                ?>
                                </select><br>
                                <p><i><a href="#" data-disconnect-nutickets=""><?php _e('Disconnect Nutickets account', 'nutickets') ?></a></i></p>
                              </div>
                            </li>
                        <?php endif; ?>
                      </ul>
                    </div>
                  </div>
                </div>
            </div> <!-- END 'Options' Section -->
        </div><?php 
    }
    /////////////////////////// END TEMPLATE FUNCTIONS FOR FORM LOGIC

    /**
     * The Admin Page
     **/
    function nutickets_settings_page() {
        global $wpdb;
        ######## BEGIN FORM HTML #########
        ?>
          <div class="nutickets-wrap">
            <div class="nutickets-ui">
              <div class="nutickets-input-wrapper">
                <?php
                
                if( $this->valid_key() ) { ?>

                <form id="nutickets_key_form" method="POST" action="">
                    <div class="nutickets-ui-header-wrapper">
                      <div class="nutickets-ui-header">
                        <div class="nutickets-ui-logo"><?php
                          _e('Nutickets Events', 'nutickets');
                          ?></div>
                      </div>
                    </div>

                    <div class="nutickets_key_form nutickets-ui-key-form">
                        <div id="nutickets-tab-main">
                            <?php
                                $companies = $this->get_companies();
                                $company = $this->get_company();

                                if ($company) {
                                    echo $this->get_setup_content($company, $companies);
                                } elseif ((count($companies) == 1) || count($companies) == 0) {
                                    ?><div class="nutickets-content">
                                        <div class="nutickets-panel">
                                            <h3 class="nutickets-panel-title"><?php _e('Setup incomplete', 'nutickets'); ?></h3>
                                            <div class="nutickets-panel-body">
                                                <span class="nutickets-settings-notification nutickets-settings-error">
                                                    <?php _e('No companies found!', 'nutickets'); ?> <a href="#" data-disconnect-nutickets="">
                                                    <?php _e('Please reconnect your Nutickets account', 'nutickets'); ?></a>
                                                </span>
                                                <br><br><?php _e('If the problem persists please send an email to <a href="mailto:info@nutickets.com">info@nutickets.com</a>', 'nutickets'); ?>
                                            </div>
                                        </div>
                                    </div><?php 
                                } elseif (count($companies) > 1) {
                                    ?><div class="nutickets-content">
                                        <div class="nutickets-panel">
                                            <h3 class="nutickets-panel-title"><?php _e('Choose your company', 'nutickets'); ?></h3>
                                            <div class="nutickets-panel-body">
                                                <ul>
                                                    <li>
                                                        <label>
                                                            <?php _e('Please select the company which you\'ll want to embed in your site:', 'nutickets'); ?>
                                                        </label>
                                                        <select name="nutickets_company_id" data-nutickets-input-autosave="">
                                                            <option value=""></option>
                                                        <?php
                                                            foreach ($companies as $item) {
                                                                echo '<option value="'.$item->company_id.'">'.stripslashes($item->company_name).'</option>';
                                                            }
                                                        ?>
                                                        </select>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div><?php
                                }
                            ?>
                        </div>
                    </div>
                  </form>
                <?php  // ELSE: Key is not entered
              } else {  ?>
                  <!-- MODAL FOR NEW ACCOUNTS -->
                <div class="nutickets-ui">
                <div class="nutickets-ui-header-wrapper">
                  <div class="nutickets-ui-header">
                    <div class="nutickets-ui-logo"><?php
                      _e('Nutickets Events', 'nutickets');
                      ?></div>
                  </div>
                </div>
                  <div class="nutickets-ui-key-wrap nutickets-new-user-modal">
                    <div class="nutickets_key_form nutickets-ui-key-form">
                        <div class="nutickets-content">
                          <div class="welcome-page-body">
                            <!-- HERO TEXT -->
                            <h1><?php _e('Follow the instructions to set up the plugin', 'nutickets'); ?></h1>
                            <h2 class="nutickets-subtitle">
                                <?php _e("The Nutickets Events Plugin allows Nutickets' clients to embed your ticketshop and other Nutickets enabled pages to your website seamlessly", 'nutickets'); ?>
                            </h2>

                            <section class="nutickets-region-selector">
                                <label><?php _e('Select your login website', 'nutickets'); ?></label>
                                <select data-change-region="">
                                    <option selected="selected"></option>
                                    <option value="com">
                                        <?php _e('Nutickets.com', 'nutickets'); ?>
                                    </option>
                                    <option value="za">
                                        <?php _e('Nutickets.co.za', 'nutickets'); ?>
                                    </option>
                                </select>
                            </section>

                            <section id="nutickets-connect-account">
                              <!-- Create a nutickets account button -->
                            <p>
                                <?php _e("Already have a Nutickets account?", "nutickets"); ?>
                            </p>
                              <button id="connect-button" class="nutickets-button nutickets-button-long" data-button-text="<?php _e("LOG IN WITH YOUR %NUTICKETS% ACCOUNT", "nutickets"); ?>">
                                <div class="inner-connect-button">
                                  <span class="inner-button-span">
                                    <img id="connect-btn-img" src=<?php echo NUTICKETS_PLUGIN_URL . "/img/nu_logo@2x.png" ?> height="60">
                                  </span>
                                  <span class="inner-button-span" data-button-text-target="">
                                    <?php _e("LOG IN WITH YOUR %NUTICKETS% ACCOUNT", "nutickets"); ?>
                                  </span>
                                </div>
                              </button>
                              <div class="nutickets-create-account-btn-wrap">
                                <p>
                                    <?php _e("Don't Have An Account?", "nutickets"); ?> <a id='create-account-btn' href="http://www.nutickets.com/get-started/" class="" target="_blank">
                                        <?php _e('Get started here', 'nutickets')?>
                                    </a>
                                </p>
                              </div>
                            </section>

                            <section>
                              <div id="nutickets-connect-failed-refresh">
                              <p> <?php _e("You may need to refresh the page after connecting", "nutickets"); ?></p>
                              </div>
                            </section>
                           </div>
                        </div>
                     </div>
                   </div>
                </div>
              <?php } // END if/else for new/existing account
              ?>
                <div id="footer">
                  <footer class="nutickets-footer">
                    &copy; <?php echo date('Y') . ' '; _e('All Rights Reserved', 'nutickets'); ?>, Nuweb Systems Ltd.
                  </footer>
                </div>
            </div>
        </div>
    </div><?php
    } // END settings page function
} // END WP_NuticketsEvents class

function random_token($length = 32){
    if (!isset($length) || intval($length) <= 8) {
        $length = 32;
    }
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length));
    }
    if (function_exists('mcrypt_create_iv')) {
        return bin2hex(mcrypt_create_iv($length, MCRYPT_DEV_URANDOM));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($length));
    }
}

//Instantiate a Global WP_NutiketsEvents
$WP_NuticketsEvents = new WP_NuticketsEvents();
