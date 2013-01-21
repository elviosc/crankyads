<?php
/*
Plugin Name: CrankyAds
Plugin URI: http://www.crankyads.com
Description: CrankyAds allows you to sell advertising space on your blog quickly and easily. 
Version: 1.9.3.1
Author: CrankyAds
Author URI: http://www.crankyads.com
*/

// TODO (791): We will want to host our plugin on http://wordpress.org/extend/plugins/ as described in http://codex.wordpress.org/Writing_a_Plugin and http://wordpress.org/extend/plugins/about/readme.txt 
 
/* ========================================================================================================== */

include_once dirname(__FILE__).'/includes/settings.php';
include_once dirname(__FILE__).'/includes/Proxy.php';
include_once dirname(__FILE__).'/includes/ContentController.php';
include_once dirname(__FILE__).'/includes/ZoneWidget.php';
include_once dirname(__FILE__).'/includes/DataContext.php';

/// <summary>Singleton instance of the CrankyAds plugin class</summary>
global $CrankyAdsPlugin;

/* ========================================================================================================== */

if (!class_exists("CrankyAdsPlugin"))
{

    // ==========================================================================================================
    //                                             Wordpress PHP Functions
    //                      (Use these to work with CrankyAds anywhere in your Wordpress PHP code)
    // ==========================================================================================================

    /// <summary>
    /// (PHP Helper Function) Serves ads for the specified CrankyAds Zone
    /// </summary>
    /// <remarks>This is a PHP helper function that lets users manually add a CrankyAdsZone anywhere in their PHP code without having to use Widgets</remarks>
    function CrankyAdsZone( $intZoneId )
    {
        global $CrankyAdsPlugin;
        $CrankyAdsPlugin->ContentController->ServeAdZoneAds($intZoneId);
    }






    // ==========================================================================================================
    //                                            CrankyAds Plugin Class
    // ==========================================================================================================

    /// <summary>
    /// The main CrankyAds Plugin class
    /// </summary>
    /// <remarks>
    /// . This class plugs the CrankyAds plugin functionality INTO Wordpress
    /// </remarks>
    class CrankyAdsPlugin
    {
        var $WP_SHORT_CODE_ADVERTISE_HERE = "CrankyAdsAdvertiseHere";

        /// <summary>Core plugin components</summary>
        var $Proxy;
        var $ContentController;
        var $WidgetFactory;
        var $DataContext;
        var $Cache;

        /// <summary>Content to be output at the footer of the page (if any)</summary>
        var $FooterContent = false;

        /// <summary>
        /// Constructor - initialize the Cranky Ads Plugin and hook it up to Wordpress
        /// </summary>
        function CrankyAdsPlugin()
        {    
            // ** Setup the plugin
            $this->DataContext = new CrankyAdsDataContext();
            $this->Cache = new CrankyAdsCache($this->DataContext);
            $this->Proxy = new CrankyAdsProxy( $this->DataContext, $this->Cache );
            $this->ContentController = new CrankyAdsContentController( $this->DataContext, $this->Proxy, $this->Cache, $this );
            $this->WidgetFactory = new CrankyAdsZoneWidgetFactory();
            $this->DataContext->Init($this->Proxy);

            if(function_exists('add_action')) // Wordpress is loaded
            {
                // Register Wordpress actions / hooks
                add_action('admin_menu', array(&$this, 'OnAddAdminMenuItem'));                  // Register our own Admin Menu
                add_filter('plugin_action_links', array(&$this, 'OnAddPluginLinks'), 10, 2);    // Register our own Plugin Links
                add_action('widgets_init', array(&$this, 'OnRegisterWidgets') );                // Register our Widgets
                add_action('init', array(&$this, 'OnRegisterShortCodes') );                     // Register short codes
                add_action('wp_enqueue_scripts', array(&$this, 'OnRegisterGlobalHead') );       // Register short codes

                register_activation_hook( __FILE__, array(&$this, 'OnPluginActivated') );       // Register a hook to let us know when this plugin is activated
                register_deactivation_hook( __FILE__, array(&$this, 'OnPluginDeactivated') );   // Register a hook to let us know when this plugin is deactivated
                
                // Register AJAX callbacks where we have control over the entire HTTP Response (for both admin and non-admin calls)
                add_action('wp_ajax_crankyads_pushnotification', array(&$this, 'PushNotification') );                      // Handle PUSH CrankyAds notification messages
                add_action('wp_ajax_nopriv_crankyads_pushnotification', array(&$this, 'PushNotification') );

                add_action('wp_ajax_crankyads_servecontent', array(&$this, 'ServeRemoteContent') );                         // Serve remote server content (used by HttpResponse.ReplaceContentUrlPlaceholders(..))
                add_action('wp_ajax_nopriv_crankyads_servecontent', array(&$this, 'ServeRemoteContent') );

                add_action('wp_ajax_crankyads_serveadzoneasync', array(&$this, 'ServeAdZoneAsync') );                       // Serve the Ad Zone HTML asynchronously
                add_action('wp_ajax_nopriv_crankyads_serveadzoneasync', array(&$this, 'ServeAdZoneAsync') );                // ** NOTE **:  If changing these lines remember to also update ContentController.ServeAdZoneAds(..)
                add_action('wp_ajax_crankyads_serveadvertisehereasync', array(&$this, 'ServeAdvertiseHereAsync') );         // Serve the Advertise Here HTML asynchronously
                add_action('wp_ajax_nopriv_crankyads_serveadvertisehereasync', array(&$this, 'ServeAdvertiseHereAsync') );  // ** NOTE **:  If changing these lines remember to also update ContentController.ServeAdvertiseHere(..)
                add_action('wp_ajax_crankyads_serveheadasync', array(&$this, 'ServeHeadAsync') );                           // Serve the <head> css block asynchronously
                add_action('wp_ajax_nopriv_crankyads_serveheadasync', array(&$this, 'ServeHeadAsync') );                    // ** NOTE **:  If changing these lines remember to also update ContentController.ServeHead(..)

                add_action('wp_ajax_crankyads_checkpluginupgrade', array(&$this, 'CheckPluginUpgrade') );                   // Perform a call to the server to check whether a plugin upgrade is required (because a new version is available)
                add_action('wp_ajax_nopriv_crankyads_checkpluginupgrade', array(&$this, 'CheckPluginUpgrade') );

                // Register a hook to output any footer content
                add_action('wp_footer', array(&$this, 'ServeFooter') );

                // Initialize the remaining plugin settings (after Wordpress is loaded)
                add_action('init', array(&$this, 'OnCrankyAdsPluginInit') );
            }
        }

        /// <summary>
        /// This is an extension of the plugin constructor, however, unlike the constructor this code is executed after the rest of Wordpress has fully loaded
        /// </summary>
        function OnCrankyAdsPluginInit()
        {
            // Note: At this point we can call init on any classes that need to perform initialization after Wordpress is fully loaded.
            //       But make sure to do that after the Installation section otherwise they might not yet be configured.

            // ** Installation
            // . This is the one time initialization we have to perform whenever the plugin is installed or upgraded
            // . The check below is a 'just in case' check since the installation should have occurred as part of the OnPluginActivated() call
            $dbVersion = $this->DataContext->GetInstalledPluginVersion();

            if($dbVersion !== CRANKY_ADS_PLUGIN_VERSION )
            {
                $this->InstallOrUpgrade($dbVersion);
            }

            // ** Init all required classes
            $this->Cache->Init();

            // ** Check for new plugin version (asynchronously)
            $pluginUpgradeCheck = $this->ContentController->ServePluginUpgradeCheck(false, true, false);
            if($pluginUpgradeCheck !== false)
                $this->EnqueueFooterContent($pluginUpgradeCheck);
        }

        /// <summary>
        /// Called when the plugin is activated
        /// </summary>
        function OnPluginActivated()
        {
            // ** Install / Upgrade the plugin
            $dbVersion = $this->DataContext->GetInstalledPluginVersion();
            if($dbVersion !== CRANKY_ADS_PLUGIN_VERSION )
            {
                $this->InstallOrUpgrade($dbVersion);
            }

            // ** Publish the Advertise Here page
            // TODO: Should we check / leave this until the blog is registered?
            $advertiseHerePage = array(
                'ID' => $this->DataContext->GetAdvertiseHerePageId(),
                'post_status' => 'publish',
            ); 

            if( $advertiseHerePage['ID'] !== false && strlen($advertiseHerePage['ID']) > 0 )
            {
                wp_update_post( $advertiseHerePage );
            }
        }

        /// <summary>
        /// Called when the plugin is deactivated
        /// </summary>
        function OnPluginDeactivated()
        {
            // ** Revert the Advertise Here page to draft
            $advertiseHerePage = array(
                'ID' => $this->DataContext->GetAdvertiseHerePageId(),
                'post_status' => 'draft',
            ); 

            if( $advertiseHerePage['ID'] !== false && strlen($advertiseHerePage['ID']) > 0 )
            {
                wp_update_post( $advertiseHerePage );
            }
        }

        /// <summary>
        /// Called when the plugin is uninstalled (deleted)
        /// </summary>
        /// <remarks>This is called explicitly by Uninstall.php</remarks>
        function OnPluginUninstalled()
        {
            $this->Uninstall(); // Note: The Wordpress auto-update function does not call OnPluginUninstalled() so no settings will be lost there.
                                //       This is only called when a user explicitly hits Delete on the Wordpress Plugins Page, in which case we DO want to delete all the settings.
            //exit(); // <- DEBUG: This ensure we don't actually proceed with the delete, which is useful when we're developing the plugin
        }

        /// <summary>
        /// Setup the CranyAds Admin Menu
        /// </summary>
        function OnAddAdminMenuItem()
        {
            $settingsPage = add_options_page('CrankyAds Options', 'CrankyAds', 'manage_options', __FILE__, array(&$this->ContentController, 'ServeSettingsPage'));
            //add_action( "admin_head-$settingsPage", array(&$this, 'OnAdminMenuHead') ); // The admin_head action doesn't allow us to enqueue scripts or styles
            add_action( "admin_print_styles-$settingsPage", array(&$this, 'OnAdminMenuHead') ); 
            add_action( "admin_footer-$settingsPage", array(&$this, 'ServeFooter') );

            $settingsPageMenu = add_menu_page('CrankyAds Settings', 'CrankyAds', 'manage_options', __FILE__, array(&$this->ContentController, 'ServeSettingsPage'), plugins_url('images/menuicon.png',__FILE__));
            add_action( "admin_print_styles-$settingsPageMenu", array(&$this, 'OnAdminMenuHead') ); 
            add_action( "admin_footer-$settingsPageMenu", array(&$this, 'ServeFooter') );
        }

        /// <summary>
        /// Enqueue the admin menu CSS
        /// </summary>
        function OnAdminMenuHead()
        {
            $this->OnRegisterGlobalHead();

            $bodyContent = false;
            $this->ContentController->ServeHead(true,false,true,true,false,$bodyContent);

            if( $bodyContent !== false )
                $this->EnqueueFooterContent($bodyContent);
        }

        /// <summary>
        /// Setup the Wordpress > Plugin Links for the CranyAds Plugin
        /// </summary>
        function OnAddPluginLinks($links, $file)
        {
            // Add a link for CrankyAds only
            if ( stripos( $file, basename(__FILE__) ) !== false )
		    {
			    $settings_link  = '<a href="'.admin_url(CRANKY_ADS_SETTINGS_PAGE_URL_RELATIVE).'"';
                if( $this->DataContext->GetBlogGuid() === false )
                    $settings_link .= ' style="color:orange;font-weight:bold">' . __('Start Here');
                else
                    $settings_link .= '>' . __('Settings');
                $settings_link .= '</a>';
			    array_unshift($links, $settings_link);
		    }
		    return $links;
        }

        /// <summary>
        /// Setup the CrankyAds Widgets
        /// </summary>
        function OnRegisterWidgets()
        {
            if( !$this->DataContext->IsSetup() )
                return;

            $allZones = $this->DataContext->GetAllZones();

            foreach ($allZones as $zone) 
            {
                $this->WidgetFactory->RegisterWidgetFor($zone->server_zone_id,$zone->name);
            }
        }

        /// <summary>
        /// Setup the CrankyAds Short Codes
        /// </summary>
        function OnRegisterShortCodes()
        {
            // Advertise Here
            add_shortcode($this->WP_SHORT_CODE_ADVERTISE_HERE, array(&$this, 'ShortCodeAdvertiseHere') );

            // Enqueue css and script only if shortcodes are present
            add_action('wp_enqueue_scripts', array(&$this, 'OnEnqueueShortcodeHead') );
        }

        /// <summary>
        /// Enqueue the css and script header blocks if any shortcode is present
        /// </summary>
        function OnEnqueueShortcodeHead()
        {
            global $post;
            $bodyContent = false;

            if( isset($post) && isset($post->post_content) && 
                strpos($post->post_content, "[".$this->WP_SHORT_CODE_ADVERTISE_HERE."]") !== false )
            {
                $this->ContentController->ServeHead(true,true,true,true,false,$bodyContent);

                if( $bodyContent !== false )
                    $this->EnqueueFooterContent($bodyContent);
            }
        }

        /// <summary>
        /// Setup the CrankyAds static <head> files
        /// </summary>
        function OnRegisterGlobalHead()
        {
            // Enqueue the crankyads css
            wp_deregister_style( "crankyads-global-style" );
            wp_register_style(   "crankyads-global-style", plugins_url('images/crankyads-zones.css',__FILE__));
            wp_enqueue_style(    "crankyads-global-style" );
        }

        /// <summary>
        /// Handle push notifications from the server.
        /// </summary>
        /// <remarks>These notifications are used primarily to update plugin settings in line with changes to the server content.</remarks>
        function PushNotification()
        {
            // Don't let anyone mess with our output
            $errorReportingSave = error_reporting(0);
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);
                
            // ** Setup
            $allowedPushNotifications = array( "get-info", "clear-cache", "resync-zones" );

            // ** Get the parameters
            $isAuthenticated = isset($_GET['auth']) && strlen($_GET['auth']) > 0 && $_GET['auth'] === $this->DataContext->GetBlogGuid() && $this->DataContext->GetBlogGuid() !== false && strlen($this->DataContext->GetBlogGuid()) > 0;
            $notification = $_GET['notification'];
            $values = $_GET['values'];

            // * Further security checks
            //   We don't want all notifications open for push
            // TODO: We should uncomment the code below at a later stage when we don't need easy debug access to the settings.
            //       We are resonably safe in commenting out this code because it is quite secure (password protected) and 100% limited to the plugin and not the blog. 
            //if( !CRANKY_ADS_DEBUG ) 
            //{
                //if( isset($notification) )
                //{
                    //$checkNotification = strtolower($notification);
                    //$isAuthenticated = $isAuthenticated & in_array( $checkNotification, $allowedPushNotifications );
                //}
            //}

            // ** Handle the notification
            if( $isAuthenticated && isset( $notification ) && strlen($notification) > 0 )
            {
                // * Split $values
                if( isset( $values ) && strlen($values) > 0 )
                    $values = explode(";",$values);
                else
                    $values = array();

                // * Make the call
                $this->ContentController->HandleCrankyAdsNotification( $notification, $values, true );

            }
            else
            {
                echo("0");
            }

            error_reporting($errorReportingSave);


            die(); // We've completed successfully so Wordpress shouldn't output anything else
        }

        /// <summary>
        /// This function is used as the target for all proxy server content (via the Wordpress AJAX script).
        /// It serves it's content using ContentController
        /// </summary>
        function ServeRemoteContent()
        {
            // ** Serve the content
            $serverUrl = $_GET["serverurl"];
            if( isset($serverUrl) )
            {
                // Don't let anyone mess with our output
                $errorReportingSave = error_reporting(0);
                if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                    error_reporting($errorReportingSave);
                   
                // Note: 
                // . For security reasons this page will serve content from the server /Content directory or /Plugin directory ONLY where content has been marked as public.
                // |-> We don't want users accessing plugin settings without being admins
                // |-> We don't want malicious users using this page as a proxy to hit just any page on the server (and slowing down this blog)
                // |-> We can't limit this call to admins only because this page needs to serve images and other content to users of the blog.
                if( !$this->ContentController->ServeRemoteContent($serverUrl, CrankyAdsHelper::GetFullRequestUri("serverurl"),0) )
                {
                    echo "Error contacting the server"; // <- Serving at least something tends to keep colorbox happy
                }

                error_reporting($errorReportingSave);
            }
            else
            {
                echo "Invalid URL";
            }

            die(); // We've completed successfully so Wordpress shouldn't output anything else
        }

        /// <summary>
        /// Call the server to check whether a plugin upgrade is required (because a new version is available).
        /// TODO (791): Review whether we want to continue this once CrankyAds is part of the Wordpress plugin list. We likely do since CrankyAds does a plugin version check whenever someone accesses the Settings page.
        /// </summary>
        function CheckPluginUpgrade()
        {
            // Don't let anyone mess with our output
            $errorReportingSave = error_reporting(0);
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);
                   
            $this->ContentController->ServePluginUpgradeCheck(true,false,false);

            error_reporting($errorReportingSave);

            die(); // We've completed successfully so Wordpress shouldn't output anything else
        }

        /// <summary>
        /// This function is used to server the contents of an Ad Zone asynchronously (via Wordpress Ajax)
        /// </summary>
        /// <remarks>
        /// . This method serves the Ad Zone through ContentController->ServeAdZoneAds(..)
        /// </remarks>
        function ServeAdZoneAsync()
        {
            // ** Get the parameters

            // Zone Id
            $zoneId = $_GET["zoneId"];
            if( isset($zoneId) )
                $zoneId = intval($zoneId);
            else
                $zoneId = 0;

            // Permutation
            $permutation = $_GET["permutation"];
            if( isset($permutation) )
            {
                $permutation = intval($permutation);
                if( $permutation === 0 ) // Failure
                    $permutation = false;
            }

            // Suppress Output
            $suppressOutput = isset($_GET["suppressoutput"]) && $_GET["suppressoutput"]=="1";

            // ** Serve the zone
            if( $zoneId > 0 )
            {
                // Don't let anyone mess with our output
                $errorReportingSave = error_reporting(0);
                if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                    error_reporting($errorReportingSave);
                
                $isUserSensitive = false;
                if( CRANKY_ADS_DEBUG && isset($_GET['debug']) )
                {
                    $isUserSensitive = true;
                }
                
                   
                // Note: 
                // . There are no security issues here since this function will ONLY server AdZones which are inherently public content.
                if( !$this->ContentController->ServeAdZoneAds($zoneId,$isUserSensitive,$permutation,$suppressOutput) )
                {
                    echo "<!--Error contacting the Cranky Ads Server-->";
                }

                error_reporting($errorReportingSave);
            }
            else
            {
                echo "Invalid Zone Id";
            }

            die(); // We've completed successfully so Wordpress shouldn't output anything else
        }

        /// <summary>
        /// This function is used to serve the contents of the Advertise Here page asynchronously (via Wordpress Ajax)
        /// </summary>
        /// <remarks>
        /// . This method serves the Advertise Here page through ContentController->ServeAdvertiseHere(..)
        /// </remarks>
        function ServeAdvertiseHereAsync()
        {
            // Don't let anyone mess with our output
            $errorReportingSave = error_reporting(0);
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Setup
            $isUserSensitive = false;
            $instructions = isset($_POST['instructions']) ? $_POST['instructions'] : (isset($_GET['instructions']) ? $_GET['instructions'] : false);
            $suppressOutput = isset($_GET["suppressoutput"]) && $_GET["suppressoutput"]=="1";

            // Special case - check for PayPal response and add "thankyou" instruction
            if(isset($_POST['txn_type']) && isset($_POST['auth']) || isset($_GET['txn_type']) && isset($_GET['auth']) )
            {
                if( $instructions === false )
                    $instructions = "";
                else
                    $instructions .= ";";

                $instructions .= "thankyou";
            }
                
            // ** Serve the Advertise Here content
            if( !$this->ContentController->ServeAdvertiseHere($isUserSensitive, $instructions, $suppressOutput) )
            {
                echo "<!--Error contacting the Cranky Ads Server-->";
            }

            error_reporting($errorReportingSave);


            die(); // We've completed successfully so Wordpress shouldn't output anything else
        }

        /// <summary>
        /// This function is used to serve the <head> block asynchronously (via Wordpress Ajax)
        /// </summary>
        /// <remarks>
        /// . This method serves the <head> block through ContentController->ServeHead(..)
        /// </remarks>
        function ServeHeadAsync()
        {
            // Don't let anyone mess with our output
            $errorReportingSave = error_reporting(0);
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);
                
            // Suppress Output
            $suppressOutput = $_GET["suppressoutput"]=="1";
                
            if( !$this->ContentController->ServeHead(false,false,false,false,$suppressOutput) )
            {
                echo "<!--Error contacting the Cranky Ads Server-->";
            }

            error_reporting($errorReportingSave);


            die(); // We've completed successfully so Wordpress shouldn't output anything else
        }

        /// <summary>
        /// Serve any footer content
        /// </summary>
        /// <remarks>
        /// . To serve additional footer content use $this->EnqueueFooterContent(..)
        /// </remarks>
        function ServeFooter()
        {
            if( $this->FooterContent !== false )
                echo $this->FooterContent;
        }

        /// <summary>
        /// Returns the Cranky Ads Advertise Here HTML as part of a ShortCode request
        /// </summary>
        function ShortCodeAdvertiseHere( $dicAttrs, $content )
        {
            // TODO: We can add attributes later to determine how the Advertise Here page should be displayed

            // ** Setup
            $instructions = isset($_POST['instructions']) ? $_POST['instructions'] : (isset($_GET['instructions']) ? $_GET['instructions'] : false);

            // Special case - check for PayPal response and add "thankyou" instruction
            if(isset($_POST['txn_type']) && isset($_POST['auth']) || isset($_GET['txn_type']) && isset($_GET['auth']) )
            {
                if( $instructions === false )
                    $instructions = "";
                else
                    $instructions .= ";";

                $instructions .= "thankyou";
            }

            // ** Serve the Advertise Here content
            return $this->ContentController->ServeAdvertiseHere(true,$instructions,true);
        }

        /// <summary>
        /// Performs a one time wordpress setup to run the plugin
        /// </summary>
        /// <remarks>
        /// . Install should be run once when the plugin is first activated and once every time a new version is installed.
        /// . Install handles cases where a previous installation may only have been partially complete or not performed at all
        /// </remarks>
        function InstallOrUpgrade( $strPreviousVersion = false )
        {
            $vMajor = 0;
            $vMinor = 0;
            $vRevision = 0;

            if( $strPreviousVersion !== false )
            {
                $version = explode(".",$strPreviousVersion);

                $vMajor = intval($version[0]);
                $vMinor = intval($version[1]);
                $vRevision = intval($version[2]);
            }

            // ** Perform install on all relevant subcomponents
            $this->DataContext->InstallOrUpgrade($vMajor, $vMinor, $vRevision);
            $this->Cache->InstallOrUpgrade($vMajor, $vMinor, $vRevision);

            // ** Perform Plugin Installation

            // * 1.1.0
            if( $vMajor < 1 || $vMajor == 1 && $vMinor < 1 ) 
            {
                // Create the Advertise Here page
                // Note: for reference on parameters see http://codex.wordpress.org/Function_Reference/wp_insert_post
                $advertiseInsertError = false;
                $advertiseHerePage = array(
                  'comment_status' => 'closed',
                  'ping_status' => 'closed',
                  'post_content' => '['.$this->WP_SHORT_CODE_ADVERTISE_HERE.']',
                  'post_name' => 'Advertise Here',
                  'post_status' => 'publish',
                  'post_title' => 'Advertise Here',
                  'post_type' => 'page'
                );  

                $advertisePageId = wp_insert_post( $advertiseHerePage, $advertiseInsertError );
                if($advertiseInsertError === false)
                    $this->DataContext->SetAdvertiseHerePageId($advertisePageId);
                else
                    $this->DataContext->SetAdvertiseHerePageId(false);
            }

            // ** Save the plugin version (for future upgrades)
            $this->DataContext->SetInstalledPluginVersion(CRANKY_ADS_PLUGIN_VERSION);

        }

        /// <summary>
        /// Clears all the one time Wordpress setup performed by the plugin during InstallOrUpgrade()
        /// </summary>
        /// <remarks>
        /// . Uninstalling is performed in the reverse order to the installation 
        /// . Uninstalling handles cases where the installation may only be partially complete or not performed at all
        /// </remarks>
        function Uninstall()
        {
            // ** Perform Plugin Un-Installation

            // * 1.1.0
            {
                // Disable the Advertise Here page
                // Note: for reference on parameters see http://codex.wordpress.org/Function_Reference/wp_insert_post
                $advertiseHerePage = array(
                  'ID' => $this->DataContext->GetAdvertiseHerePageId(),
                  'post_status' => 'draft',
                ); 

                if( $advertiseHerePage['ID'] !== false && strlen($advertiseHerePage['ID']) > 0 )
                {
                    //wp_update_post( $advertiseHerePage );
                    wp_delete_post( $advertiseHerePage['ID'] ); // This will move the page into trash allowing users to recover it if desired
                }

                // Clear the advertise here id
                $this->DataContext->SetAdvertiseHerePageId(false);

            }

            // ** Perform Un-Installation on all relevant subcomponents (in reverse installation order)
            $this->Cache->Uninstall();
            $this->DataContext->Uninstall();

        }

        /// <summary>
        /// Enqueue additional content that will be output as part of the wp_footer action
        /// </summary>
        function EnqueueFooterContent( $strContent )
        {
            if( $this->FooterContent === false )
                $this->FooterContent = "";

            $this->FooterContent .= $strContent;
        }
        
    } // end class



    
    // ** Create a new Cranky Ads Plugin instance
    $CrankyAdsPlugin = new CrankyAdsPlugin();


} // end if


    
?>