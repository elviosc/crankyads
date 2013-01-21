<?php

include_once dirname(__FILE__).'/settings.php';
include_once dirname(__FILE__).'/Enums.php';
include_once dirname(__FILE__).'/Proxy.php';
include_once dirname(__FILE__).'/Cache.php';
include_once dirname(__FILE__).'/DataContext.php';
include_once dirname(__FILE__).'/Helper.php';

if (!class_exists("CrankyAdsContentController"))
{
    /// <summary>
    /// Class used to control the serving of content for the entire CrankyAdsPlugin
    /// </summary>
    class CrankyAdsContentController
    {
        /// <summary>The cache timeout of the AdZone HTML (in seconds)</summary>
        /// <remarks>Within this period the ADs in a zone WILL NOT CHANGE</remarks>
        var $ADZONE_CACHE_TIMEOUT_IN_SECONDS = 900; // 15 minutes

        /// <summary>The number of copies of the AdZone HTML to cache simultaneously</summary>
        /// <remarks>This is used to cache a number of permutations of each zone</remarks>
        var $ADZONE_CACHE_NUMBER_OF_COPIES = 5;

        /// <summary>The option key used to record the last time the plugin upgrade check was served</summary>
        var $LAST_PLUGIN_UPGRADE_CHECK_TIMESTAMP_OPTION_KEY = "last_plugin_upgrade_check_timestamp";

        /// <summary>Instance of the proxy class used to communicate with the server</summary>
        var $Proxy = null;

        /// <summary>Instance of the DataContext used to persist and retrieve values</summary>
        var $DataContext = null;

        /// <summary>Instance of the Cache used to persist server content locally</summary>
        var $Cache = null;

        /// <summary>Instance of the CrankyAdsPlugin that we're a part of</summary>
        var $Plugin = null;

        /// <summary>
        /// Constructor - initialize the Cranky Ads Content Controller
        /// </summary>
        function CrankyAdsContentController( $dataContext, $proxy, $cache, $plugin )
        {
            $this->DataContext = $dataContext;
            $this->Proxy = $proxy;
            $this->Cache = $cache;
            $this->Plugin = $plugin;
        }
        
        // ==========================================================================================================
        //                                          Serve Content Methods
        // ==========================================================================================================

        /// <summary>
        /// Serve the settings page
        /// </summary>
        function ServeSettingsPage()
        {
            $remoteUrl = "";
            if(isset($_GET["serverurl"])) // TODO (792): This should be passed as a parameter via a method in CrankyAdsPlugin.php
                $remoteUrl = $_GET["serverurl"];

            if( strlen($remoteUrl) <= 0 )
                $remoteUrl = $this->Proxy->ToServerUrlFromActionUrl("Settings/Index");

            $pluginSettingsUrl = admin_url(CRANKY_ADS_SETTINGS_PAGE_URL_RELATIVE);

            $this->ServeRemotePage($remoteUrl,true,$pluginSettingsUrl, true, array(&$this, 'HandleCrankyAdsNotificationsFromProxy'), false );
        }

        /// <summary>
        /// Call the server to check whether a plugin upgrade is required (because a new version is available). 
        /// </summary>
        /// <remarks>
        /// If the minimum time between checks has not expired this method will simply return false
        /// . bIsAsynchronous - If true then the result is served directly, otherwise, an iframe is served to perform the check asynchronously
        /// . $bSuppressAndReturnOutput - If true then instead of serving the result directly to the response this method will instead return the result to the caller
        /// . $bForce - Whether to server the result regardless of whether the minimum time between checks has elapsed
        /// </remarks>
        function ServePluginUpgradeCheck($bIsAsynchronous, $bSuppressAndReturnOutput=true, $bForce=false)
        {
            // ** Check whether we even need to perform the upgrade check
            $performPluginUpgradeCheck = $bForce;
            $nowStr = gmdate("Y-m-d H:i:s") . " UTC";
            $now = strtotime( $nowStr );

            if( !$bForce )
            {
                $lastPluginVersionCheck = $this->DataContext->GetOptionAsTime( $this->LAST_PLUGIN_UPGRADE_CHECK_TIMESTAMP_OPTION_KEY );

                // Check whether the minimum time has elapsed
                if( $lastPluginVersionCheck !== false )
                {
                    $nextCheck = strtotime("+12 hours", $lastPluginVersionCheck );
                    $performPluginUpgradeCheck = ( $now > $nextCheck );
                }
                // We've never performed a check
                else
                {
                    $performPluginUpgradeCheck = true;
                }
            }

            if(!$performPluginUpgradeCheck)
                return false;
            
            // ** Serve the contents directly
            // The server will send back a notification to upgrade the plugin (if required)
            if($bIsAsynchronous)
            {
                // ** Setup
                $remoteUrl = $this->Proxy->ToServerUrlFromActionUrl("Data/CheckPluginUpgrade");

                // ** Perform the check with the server
                $this->DataContext->SetOption( $this->LAST_PLUGIN_UPGRADE_CHECK_TIMESTAMP_OPTION_KEY, $nowStr );
                $httpResponse = $this->Proxy->GetRemoteContent($remoteUrl,false,false,false,3,false,true);

                // Valid response
                if($httpResponse !== false && $httpResponse->HttpResponseCode === 200)
                {
                    // Handle any notifications
                    $this->HandleCrankyAdsNotificationsFromProxy($httpResponse);

                    // Output Result
                    $httpResponse->ReplaceAllContentPlaceholders();
                    if( !$bSuppressAndReturnOutput )
                    {
                        echo $httpResponse->Content;
                        return;
                    }
                    else
                    {
                        return $httpResponse->Content;
                    }

                }
                // Invalid Response
                else
                {
                    // ** Do nothing, we'll just perform the check next time around

                    // Debug output
                    if( CRANKY_ADS_DEBUG )
                    {
                        if( $httpResponse !== false )
                        {
                            echo("<div style='max-width:200px;max-height:500px;overflow:auto;border:dashed 2px red'><h2>DEBUG:ContentController.ServePluginUpgradeCheck(\$bIsAsynchronous = $bIsAsynchronous, \$bSuppressAndReturnOutput = $bSuppressAndReturnOutput, \$bForce = $bForce)</h2><br/>Error occurred - Http Response code is unexpected (".$httpResponse->HttpResponseCode.");<br/><br/><b>Content:</b><br/>".$httpResponse->Content."</div>");
                        }
                        else
                        {
                            echo("<div style='max-width:200px;max-height:500px;overflow:auto;border:dashed 2px red'<h2>DEBUG:ContentController.ServePluginUpgradeCheck(\$bIsAsynchronous = $bIsAsynchronous, \$bSuppressAndReturnOutput = $bSuppressAndReturnOutput, \$bForce = $bForce)</h2><br/>Error occurred - Http Response is empty</div>");
                        }
                    }
                }
            }
            // ** Serve the contents asynchronously
            else
            {
                $asyncCallbackUrlBase = "admin-ajax.php?action=crankyads_checkpluginupgrade";

                $asyncCallbackUrl = admin_url( $asyncCallbackUrlBase );
                return $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadsupdatecheck", CrankyAdsCopyAsyncContentEnum::DoNotCopy, false, true, $bSuppressAndReturnOutput );
            }

        }

        /// <summary>
        /// Serve the plugin <head> tag entries (css and scripts). This is only necessary when displaying Advertise Here and Settings pages
        /// </summary>
        /// <remarks>
        /// . This method returns true if the CSS <link> and <script> tags could be served and false if a failure occurred
        /// . $bUseEnqueue 
        ///   - If true then the files are served via Wordpress wp_enqueue_styles(..)/wp_enqueue_script(..) calls otherwise the files are served via a direct echo(..) call
        /// . $isLoadTimeSensitive
        ///   - Whether the call is load time sensitive (i.e. should the call return as fast as possible)
        ///   - If true then this method will only succeed if it can serve the <head> contents immediately. Specifically, this call will succeed only if the XML list of CSS and SCRIPT files to be loaded are in cache as well as the files themselves.
        ///     If any element is NOT in the cache then the method will fail and attempt to load the contents asynchronously via $strBodyContent.
        ///     If all elements ARE in the cache but at least one element has timed out then the method will attempt to refresh the cache asynchronously via $strBodyContent.
        ///   - If false then this method will serve all <head> content and fail only if there is a problem contacting the server. In this case the caller will be blocked until all communication with the server is complete.
        /// . $bRefreshTimedOutCacheAsync - If any of the cached items are timed out, should this method also serve an iframe to refresh the cache contents asynchronously (via $strBodyContent)
        /// . $bPreferCache - Only applicable if !$isLoadTimeSensitive. Should we prefer timed-out cache items over making a request to the server to retrieve fresh content?
        /// . $bSuppressOutput - Whether NOT to output the result of the call (this is useful when simply updating the cache via an asynchronous iFrame callback)
        /// . $strBodyContent - (Output) Additional content that should be output somewhere inside the <body> tag (required for asynchronous loading or cache updates)
        /// </remarks>
        function ServeHead( $bUseEnqueue=true, $isLoadTimeSensitive=true, $bRefreshTimedOutCacheAsync=true, $bPreferCache=false, $bSuppressOutput=false, &$strBodyContent=false)
        {
            // ** Setup
            $strBodyContent = false;
            $asyncCallbackUrlBase = "admin-ajax.php?action=crankyads_serveheadasync";

            $cacheBehaviour = CrankyAdsCacheBehaviourFlags::DefaultBehaviour;
            if( $isLoadTimeSensitive )
            {
                $cacheBehaviour = CrankyAdsCacheBehaviourFlags::DoNotMakeRequestToServer | CrankyAdsCacheBehaviourFlags::IgnoreCacheTimeout;
            }
            else
            {
                $cacheBehaviour = CrankyAdsCacheBehaviourFlags::UseTimedOutCacheAsFallbackOnServerError;

                if( $bPreferCache )
                    $cacheBehaviour = $cacheBehaviour | CrankyAdsCacheBehaviourFlags::IgnoreCacheTimeout;
            }

            // ** Special case
            // We're guaranteed to always need these.
            // This is especially true if we're serving <script>s and the headers are being loaded asynchronously
            if($bUseEnqueue)
            {

                // Always load v1.7.1 or greater of jQuery when dealing with CrankyAds content
                global $wp_scripts;
                if(!$wp_scripts)
                {
                    $wp_scripts = new WP_Scripts();
                    wp_default_scripts($wp_scripts); 
                }

	            if ( ( version_compare("1.7.1", $wp_scripts->registered["jquery"]->ver) == 1 ) && !is_admin() )
                {
	 	            wp_deregister_script('jquery'); 
	 	            wp_register_script('jquery', 'http://code.jquery.com/jquery-1.7.1.min.js', false, our_version);
                    $wp_scripts->registered[jquery]->ver = "1.7.1";
                }


                wp_enqueue_script("jquery");

            }

            // ** Get the list of CSS and SCRIPTS to serve (from the server)
            $cssData = $this->DataContext->Remote_GetHeadCssData($cacheBehaviour,true);
            $scriptData = false;
            if( $cssData )
                $scriptData = $this->DataContext->Remote_GetHeadScriptData($cacheBehaviour,true);

            // Failure
            if(!$cssData || !$scriptData)
            {
                // Load asynchronously
                if( $isLoadTimeSensitive )
                {
                    $asyncCallbackUrl = admin_url( $asyncCallbackUrlBase );
                    $strBodyContent = $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadshead", CrankyAdsCopyAsyncContentEnum::CopyToHead, false, true, true );
                }

                return false;
            }

            // ** Attempt to load all <head> items
            //
            // Either:
            //
            // . (if $isLoadTimeSensitive) To ensure they're available locally in the cache
            //   - This is necessary because if we serve a <head> css or script block to a file which is itself NOT in the cache then
            //     the page load will block while that file is being loaded via the Proxy remotely from the CrankyAds server. 
            //     We want to ensure that blog page refreshes are NEVER dependent on the CrankyAds server!
            //
            //   OR
            //
            // . (if !isLoadTimeSensitive) To load all the content AND
            //    . (!$bPreferCache) To refresh timed-out cache
            //    . ($bPreferCache)  To check whether the cache needs to be refreshed
            //
            // Note: 
            // $cacheBehaviour is the same here as it is for the overall css/script data, so:
            // If $isLoadTimeSensitive then no calls to the server will be made and only the existing cached values are checked.
            // If !$sUserSensitive then a call to the server will be made on timed out items to refresh the cache (unless $bPreferCache)
            //
            // TODO (793): I don't like that we're actually LOADING the files into memory here. Could we not just change this to CheckHeadFilesCache(.., $bRefreshTimedOutFiles = !$isLoadTimeSensitive ), then in CheckHeadData use $this->Cache->IsInCache(..)
            //       If we're afraid that the file won't be there we can just

            $isAvailable = true;
            $isTimedOut = false;

            $this->LoadHeadData( $cssData, $scriptData, $cacheBehaviour | CrankyAdsCacheBehaviourFlags::ReplaceContentUrlPlaceholdersOnServerResponse, $isAvailable, $isTimedOut);

            // Cannot load directly (not all content is in cache)
            if( $isLoadTimeSensitive && !$isAvailable )
            {
                $asyncCallbackUrl = admin_url( $asyncCallbackUrlBase );
                $strBodyContent = $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadshead", CrankyAdsCopyAsyncContentEnum::CopyToHead, false, true, true );
                return false;
            }

            // ** Output the <head> items
            if( !$bSuppressOutput )
            {
                // * Output the actual <head> items
                $this->OutputHead($bUseEnqueue,$cssData,$scriptData);

                // * Timed out - Output and additional entry to refresh the cache asynchronously
                if( $isTimedOut && $bRefreshTimedOutCacheAsync )
                {
                    $asyncCallbackUrl = admin_url( $asyncCallbackUrlBase . "&suppressoutput=1" );
                    $strBodyContent = $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadshead", CrankyAdsCopyAsyncContentEnum::DoNotCopy, false, true, true );
                }
            }

            return true;
        }

        /// <summary>
        /// Serve a series of Ads for an AdZone
        /// </summary>
        /// <remarks>
        /// $isUserSensitive 
        ///   . Whether this is a user sensitive request (i.e. the page contents will not display until this request is complete)
        ///   . If true and the contents are not immediately available then this method will serve an iFrame to asynchronously load the contents
        /// $intPermutation
        ///   . Which permutation of the Ad Zone to serve (since Ad Zones should change on each refresh we cache ADZONE_CACHE_NUMBER_OF_COPIES permutations)
        ///   . If false then a random permutation will be served
        /// $bSuppressOutput
        ///   . Do NOT output anything to the response.
        ///   . This is useful for asynchronous loads that are designed simply to refresh a timed out cache
        /// </remarks>
        function ServeAdZoneAds( $intZoneId, $isUserSensitive=true, $intPermutation=false, $bSuppressOutput=false )
        {
            // ** Setup
            $remoteUrl = $this->Proxy->ToServerUrlFromActionUrl("AdServer/Zone?zoneId=".$intZoneId);
            if( $intPermutation === false )
                $intPermutation = rand(1,$this->ADZONE_CACHE_NUMBER_OF_COPIES);
            $asyncCallbackUrlBase = "admin-ajax.php?action=crankyads_serveadzoneasync&zoneId=$intZoneId";
            $asyncCallbackUrlBaseWithPermutation = $asyncCallbackUrlBase."&permutation=$intPermutation";

            // ** Determine cache behaviour
            $cacheBehaviour = CrankyAdsCacheBehaviourFlags::DefaultBehaviour;

            // Synchronous - we only want cached copies, DO NOT call the main server (and risk blocking the calling request)
            if( $isUserSensitive )
            {
                $cacheBehaviour = CrankyAdsCacheBehaviourFlags::DoNotMakeRequestToServer | CrankyAdsCacheBehaviourFlags::IgnoreCacheTimeout;
            }
            // Asynchronous - we can call the server directly
            else
            {
                $cacheBehaviour = CrankyAdsCacheBehaviourFlags::UseTimedOutCacheAsFallbackOnServerError;
            }
            

            // ** Get the AdZone
            $httpResponse = $this->Proxy->GetRemoteContent($remoteUrl,false,false,$this->ADZONE_CACHE_TIMEOUT_IN_SECONDS,3,false,false,false,$cacheBehaviour,"AdZone-$intZoneId-$intPermutation://","zone");

            // Valid response - serve directly
            if($httpResponse !== false && $httpResponse->HttpResponseCode === 200)
            {
                $httpResponse->LinkContentUrlPlaceholdersToCache();
                $httpResponse->ReplaceAllContentPlaceholders();

                if( !$bSuppressOutput )
                {
                    echo $httpResponse->Content;
                }

                // Refresh cache asynchronously (if timed out)
                if( $httpResponse->cIsFromCache && $httpResponse->cIsTimedOut && !$bSuppressOutput )
                {
                    $asyncCallbackUrl = admin_url( $asyncCallbackUrlBaseWithPermutation . "&suppressoutput=1" );
                    $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadszone".$intZoneId."p".$intPermutation, CrankyAdsCopyAsyncContentEnum::DoNotCopy, false, true );
                }

                return true;
            }
            // Invalid Response
            else
            {
                // Debug output
                if( CRANKY_ADS_DEBUG )
                {
                    if( $httpResponse !== false )
                    {
                        echo("<div style='max-width:200px;max-height:500px;overflow:auto;border:dashed 2px red'><h2>DEBUG:ContentController.ServeAdZoneAds( \$intZoneId=$intZoneId, \$isUserSensitive=$isUserSensitive, \$intPermutation=$intPermutation, \$bSuppressOutput=$bSuppressOutput )</h2><br/>Error occurred - Http Response code is unexpected (".$httpResponse->HttpResponseCode.");<br/><br/><b>Content:</b><br/>".$httpResponse->Content."</div>");
                    }
                    else
                    {
                        // Check that we're not expecting this error
                        // if $isUserSensitive && !CRANKY_ADS_DISABLE_ASYNC_LOADING) then we expect this to be false when the data is not in the cache. So don't output that error.
                        if( !($isUserSensitive && !CRANKY_ADS_DISABLE_ASYNC_LOADING) )
                        {
                            echo("<div style='max-width:200px;max-height:500px;overflow:auto;border:dashed 2px red'<h2>DEBUG:ContentController.ServeAdZoneAds( \$intZoneId=$intZoneId, \$isUserSensitive=$isUserSensitive, \$intPermutation=$intPermutation, \$bSuppressOutput=$bSuppressOutput )</h2><br/>Error occurred - Http Response is empty</div>");
                        }
                    }
                }

                // Serve Asynchronously
                if( $isUserSensitive )
                {
                    if( !$bSuppressOutput )
                    {
                        // Serve this zone asynchronously
                        $asyncCallbackUrl = admin_url( $asyncCallbackUrlBaseWithPermutation );
                        $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadszone".$intZoneId."p".$intPermutation );

                        // Refresh the cache for all the other permutations of this zone 
                        // (so we don't get a set of successive asynchronous loads on the following page refreshes for this zone)
                        for ($iPermutation = 1; $iPermutation <= $this->ADZONE_CACHE_NUMBER_OF_COPIES; $iPermutation++) 
                        {
                            $iAsyncCallbackUrl = admin_url( $asyncCallbackUrlBase . "&permutation=$iPermutation&suppressoutput=1" );
                            if( $iPermutation != $intPermutation )
                                $this->ServeAsynchronousContentIFrame( $iAsyncCallbackUrl, "crankyadszone".$intZoneId."p".$iPermutation, CrankyAdsCopyAsyncContentEnum::DoNotCopy);
                        }
                    }
                    return true;
                }
                // Fail - Already asynchronous and we've still got an error
                else
                {
                    return false;
                }
            }
        }

        /// <summary>
        /// Returns the Cranky Ads Advertise Here HTML
        /// </summary>
        /// <remarks>
        /// . $isUserSensitive 
        ///   . Whether this is a user sensitive request (i.e. the page contents will not display until this request is complete)
        ///   . If true and the contents are not immediately available then this method will serve an iFrame to asynchronously load the contents
        /// . $strInstructions - Any instructions to pass to the server when requesting the Advertise Here page
        /// . $bSuppressAndReturnOutput - If true then instead of serving the result directly to the response this method will instead return the result to the caller
        /// </remarks>
        function ServeAdvertiseHere( $isUserSensitive=true, $strInstructions=false, $bSuppressAndReturnOutput=false )
        {
            // ** Setup
            $localActionProxyUrl = CrankyAdsHelper::GetFullRequestUri("serverurl");
            $remoteUrl = $this->Proxy->ToServerUrlFromActionUrl("AdvertiseHere/AdvertiseHere");
            $asyncCallbackUrlBase = 'admin-ajax.php?action=crankyads_serveadvertisehereasync';

            if($strInstructions !== false )
            {
                $remoteUrl .= "?instructions=".urlencode($strInstructions);
                $asyncCallbackUrlBase .= "&instructions=".urlencode($strInstructions);
            }

            // ** Determine cache behaviour
            $cacheBehaviour = CrankyAdsCacheBehaviourFlags::DefaultBehaviour;

            // Synchronous - we only want cached copies, DO NOT call the main server (and risk blocking the calling request)
            if( $isUserSensitive )
            {
                $cacheBehaviour = CrankyAdsCacheBehaviourFlags::DoNotMakeRequestToServer | CrankyAdsCacheBehaviourFlags::IgnoreCacheTimeout;
            }
            // Asynchronous - we can call the server directly
            else
            {
                $cacheBehaviour = CrankyAdsCacheBehaviourFlags::UseTimedOutCacheAsFallbackOnServerError;
            }
            

            // ** Get the Advertise Here page
            $httpResponse = $this->Proxy->GetRemoteContent($remoteUrl,false,false,0,3,false,true,false,$cacheBehaviour,false,"advertisehere");
            
            // Valid response - serve directly
            if($httpResponse !== false && $httpResponse->HttpResponseCode === 200)
            {
                $httpResponse->ReplaceAllContentPlaceholders();
                $result = $httpResponse->Content;

                // Refresh cache asynchronously (if timed out)
                if( $httpResponse->cIsFromCache && $httpResponse->cIsTimedOut )
                {
                    $asyncCallbackUrl = admin_url( $asyncCallbackUrlBase . "&suppressoutput=1" );
                    $iframe = $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadsadvertisehere", CrankyAdsCopyAsyncContentEnum::DoNotCopy, false, true, $bSuppressAndReturnOutput );
                    if( $bSuppressAndReturnOutput )
                        $result .= $iframe;
                }

                // Return or Serve the output
                if($bSuppressAndReturnOutput)
                {
                    return $result;
                }
                else
                {
                    echo $result;
                    return true;
                }
            }
            // Invalid Response
            else
            {
                // Serve Asynchronously
                if( $isUserSensitive )
                {
                    $asyncCallbackUrl = admin_url( $asyncCallbackUrlBase );

                    $result = $this->ServeAsynchronousContentIFrame( $asyncCallbackUrl, "crankyadsadvertisehere", CrankyAdsCopyAsyncContentEnum::CopyAbove, true, true, $bSuppressAndReturnOutput );

                    // Return or Serve the output
                    if($bSuppressAndReturnOutput)
                    {
                        return $result;
                    }
                    else
                    {
                        echo $result;
                        return true;
                    }
                }
                // Fail - Already asynchronous and we've still got an error
                else
                {
                    if( $httpResponse === false )
                        $result = "<!-- error connecting to the cranky ads server (HttpResponse is null; Browser Error No: " . $this->Proxy->Browser->lastErrorNumber . "; Browser Error String: " . $this->Proxy->Browser->lastErrorString . ") -->";
                    else
                        $result = "<!-- error connecting to the cranky ads server (HttpResponse Code: " . $httpResponse->HttpResponseCode . "; Browser Error No: " . $this->Proxy->Browser->lastErrorNumber . "; Browser Error String: " . $this->Proxy->Browser->lastErrorString . ") -->";

                    // Return or Serve the output
                    if($bSuppressAndReturnOutput)
                    {
                        return $result;
                    }
                    else
                    {
                        echo $result;
                        return false;
                    }
                }
            }
        }

        /// <summary>
        /// Get and serve any remote page to the response (limited to /Plugin on the server)
        /// </summary>
        /// <remarks>
        /// If the server redirect the request to another page then this method will send redirect
        /// to the equivalent local page for the redirected server page, $strLocalActionProxyUrl?serverurl=REDIRECTED_SERVER_URL.
        /// </remarks>
        function ServeRemotePage($strServerRelativeUrl, $bRestrictToAdmin=true, $strLocalActionProxyUrl="", $bOutputOnFailure=true, $funcOnResponseReceived=false, $intCacheResultForSeconds=false )
        {
            // ** Security checks
            if( strlen($strServerRelativeUrl) <= 0 ) // No url
                return false;

            if($strServerRelativeUrl[0] != "/") // starting /
                $strServerRelativeUrl = "/".$strServerRelativeUrl;

            $checkUrl = strtolower($strServerRelativeUrl);
            if( strpos( $checkUrl, "/plugin" ) !== 0 ) // Only /plugin directory
                return false;

            if( $bRestrictToAdmin && (!function_exists( "is_admin" ) || !is_admin() ) ) // Only Admin users - actually we should probably do this if(current_user_can('manage_options')) since is_admin() just checks whether the admin panel is open
            {
                if($bOutputOnFailure)
                    wp_die( __('<p>You do not have permission to access this page.</p>') );
                return false;
            }

            // ** Serve the content
            $httpResponse = $this->Proxy->GetRemoteContent( $strServerRelativeUrl, true, false, $intCacheResultForSeconds, 0 );

            if($httpResponse !== false )
            {
                // * Call the callback function
                if( $funcOnResponseReceived !== false )
                    call_user_func($funcOnResponseReceived,$httpResponse);

                // * We've been redirected
                if( $httpResponse->hLocation !== false )
                {
                    // Redirect to the equivalent local URL that matches to the remote url
                    $urlJoin = "?";
                    if( strpos( $strLocalActionProxyUrl , "?" ) !== false )
                        $urlJoin = "&";

                    $redirectTo = $strLocalActionProxyUrl.$urlJoin."serverurl=".urlencode($httpResponse->hLocation);

                    // Note: We can't set response headers here since WP has already written content to the stream. So we need to use Javascript
                    //wp_redirect( $redirectTo, 302 ); exit();
                    //header("Location: ".$redirectTo); exit();
                    ?>
                    <script type='text/javascript'>
                        window.location = '<?php echo $redirectTo; ?>';
                    </script>
                    This page has moved. Please go <a href='<?php echo $redirectTo; ?>'>here</a>. Thank you.;
                    <?php

                    return true;
                }
                // * Error - unexpected return code
                else if( $httpResponse->HttpResponseCode !== 200 )
                {
                    if( CRANKY_ADS_DEBUG )
                    {
                        echo("<div><h2>DEBUG:ContentController.ServeRemotePage(..)</h2><br/>Error occurred - Http Response code is unexpected (".$httpResponse->HttpResponseCode.");<br/><br/><b>Content:</b><br/>".$httpResponse->Content."</div>");
                    }

                    if($bOutputOnFailure)
                    {
                        if($httpResponse->HttpResponseCode >= 500)
                            wp_die( __('<p>A Cranky Ads Server Error occurred. <!-- (HttpResponse Code: ' . $httpResponse->HttpResponseCode . '; Browser Error No: ' . $this->Proxy->Browser->lastErrorNumber . '; Browser Error String: ' . $this->Proxy->Browser->lastErrorString . ') --></p>') );
                        else
                            wp_die( __('<p>Error contacting the Cranky Ads Server. Please try again later. <!-- (HttpResponse Code: ' . $httpResponse->HttpResponseCode . '; Browser Error No: ' . $this->Proxy->Browser->lastErrorNumber . '; Browser Error String: ' . $this->Proxy->Browser->lastErrorString . ') --></p>') );
                    }

                    return false;
                }
                // * Serve this response
                else
                {
                    $httpResponse->ReplaceAllContentPlaceholders($strLocalActionProxyUrl);
                    $httpResponse->CopyToResponse(false);
                    return true;
                }
            }
            else
            {
                if($bOutputOnFailure)
                    wp_die( __('<p>Error contacting the Cranky Ads Server. Please try again later. <!-- (HttpResponse is null; Browser Error No: ' . $this->Proxy->Browser->lastErrorNumber . '; Browser Error String: ' . $this->Proxy->Browser->lastErrorString . ') --></p>') );

                return false;
            }
        }

        /// <summary>
        /// Get and forward any remote content to the response (limited to /Content and /Plugin on the server)
        /// </summary>
        /// <remarks>
        /// . This will automatically call CrankyAdsHttpResponse->ReplaceAllContentPlaceholders() and then copy the headers and content from the server to response.
        /// . This call is flagged as public content only (so no non-public actions can be served)
        /// . If $strLocalActionProxyUrl is false then the current url (minus serverurl querystring parameter) will be used
        /// </remarks>
        function ServeRemoteContent( $strServerRelativeUrl, $strLocalActionProxyUrl=false, $intCacheResultForSeconds=false )
        {
            // ** Security checks
            if( strlen($strServerRelativeUrl) <= 0 ) // No url
                return false;

            if($strServerRelativeUrl[0] != "/") // starting /
                $strServerRelativeUrl = "/".$strServerRelativeUrl;

            // ** Init
            if( $strLocalActionProxyUrl === false )
            {
                $strLocalActionProxyUrl = CrankyAdsHelper::GetFullRequestUri("serverurl");
            }

            /* The following code transparently handles redirects. The replacement code further below simply passes these to the original requester
            // ** Get the content
            // Note: 
            // We manually handle redirects here so we can capture and send on all the Set-Cookie headers
            // This means we also need to manage the raw Cookie string between redirects
            $cookies = isset($_SERVER["HTTP_COOKIE"]) ? $_SERVER["HTTP_COOKIE"] : false;
            $targetUrl = $strServerRelativeUrl;
            $intMaxRedirects = 3;

            while ( $intMaxRedirects >=0 ) 
            {
                // * Build the Cookies header
                if($cookies === false)
                    $headers = false;
                else
                    $headers = array("Cookie" =>$cookies);

                // * Get the response (without auto-redirects)
                $httpResponse = $this->Proxy->GetRemoteContent($targetUrl,true,true,$intCacheResultForSeconds,0,false,true,$headers,CrankyAdsCacheBehaviourFlags::DoNotCacheHtml | CrankyAdsCacheBehaviourFlags::UseTimedOutCacheAsFallbackOnServerError | CrankyAdsCacheBehaviourFlags::ReplaceContentUrlPlaceholdersOnServerResponse, false, "content");

                // * Response received
                if($httpResponse !== false)
                {
                    // Redirect
                    if($httpResponse->hLocation !== false && ($httpResponse->HttpResponseCode === 301 || $httpResponse->HttpResponseCode === 302 ) )
                    {
                        $targetUrl = $httpResponse->hLocation;                                      // Update the target Url
                        $httpResponse->CopySetCookieHeadersToResponse();                            // Send on all the Set-Cookie headers from this response (if any)
                        $cookies = $httpResponse->AppendSetCookieHeadersToCookieString($cookies);   // Maintain the set of cookies sent to the server for the next request
                    }
                    // Done
                    else
                    {
                        break;
                    }

                }
                // * No Response
                else
                {
                    break;
                }

                // * Try again?
                $intMaxRedirects--;

            }
            */

            // ** Get the content
            $httpResponse = $this->Proxy->GetRemoteContent($strServerRelativeUrl,true,true,$intCacheResultForSeconds,0,true,true,false,CrankyAdsCacheBehaviourFlags::DoNotCacheHtml | CrankyAdsCacheBehaviourFlags::UseTimedOutCacheAsFallbackOnServerError | CrankyAdsCacheBehaviourFlags::ReplaceContentUrlPlaceholdersOnServerResponse, false, "content");
            

            // ** Serve the content

            // Success
            if($httpResponse !== false && $httpResponse->HttpResponseCode === 200)
            {
                $httpResponse->ReplaceAllContentPlaceholders($strLocalActionProxyUrl);
                $httpResponse->CopyToResponse(true,false); // Note: No real need NOT to copy the result code, I'm just making a point that 200 is the default result
                return true;
            }
            // 304 - No changes
            else if($httpResponse !== false && $httpResponse->HttpResponseCode === 304)
            {
                $httpResponse->CopyToResponse(true,true);
                return true;
            }
            // Redirect
            else if($httpResponse !== false && $httpResponse->hLocation !== false && ($httpResponse->HttpResponseCode === 301 || $httpResponse->HttpResponseCode === 302 ) )
            {
                // Build the re-direction url
                $redirectTo = $strLocalActionProxyUrl; 
                if( strpos( $redirectTo, "?" ) === false )
                    $redirectTo .= "?";
                else
                    $redirectTo .= "&";
                $redirectTo .= "serverurl=" . urlencode($httpResponse->hLocation);

                // Copy the result to the output
                $httpResponse->ReplaceAllContentPlaceholders($strLocalActionProxyUrl);
                header("Location: ".$redirectTo,true,$httpResponse->HttpResponseCode);
                $httpResponse->CopyToResponse(true,false);

                return true;
            }
            else if( CRANKY_ADS_DEBUG )
            {
                if( $httpResponse !== false )
                {
                    echo("<div><h2>DEBUG:ContentController.ServeRemoteContent(..)</h2><br/>Error occurred - Http Response code is unexpected (".$httpResponse->HttpResponseCode.");<br/><br/><b>Content:</b><br/>".$httpResponse->Content."</div>");
                }
                else
                {
                    echo("<div><h2>DEBUG:ContentController.ServeRemoteContent(..)</h2><br/>Error occurred - Http Response is empty</div>");
                }

                return false;
            }
            // Fail
            else
            {
                return false;
            }
        }

        /// <summary>
        /// Serves a hidden <iframe> for the specified targetUrl and (if requested) copies the loaded contents back to the parent DOM just after the <iframe>
        /// </summary>
        /// <remarks>
        /// . This content was tested for NON-HEAD content and:
        ///   . Works on: Chrome 14, IE9, IE8, FF 6.0.2, FF 3.6, Opera 11.51, Safari 5.1,
        ///   . Partially Fails on: IE 7, IE 6 (TODO (794): Get this to work - although it is a rather small % of browsers AND iFrame should only ever occur for 1 refresh)
        ///     - On these browsers the asynchronous load occurs, however, the javascript generated for $eShowContentOnLoad fails and so the content is never copied to the main page's html
        ///     - Given the way ServeAsynchronousContentIFrame(..) is used this should not cause a problem since the next refresh should immediately return the cached result without needing to render an <iframe>
        /// . This content was tested for HEAD content and:
        ///   . Works on: Chrome 14, FF 6.0.2, FF 3.6, Opera 11.51, Safari 5.1,
        ///   . Fails on: IE 6-9 (TODO (794): Get this to work)
        ///     - On these browsers (apart from showing the same problems as the non-head content above for IE6-7) there seems to be a problem with jQuery not being defined when the jQuery <script> is loaded asynchronously
        /// . $strTargetUrl - The target of the iframe
        /// . $strName - A name for the content being loaded [a-zA-Z0-9]+. This is used to uniquely name all served content (id's and function names)
        /// . $eShowContentOnLoad 
        ///   - Copies the content of the iFrame to the parent DOM via injected javascript once loaded
        ///   - This must be one of the CrankyAdsCopyAsyncContentEnum values
        ///   - If this is anything but DoNotCopy then $strTargetUrl MUST be the same domain as the one where the resultant <iframe> is being served from
        /// . $bShowLoader - Show a loading animation while the actual content is being loaded (only valid if $eShowContentOnLoad is NOT DoNotCopy)
        /// . $bAppendTimestampQuerystring - Append the querystring &timestamp=yyyymmddhhmmss to the targetUrl so that browsers are discouraged from caching the iframe contents
        /// . $bSuppressAndReturnOutput - If true then instead of serving the result directly to the response this method will instead return the result to the caller
        /// </remarks>
        function ServeAsynchronousContentIFrame( $strTargetUrl, $strName, $eShowContentOnLoad=1, $bShowLoader=false, $bAppendTimestampQuerystring=true, $bSuppressAndReturnOutput=false )
        {
            // ** Setup
            $bShowContentOnLoad = ($eShowContentOnLoad != CrankyAdsCopyAsyncContentEnum::DoNotCopy);
            $bShowLoader = $bShowLoader && $bShowContentOnLoad;
            $imageUrl = plugins_url( 'images/loading.gif' , dirname(__FILE__) );
            $strName .= "UID" . $this->GetUniqueId();

            if($bAppendTimestampQuerystring)
            {
                $strTargetUrl = CrankyAdsHelper::UrlAddToQuerystring($strTargetUrl, "timestamp", gmdate("YmdHis"));
            }

            // ** Output content
            if($bSuppressAndReturnOutput)
                ob_start();
            ?>
                <div id='crankyads_async_loader_<?php echo($strName); ?>' class='crankyads_async_loader' style='display:none;background-image: url("<?php echo($imageUrl); ?>");height:24px;width:24px;'>

                <?php if($bShowLoader || $bShowContentOnLoad ){ ?> 
                    <script type='text/javascript'>

                    <?php if($bShowLoader){ ?> 
                    setTimeout('CrankyAdsAsyncLoaderShowWait<?php echo($strName); ?>( true )',750); // Show loader only after a little while
                    setTimeout('CrankyAdsAsyncLoaderShowWait<?php echo($strName); ?>( false )',10000); // Hide loader if it's clear loading has failed

                    // Summary: Show / Hide the asynchronous loader DIV
                    function CrankyAdsAsyncLoaderShowWait<?php echo($strName); ?>( bShow )
                    {
                        var asyncLoaderDiv = document.getElementById('crankyads_async_loader_<?php echo($strName); ?>'); 
                        if( asyncLoaderDiv )
                        {
                            if( bShow )
                                asyncLoaderDiv.style.display = '';
                            else
                                asyncLoaderDiv.style.display = 'none';
                        }
                    }
                    <?php } ?>

                    <?php if($bShowContentOnLoad){ ?> 

                    <?php if($eShowContentOnLoad == CrankyAdsCopyAsyncContentEnum::CopyToHead){ ?>  
                    function CrankyAdsAsyncLoaderDoesScriptTagExist<?php echo($strName); ?>( src )
                    {
                        src = src.toLowerCase();
                        var existingScripts = document.getElementsByTagName('script');
                        for( var i=0; i<existingScripts.length; i++ )
                        {
                            var existingSrc = existingScripts[i].getAttribute('src');
                            if( existingSrc != null )
                            {
                                existingSrc = existingSrc.replace(/(?:\?|\&)ver=[\d\.]*/gi, "");
                                existingSrc = existingSrc.toLowerCase();
                                if( src == existingSrc )
                                    return true;
                            }
                        }

                        return false;
                    }
                    
                    function CrankyAdsAsyncLoaderCopyScriptTagToHead<?php echo($strName); ?>( src, onload )
                    {
                        var scriptExists = CrankyAdsAsyncLoaderDoesScriptTagExist<?php echo($strName); ?>( src );
                        //alert('Adding:'+src+'; Exists:' + scriptExists );

                        if( scriptExists )
                        {
                            if( typeof(onload) != "undefined" )
                                eval( onload );
                            return;
                        }

                        var sc = document.createElement('script');
                        sc.type = 'text/javascript';
                        sc.src = src;
                        if( typeof(onload) != "undefined" )
                            sc.setAttribute("onload", onload );

                        var headTag = document.getElementsByTagName('head')[0]; 
                        headTag.appendChild(sc);
                    }
                    <?php } ?>

                    // Summary: Copy content out of the iFrame into the parent HTML
                    function CrankyAdsAsyncLoaderCopyToParent<?php echo($strName); ?>()
                    {
                        // ** Get the loader DOM elements
                        var asyncLoaderDiv    = document.getElementById('crankyads_async_loader_<?php echo($strName); ?>'); 
                        var asyncLoaderIFrame = document.getElementById('crankyads_async_loader_iframe_<?php echo($strName); ?>'); 
                        <?php if($eShowContentOnLoad == CrankyAdsCopyAsyncContentEnum::CopyToHead){ ?> 
                        var headTag           = document.getElementsByTagName('head')[0]; 
                        <?php } ?> 

                        // ** Get the iFrame contents
                        var iFrameDocument;

                        // Chrome / FF
                        if ( asyncLoaderIFrame.contentDocument ) 
                        {
                            iFrameDocument = asyncLoaderIFrame.contentDocument;
                        }
                        // IE
                        else if ( asyncLoaderIFrame.contentWindow ) 
                        {
                            iFrameDocument = asyncLoaderIFrame.contentWindow.document;
                        }

                        var iFrameRootTag;
                        <?php if($eShowContentOnLoad == CrankyAdsCopyAsyncContentEnum::CopyToHead){ ?>  
                        iFrameRootTag = iFrameDocument.getElementsByTagName('head'); // This should be an array of 1
                        <?php }else{ ?>  
                        iFrameRootTag = iFrameDocument.getElementsByTagName('body'); // This should be an array of 1
                        <?php } ?>  

                        if(iFrameRootTag)
                            iFrameRootTag = iFrameRootTag[0];
                        if(!iFrameRootTag) // Fallback
                            iFrameRootTag = iFrameDocument.documentElement;

                        // ** Insert the iFrame contents
                        while( iFrameRootTag.children.length > 0 )
                        {
                            var iElement = iFrameRootTag.children[0];
                            iFrameRootTag.removeChild(iElement);
                            <?php if($eShowContentOnLoad == CrankyAdsCopyAsyncContentEnum::CopyToHead){ ?>  
                            if( iElement.tagName == 'SCRIPT' )
                            {
                                CrankyAdsAsyncLoaderCopyScriptTagToHead<?php echo($strName); ?>( iElement.src, "Javascript:CrankyAdsAsyncLoaderCopyToParent<?php echo($strName); ?>();" ); // Restart the copy AFTER the script is loaded (to avoid dependant scripts from loading simultaneously)
                                return;
                            }
                            else
                            {
                                headTag.appendChild(iElement);
                            }
                            <?php }else{ ?>  
                            if( iElement.tagName == 'SCRIPT' )
                            {
                                var scriptTag = document.createElement('script');
                                scriptTag.type = iElement.type;
                                scriptTag.innerHTML = iElement.innerHTML;
                                asyncLoaderDiv.parentNode.insertBefore(scriptTag,asyncLoaderDiv);
                            }
                            else
                            {
                                asyncLoaderDiv.parentNode.insertBefore(iElement,asyncLoaderDiv);
                            }
                            <?php } ?> 
                        }

                        // ** Remove the loader
                        asyncLoaderDiv.parentNode.removeChild(asyncLoaderDiv);
                    }
                    <?php } ?>

                    </script>
                <?php } ?>

                    <iframe id='crankyads_async_loader_iframe_<?php echo($strName); ?>' src='<?php echo($strTargetUrl); ?>' style='display:none' <?php if($bShowContentOnLoad){ ?>onload='javascript:CrankyAdsAsyncLoaderCopyToParent<?php echo($strName); ?>();'<?php }else{ ?>onload="javascript:this.parentNode.parentNode.removeChild(this.parentNode);"<?php } ?> ></iframe>

                </div>
            <?php

            if($bSuppressAndReturnOutput)
            {
                $result = ob_get_contents();
                ob_end_clean();
                return $result;
            }

        }

        /// <summary>
        /// Checks for any CrankyAds notification headers in the $httpResponse and send those on to get processed
        /// </summary>
        function HandleCrankyAdsNotification( $strNotificationName, $arrNotificationValues, $bOutputResult = false )
        {
            // ** Setup
            $strNotificationName = strtolower($strNotificationName);
            $xmlResponse = "Invalid Notification";

            // ** AdZone Added / AdZone Modified / Re-sync Zones
            if( $strNotificationName == "adzone-registered" || 
                $strNotificationName == "adzone-modified" )
            {
                // Update the zone in the local DataContext
                $zoneId = intval($arrNotificationValues[1]);
                $zoneName = $arrNotificationValues[0];
                
            }
            if( $strNotificationName == "adzone-registered" || 
                $strNotificationName == "adzone-modified" ||
                $strNotificationName == "resync-zones" )
            {
                // * Sync the zones
                $this->DataContext->Remote_UpdateAllZonesFromServer(); // TODO (795): Make this more robust - see the TODO on the other Remote_UpdateAllZonesFromServer() line below

                // * Clear the Advertise Here page cache
                $this->Cache->DeleteCacheForCacheType("advertisehere");

                $xmlResponse = "OK";
            }

            // ** Blog Registered - save the blog id in the DB
            if( $strNotificationName === "blog-registered" )
            {
                $this->DataContext->SetBlogGuid( trim($arrNotificationValues[0]) );
                $this->DataContext->Remote_UpdateAllZonesFromServer(); // TODO (795): This process should be made more robust. If a failure occurs then we should save it as an Option. Then when next load, if the last sync failed we should do it there. Just make sure this is not a blocking call (check if is_admin() - but then give it a rest of up to 1 minute if there's an issue or serve an iFrame)! Also lets make this a generic solution, so DataContext->Remote_Update...(..,$bAutoResyncOnFailure=true). If a failure occurs then the next time OnFooter is called it will serve an iframe that will call through to a new admin-ajax that will try to resync all the unsynced items (currently only Remote_UpdateAllZonesFromServer())
                $xmlResponse = "OK";
            }


            // ** Clear cache
            if( $strNotificationName == "clear-cache" )
            {
                $this->Cache->Clear();
                $xmlResponse = "OK";
            }

            // ** Delete cache for type
            if( $strNotificationName == "delete-cache-for-type" )
            {
                if( count($arrNotificationValues) == 0 )
                {
                    $this->Cache->DeleteCacheForCacheType(false);
                    $xmlResponse = "<Result>1</Result><Message>Cache deleted for NULL type</Message>";
                }
                else
                {
                    $this->Cache->DeleteCacheForCacheType($arrNotificationValues[0]);
                    $xmlResponse = "<Result>1</Result><Message>Cache deleted for type '{$arrNotificationValues[0]}'</Message>";
                }
            }

            // ** Get Cache Info
            if( $strNotificationName == "get-cache-info" )
            {
                $cacheEntries = $this->DataContext->GetAllCacheEntries();

                $xmlResponse = "<Count>".count($cacheEntries)."</Count>";
                $xmlResponse .= "<List>";

                foreach ($cacheEntries AS $iCacheEntry)
                {
                    $xmlResponse .= "<CacheEntry>";

                    $iCacheEntryArray = (array)$iCacheEntry;
                    foreach ($iCacheEntryArray AS $key => $value)
                    {
                        $xmlResponse .= "<" . htmlentities($key) . ">";
                        $xmlResponse .= htmlentities($value);
                        $xmlResponse .= "</" . htmlentities($key) . ">";
                    }

                    $xmlResponse .= "</CacheEntry>";
                }

                $xmlResponse .= "</List>";
            }

            // ** Get Info
            if( $strNotificationName == "get-info" )
            {
                global $wp_version;
                $xmlResponse = "<WordpressVersion>$wp_version</WordpressVersion><SiteUrl>".site_url()."</SiteUrl><ServerTime>".gmdate("Y-m-d H:i:s") . " UTC"."</ServerTime>";
            }

            // ** Get Option
            if( $strNotificationName == "get-option" )
            {
                if( count($arrNotificationValues) == 0 )
                {
                    $xmlResponse = "<Result>error</Result><Message>Missing Option Name Argument</Message><Value></Value>";
                }
                else
                {
                    $optionValue = $this->DataContext->GetOption($arrNotificationValues[0]);

                    if( $optionValue === false )
                        $xmlResponse = "<Result>0</Result><Message>Option not found</Message><Value></Value>";
                    else
                        $xmlResponse = "<Result>1</Result><Message>Option found</Message><Value>$optionValue</Value>";
                }
            }

            // ** Set Advertise Here Page Id
            if( $strNotificationName == "set-advertise-here-page-id" )
            {
                if( count($arrNotificationValues) < 1 )
                {
                    $xmlResponse = "<Result>error</Result><Message>Missing Arguments: advertise-here-page-id</Message>";
                }
                else if( intval($arrNotificationValues[0]) == 0 && $arrNotificationValues[0] !== "0" )
                {
                    $xmlResponse = "<Result>error</Result><Message>advertise-here-page-id is not an integer</Message>";
                }
                else
                {
                    $this->DataContext->SetAdvertiseHerePageId( intval($arrNotificationValues[0]) );
                    $xmlResponse = "<Result>1</Result><Message>Option Advertise Here Page Id set to '".intval($arrNotificationValues[0])."'</Message>";
                }
            }

            // ** Set Option
            if( $strNotificationName == "set-option" )
            {
                if( count($arrNotificationValues) < 1 )
                {
                    $xmlResponse = "<Result>error</Result><Message>Missing Arguments: option name[,value]</Message>";
                }
                else if( count($arrNotificationValues) < 2 )
                {
                    $this->DataContext->SetOption( $arrNotificationValues[0], false );
                    $xmlResponse = "<Result>1</Result><Message>Option '$arrNotificationValues[0]' Cleared</Message>";
                }
                else
                {
                    $this->DataContext->SetOption( $arrNotificationValues[0], $arrNotificationValues[1] );
                    $xmlResponse = "<Result>1</Result><Message>Option '$arrNotificationValues[0]' set to '$arrNotificationValues[1]'</Message>";
                }
            }

            // ** Set Update Plugin
            // To inform Wordpress that there is a new version of CrankyAds on the site
            if( $strNotificationName == "set-update-plugin" )
            {
                if( count($arrNotificationValues) == 0 )
                {
                    // Get the plugin update info
                    $current = get_site_transient( 'update_plugins' );

                    // Set the CrankyAds plugin update-info (only if it doesn't exist or we're allowed to overwrite)
                    if(isset($current) && isset($current->response["crankyads/CrankyAdsPlugin.php"]))
                    {
                        unset($current->response["crankyads/CrankyAdsPlugin.php"]);
                        set_site_transient('update_plugins',$current);

                        $xmlResponse = "<Result>2</Result><Message>Plugin upgrade information cleared.</Message>";

                    }
                    else
                    {
                        $isCurrentSet = isset($current)?"1":"0";
                        $isResponseSet = isset($current->response["crankyads/CrankyAdsPlugin.php"])?"1":"0";
                        $xmlResponse = "<Result>-2</Result><Data><Current>$isCurrentSet</Current><Response>$isResponseSet</Response></Data><Message>No changes made to plugin upgrade information</Message>";
                    }
                }
                else if( count($arrNotificationValues) < 4 )
                {
                    $xmlResponse = "<Result>error</Result><Message>Missing Arguments: [overwrite,version,url,url-package]</Message>";
                }
                else
                {
                    // Setup
                    $overwrite = $arrNotificationValues[0] == "1" || strtolower($arrNotificationValues[0]) == "true";
                    $version = $arrNotificationValues[1];
                    $url = $arrNotificationValues[2];
                    $urlPackage = $arrNotificationValues[3];
                    if(strlen($url) > 0 && $url[0] != "/" ) // starting /
                        $url = "/".$url;
                    if(strlen($urlPackage) > 0 && $urlPackage[0] != "/" ) // starting /
                        $urlPackage = "/".$urlPackage;

                    // Force Wordpress to check for updates
                    if(function_exists('wp_update_plugins'))
                    {
                        wp_update_plugins(); 
                    }

                    // Get the plugin update info
                    $current = get_site_transient( 'update_plugins' );

                    // Set the CrankyAds plugin update-info (only if it doesn't exist or we're allowed to overwrite)
                    if(isset($current) && ( $overwrite || !isset($current->response["crankyads/CrankyAdsPlugin.php"]) ))
                    {
                        if( isset($current->response["crankyads/CrankyAdsPlugin.php"]) )
                            unset($current->response["crankyads/CrankyAdsPlugin.php"]);

                        $response = new stdClass;
                        $response->id = 0;
                        $response->slug = "crankyads";
                        $response->new_version = $version;
                        $response->upgrade_notice = "Latest fixes, features and performance improvements";
                        $response->url = "http://" . CRANKY_ADS_DOMAIN . $url;
                        $response->package = "http://" . CRANKY_ADS_DOMAIN . $urlPackage;

                        $current->response["crankyads/CrankyAdsPlugin.php"] = $response;
                        set_site_transient('update_plugins',$current);

                        $xmlResponse = "<Result>1</Result><Message>Plugin upgrade information set.</Message>";

                    }
                    else
                    {
                        $isCurrentSet = isset($current)?"1":"0";
                        $isResponseSet = isset($current->response["crankyads/CrankyAdsPlugin.php"])?"1":"0";
                        $isOverwriteSet = $overwrite?"1":"0";
                        $xmlResponse = "<Result>-1</Result><Data><Current>$isCurrentSet</Current><Overwrite>$isOverwriteSet</Overwrite><Response>$isResponseSet</Response></Data><Message>No changes made to plugin upgrade information</Message>";
                    }
                }
            }

            // ** Delete cache for type
            if( $strNotificationName == "timeout-cache-for-type" )
            {
                if( count($arrNotificationValues) == 0 )
                {
                    $this->Cache->TimeoutCacheForCacheType(false);
                    $xmlResponse = "<Result>1</Result><Message>Cache timed out for NULL type</Message>";
                }
                else
                {
                    $this->Cache->TimeoutCacheForCacheType($arrNotificationValues[0]);
                    $xmlResponse = "<Result>1</Result><Message>Cache timed out for type '{$arrNotificationValues[0]}'</Message>";
                }
            }

            // ** Uninstall
            if( $strNotificationName == "uninstall" )
            {
                $this->Plugin->Uninstall();
                $xmlResponse = "OK";
            }

            // ** Output
            if( $bOutputResult )
                $this->OutputCrankyAdsNotificationsResponse( $strNotificationName, $arrNotificationValues, $xmlResponse );

        }

        // ==========================================================================================================
        //                                              Helper Methods
        // ==========================================================================================================

        /// <summary>
        /// Returns a Unique ID 
        /// </summary>
        /// <remarks>
        /// . This method returns a unique Id between min=1 and max=1000000
        /// . The Ids are returned in sequence from min, min+1,...,max
        /// . After max is reached, the next value returned will be min
        /// </remarks>
        function GetUniqueId()
        {
            return $this->DataContext->IncrementCounterOption("ContentController.GetUniqueId",0,1000000);
        }

        /// <summary>
        /// Attempts to load the custom CSS file and custom Script files specified by $xmlCssData and $xmlScriptData.
        /// </summary>
        /// <remarks>
        /// . Depending on the $flagsCacheBehaviour passed in, this method can be used to:
        ///     1. Check whether all custom files are in the cache
        ///     2. Refresh the cache for all custom files
        /// . $bIsAvailable - returns whether all files were loaded successfully
        /// . $bIsTimedOut - returns whether any of the file returned were marked as timed-out
        /// </remarks>
        function LoadHeadData( $xmlCssData, $xmlScriptData, $flagsCacheBehaviour, &$bIsAvailable, &$bIsTimedOut)
        {
            // ** Setup
            $bIsAvailable = true;
            $bIsTimedOut = false;

            // ** Load custom css
            $customCss = $xmlCssData->getElementsByTagName("custom");

            foreach ($customCss AS $iCustom)
            {
                // * Get the URL for this custom css
                $cssUrl = $iCustom->attributes->getNamedItem("src")->nodeValue;
                $cssUrl = CrankyAdsHelper::UrlGetQuerystringValue($cssUrl,"serverurl");

                if( $cssUrl === false ) // The file has likely been been linked to a cache file directly
                	continue;
                
                // * Load the css content
                $cssEntry = $this->Proxy->GetRemoteContent($cssUrl,false,false,0,3,false,false,false,$flagsCacheBehaviour, false, "content");

                // Unable to load entry
                if($cssEntry === false || $cssEntry->HttpResponseCode !== 200)
                {
                    $bIsAvailable = false;
                }
                // Check if cache is timed out
                else
                {
                    if( $cssEntry->cIsFromCache && $cssEntry->cIsTimedOut )
                        $bIsTimedOut = true;
                }
            }

            // ** Short circuit
            // Can't get any worse than this, so no need to keep checking
            if( !$bIsAvailable && $bIsTimedOut )
                return;

            // ** Load custom scripts
            $customScript = $xmlScriptData->getElementsByTagName("custom");
            foreach ($customScript AS $iCustom)
            {
                // * Get the URL for this custom css
                $scriptUrl = $iCustom->attributes->getNamedItem("src")->nodeValue;
                $scriptUrl = CrankyAdsHelper::UrlGetQuerystringValue($scriptUrl,"serverurl");

                if( $scriptUrl === false ) // The file has likely been been linked to a cache file directly
                	continue;
                
                // * Check / Refresh the css content
                $scriptEntry = $this->Proxy->GetRemoteContent($scriptUrl,false,false,0,3,false,false,false,$flagsCacheBehaviour, false, "content");

                // Unable to load entry
                if($scriptEntry === false || $scriptEntry->HttpResponseCode !== 200)
                {
                    $bIsAvailable = false;
                }
                // Check if cache is timed out
                else
                {
                    if( $scriptEntry->cIsFromCache && $scriptEntry->cIsTimedOut )
                        $bIsTimedOut = true;
                }
            }
        }

        /// <summary>
        /// Output the <head> block
        /// </summary>
        /// <remarks>
        /// . $bUseEnqueue - Whether to use wp_enqueue_[style|script] to output the css and script items. Otherwise echo(..) will be used to directly write the entries.
        /// </remarks>
        function OutputHead( $bUseEnqueue, $xmlCssData, $xmlScriptData )
        {
            // ** Standard css
            $wp_styles = false;
            $standardCss = $xmlCssData->getElementsByTagName("standard");
            foreach ($standardCss AS $iStdCss)
            {
                if($bUseEnqueue)
                {
                    wp_enqueue_style( $iStdCss->attributes->getNamedItem("name")->nodeValue );
                }
                else
                {
                    // Get all the default Wordpress styles
                    // Note: This is done here for efficiency (if no standard wordpress css files are required, this will never be executed)
                    if(!$wp_styles)
                    {
                        $wp_styles = new WP_Styles();
                        wp_default_styles($wp_styles); 
                    }

                    $stdCssName = $iStdCss->attributes->getNamedItem("name")->nodeValue;
                    if ( !array_key_exists($stdCssName, $wp_styles->registered) )
		                continue;

                    echo("<link rel='stylesheet' id='$stdCssName-css' href='".site_url($wp_styles->registered[$stdCssName]->src)."' type='text/css' media='all'  />\n"); // Note: Wordpress also appends a ?ver=xx to the end of href (and encodes href with esc_attr)
                }
            }

            // * Custom css
            $customCss = $xmlCssData->getElementsByTagName("custom");
            foreach ($customCss AS $iCustomCss)
            {
                if($bUseEnqueue)
                {
                    $dependencies = false;
                    if(isset($iCustomCss->attributes->getNamedItem("dependencies")->nodeValue))
                        $dependencies = explode(";",$iCustomCss->attributes->getNamedItem("dependencies")->nodeValue);

                    wp_deregister_style( $iCustomCss->attributes->getNamedItem("name")->nodeValue );
                    wp_register_style(   $iCustomCss->attributes->getNamedItem("name")->nodeValue, $iCustomCss->attributes->getNamedItem("src")->nodeValue, $dependencies, false);
                    wp_enqueue_style(    $iCustomCss->attributes->getNamedItem("name")->nodeValue );
                }
                else
                {
                    $CssName = $iCustomCss->attributes->getNamedItem("name")->nodeValue;
                    $CssSrc = $iCustomCss->attributes->getNamedItem("src")->nodeValue;
                    echo("<link rel='stylesheet' id='$CssName-css' href='$CssSrc' type='text/css' media='all' />\n");  // Note: Wordpress also appends a ?ver=xx to the end of href (and encodes href with esc_attr)
                }
            }

            // * Standard script
            global $wp_scripts;
            $standardScripts = $this->ExtractStandardScripts($xmlScriptData,!$bUseEnqueue); // We don't need to explicitly handle dependencies if we use enqueue (since Wordpress does this for us)
            foreach ($standardScripts AS $iStdScript)
            {
                if($bUseEnqueue)
                {
                    wp_enqueue_script( $iStdScript );
                }
                else
                {
                    // Get all the default Wordpress scripts
                    // Note: This is done here for efficiency (if no standard wordpress script files are required, this will never be executed)
                    if(!$wp_scripts)
                    {
                        $wp_scripts = new WP_Scripts();
                        wp_default_scripts($wp_scripts); 
                    }

                    if ( !array_key_exists($iStdScript, $wp_scripts->registered) )
		                continue;

                    echo("<script type='text/javascript' src='".site_url($wp_scripts->registered[$iStdScript]->src)."'></script>");
                }
            }

            // * Custom script
            $customScripts = $xmlScriptData->getElementsByTagName("custom");
            foreach ($customScripts AS $iCustomScript)
            {
                if($bUseEnqueue)
                {
                    $dependencies = false;
                    if(isset($iCustomScript->attributes->getNamedItem("dependencies")->nodeValue))
                        $dependencies = explode(";",$iCustomScript->attributes->getNamedItem("dependencies")->nodeValue);

                    wp_deregister_script( $iCustomScript->attributes->getNamedItem("name")->nodeValue );
                    wp_register_script(   $iCustomScript->attributes->getNamedItem("name")->nodeValue, $iCustomScript->attributes->getNamedItem("src")->nodeValue, $dependencies, false);
                    wp_enqueue_script(    $iCustomScript->attributes->getNamedItem("name")->nodeValue );
                }
                else
                {
                    $ScriptName = $iCustomScript->attributes->getNamedItem("name")->nodeValue;
                    $ScriptSrc = $iCustomScript->attributes->getNamedItem("src")->nodeValue;
                    echo("<script type='text/javascript' src='$ScriptSrc'></script>");  // Note: Wordpress also appends a ?ver=xx to the end of src (and encodes href with esc_attr)
                }
            }
        }

        /// <summary>
        /// Extract all the standard script names from $xmlScriptData (with dependencies?) into a flat array( stdScriptName ).
        /// </summary>
        function ExtractStandardScripts( $xmlScriptData, $bWithDependencies )
        {
            // ** Setup
            $result = array();
            global $wp_scripts;

            // ** Extract the standard scripts (with dependencies?) from $xmlScriptData
            $standardScripts = $xmlScriptData->getElementsByTagName("standard");
            foreach ($standardScripts AS $iStdScript)
            {
                // * Get all the default Wordpress scripts
                // Note: This is done here for efficiency (if no standard wordpress script files are required, this will never be executed)
                if(!$wp_scripts)
                {
                    $wp_scripts = new WP_Scripts();
                    wp_default_scripts($wp_scripts); 
                }

                // * Get the standard script
                $stdScriptName = $iStdScript->attributes->getNamedItem("name")->nodeValue;
                if ( !array_key_exists($stdScriptName, $wp_scripts->registered) )
		            continue;

                // * Get the dependencies
                if( $bWithDependencies )
                    $this->AppendStandardScriptDependencies( $stdScriptName, $wp_scripts, $result );

                // * Add to result
                if( !in_array( $stdScriptName, $result ) )
                    $result[] = $stdScriptName;
            }

            return $result;
        }

        /// <summary>
        /// Recursively checks the dependencies of $strScriptName and adds any dependencies to $arrayAllScripts
        /// </summary>
        function AppendStandardScriptDependencies( $strScriptName, $wp_scripts, &$arrayAllScripts )
        {
            // ** Check whether the script exists
            if ( !array_key_exists($strScriptName, $wp_scripts->registered) )
		        return;

            // ** Add any dependencies
            foreach ($wp_scripts->registered[$strScriptName]->deps AS $iDependency)
            {
                // * Already accounted for
                if( in_array( $iDependency, $arrayAllScripts ) )
                    continue;

                // * First add of of THIS scripts dependencies
                $this->AppendStandardScriptDependencies( $iDependency, $wp_scripts, $arrayAllScripts );

                // * Now add the dependency itself
                if( !in_array( $iDependency, $arrayAllScripts ) )
                    $arrayAllScripts[] = $iDependency;
            }
        }

        /// <summary>
        /// Outputs the notification response XML
        /// </summary>
        /// <remarks>
        /// $strXmlResponse - This is the response of the notification and must be valid XML, including plain text.
        /// </remarks>
        function OutputCrankyAdsNotificationsResponse( $strNotificationName, $arrNotificationValues, $strXmlResponse )
        {
            header("Content-Type: text/xml");

            echo '<?xml version="1.0" encoding="UTF-8" ?>'."\r\n"; ?>
            <CrankyAdsNotificationResponse>
                <PluginVersion><?php echo(CRANKY_ADS_PLUGIN_VERSION) ?></PluginVersion>
                <Notification><?php echo($strNotificationName) ?></Notification>
                <Values>

                    <?php foreach($arrNotificationValues as $value)
                          {  ?><Value><?php echo($value) ?></Value>
                    <?php }  ?>

                </Values>
                <Response><?php echo($strXmlResponse) ?></Response>
            </CrankyAdsNotificationResponse><?php
        }

        /// <summary>
        /// Checks for any CrankyAds notification headers in the $httpResponse and send those on to get processed
        /// </summary>
        function HandleCrankyAdsNotificationsFromProxy( $httpResponse )
        {
            // ** No notifications
            if( $httpResponse->hCrankyAdsNotification === false )
                return;

            // ** Handle notifications
            foreach ($httpResponse->hCrankyAdsNotification AS $iNotification)
            {

                // * blog-registered Notification
                //   Special case (legacy format)
                if( $iNotification === "blog-registered" && $httpResponse->hCrankyAdsContentType === "text/blogguid" )
                {
                    $this->HandleCrankyAdsNotification($iNotification, array($httpResponse->Content), false ); 
                }
                // * Standard Notifications
                //   These have the following format:
                //   notificationName[;value]*
                else
                {
                    $values = explode(";",$iNotification);
                    $name = $values[0];
                    array_splice($values, 0, 1);

                    $this->HandleCrankyAdsNotification($name,$values,false);
                }
            }

        }

    }
    
}


    
?>