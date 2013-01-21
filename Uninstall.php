<?php

if( defined("WP_UNINSTALL_PLUGIN") )
{
    // Load the plugin
    include_once dirname(__FILE__).'/CrankyAdsPlugin.php';

    // Inform the plugin of the uninstall
    global $CrankyAdsPlugin;
    $CrankyAdsPlugin->OnPluginUninstalled();
}

?>