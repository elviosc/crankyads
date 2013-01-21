<?php

if(!defined("CRANKY_ADS_SETTINGS_DEFINED"))
{

    // The CrankyAds server domain name
    // Note: This contains NO scheme prefix (http:// https://) or trailing /
    define("CRANKY_ADS_DOMAIN", "www.crankyads.com");

    // The current version of the plugin we're running
    define("CRANKY_ADS_PLUGIN_VERSION", "1.9.3.1");// <<<< NOTE: Update this in CrankyAdsPlugin.php as well

    // Whether the plugin should run in DEBUG mode. 
    // Note: This outputs additional error information but has negative effects in production environments. 
    //       DO NOT RUN DEBUG in a production environment unless you know exactly what you're doing 
    //       (i.e. you're part of the CrankyAds development team and are debugging this plugin)
    define("CRANKY_ADS_DEBUG", false);

    // Whether to force PHP to report all errors
    // Note: This includes any and all errors in other plugins as well, hence this is useful only in development
    //       environments running only the CrankyAds plugin (in order to trap all CrankyAds plugin errors).
    //       This should be disabled in all production environments and when debugging errors involving other
    //       plugins (which typically generate many NOTICES).
    define("CRANKY_ADS_FORCE_PHP_ERROR_REPORTING_ALL", CRANKY_ADS_DEBUG);

    // Whether to forcefully disable all caching
    // Note: If set to true the blog running this plugin will incur performance penalties
    define("CRANKY_ADS_DISABLE_CACHE", CRANKY_ADS_DEBUG);

    // Whether to forcefully disable asynchronous loading
    // Note: If set to true then the blog will ALWAYS pause until all content is retrieved from the server
    define("CRANKY_ADS_DISABLE_ASYNC_LOADING", CRANKY_ADS_DEBUG);

    // Whether to disable error output suppression in order to debug regions of code that need to have this output disabled.
    // For instance, AJAX functions need error output disabled since error messages interfer with the AJAX response, 
    // particularly if AJAX is sending header data which is not possible after an error message is written to the response.
    define("CRANKY_ADS_DISABLE_ERROR_SUPPRESSION", false);

    // The path to the CrankyAds plugin directory WITHOUT a trailing / relative to the root Wordpress plugins directory
    // Note: To get the absolute url use: plugins_url(CRANKY_ADS_PLUGIN_DIRECTORY_RELATIVE);
    define("CRANKY_ADS_PLUGIN_DIRECTORY_RELATIVE",plugin_basename(dirname(dirname(__FILE__))));

    // The path to the CrankyAds plugin file relative to the root Wordpress plugins directory
    // Note: To get the absolute url use: plugins_url(CRANKY_ADS_PLUGIN_FILE_RELATIVE);
    define("CRANKY_ADS_PLUGIN_FILE_RELATIVE",CRANKY_ADS_PLUGIN_DIRECTORY_RELATIVE."/CrankyAdsPlugin.php");

    // The relative URL of the cranky ads settings page. To get the full url use admin_url(CRANKY_ADS_SETTINGS_PAGE_URL_RELATIVE)
    define("CRANKY_ADS_SETTINGS_PAGE_URL_RELATIVE", "admin.php?page=".urlencode(CRANKY_ADS_PLUGIN_FILE_RELATIVE));

    // CrankyAds version of the PHP_VERSION_ID constant (PHP_VERSION_ID is only defined in PHP 5.2.7)
    // Example: CRANKY_ADS_PHP_VERSION_ID for PHP 5.2.7 = 50207
    $phpVersion = explode('.', PHP_VERSION);
    define("CRANKY_ADS_PHP_VERSION_ID", ($phpVersion[0] * 10000 + $phpVersion[1] * 100 + $phpVersion[2]));


    define("CRANKY_ADS_SETTINGS_DEFINED", "1"); // Settings defined catch


    // ** Setup Code
    if(CRANKY_ADS_FORCE_PHP_ERROR_REPORTING_ALL)
    {
        error_reporting(E_ALL);
    }
}

?>