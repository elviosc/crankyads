<?php

include_once dirname(__FILE__).'/settings.php';
include_once dirname(__FILE__).'/HttpResponse.php';
include_once dirname(__FILE__).'/Browser.php';

if (!class_exists("CrankyAdsProxy"))
{
    /// <summary>
    /// Class used to get content from the CrankyAds Ad Server
    /// </summary>
    /// <remarks>
    /// This is useful for a number of reasons:
    /// 1. It supports local caching of the server content to make serving ads a lot faster
    /// 2. It overcomes the cross domain problem when using Ajax with the Cranky Ads Server
    /// </remarks>
    class CrankyAdsProxy
    {
        /// <summary>Instance of the DataContext used to persist and retrieve values</summary>
        var $DataContext = null;

        /// <summary>Instance of the Cache used to store content locally</summary>
        var $Cache = null;

        /// <summary>Instance of the Browser used to make calls to the server</summary>
        var $Browser = null;

        /// <summary>Whether to use an SSL connection by default</summary>
        var $DefaultSSL=false;


        /// <summary>
        /// Constructor - initialize the Cranky Ads Browser
        /// </summary>
        function CrankyAdsProxy( $dataContext, $cache )
        {
            $this->DataContext = $dataContext;
            $this->Cache = $cache;
            $this->Browser = new CrankyAdsBrowser( $dataContext );
        }
        
        /// <summary>
        /// Returns the remote content as a CrankyAdsHttpResponse (limited to /Plugin and /Content on the server) or false if an error occurred
        /// </summary>
        /// <remarks>
        /// . $intCacheResultForSeconds 
        ///   . If false no caching will be performed; if 0 the default cache period will be used (see CrankyAdsCache); Otherwise the result will be cached for the specified number of seconds
        ///   . If post data is used to make the call then NO caching is performed.
        ///   . If NOT false and the result has already been cached then the cached result will be returned instead (depending on $flagsCacheBehaviour)
        /// . $flagsCacheBehaviour
        ///   . This flag modifies the default behaviour of the cache, which is:
        ///     1. Check the cache for a non-timedout copy and return that if exists
        ///     2. If cache does not exist or is timed out then get a fresh response from the server; Then update the cache with the fresh response; Then return the fresh response (or false on failure)
        ///   . Only applicable if intCacheResultForSeconds !== false.
        ///   . One or more members of the CrankyAdsCacheBehaviourFlags enumeration. 
        ///   . CrankyAdsCacheBehaviourFlags::DoNotCacheHtml -  This is important if the html is dynamically generated from something OTHER than post data 
        ///   . CrankyAdsCacheBehaviourFlags::UseTimedOutCacheAsFallbackOnServerError
        ///     . If set then the cached result will be returned EVEN if it is timed out in situations where the server returns an invalid response.
        ///     . This flag effectively extends the timeout for cached items when there are problems with the server
        ///     . DoNotCacheHtml takes precedence over this parameter with text/html mime-type content
        /// . $strCachePrefix
        ///   . Will append this prefix to the $strServerRelativeUrl when reading or writing the cache. 
        ///   . This allows the same url to have multiple cache values (i.e. "Content1://" and "Content2://") particularly important for dynamically changing content which should nevertheless be cached
        /// . $strCacheType
        ///   . The (optional) type of this cache. 
        ///   . This is useful to distinguish between different types of cached data.
        /// </remarks>
        function GetRemoteContent($strServerRelativeUrl, $bForwardPostData=true, $bForwardStandardHeaders=true, $intCacheResultForSeconds=false, $intAllowedRedirects=3, $bForwardCookies=false, $bFlagAsPublic=false, $dicAdditionalHeaders=false, $flagsCacheBehaviour=0, $strCachePrefix=false, $strCacheType=false )
        {
            if( CRANKY_ADS_DISABLE_ASYNC_LOADING )
            {
                $flagsCacheBehaviour = $flagsCacheBehaviour & (~CrankyAdsCacheBehaviourFlags::DoNotMakeRequestToServer);
            }

            // ** Get the Blog Guid
            $blog_id = $this->DataContext->GetBlogGuid();
            if( $blog_id === false )
                $blog_id = "none";

            // ** Security checks
            if( strlen($strServerRelativeUrl) <= 0 ) // No url
                return false;

            if($strServerRelativeUrl[0] != "/") // starting /
                $strServerRelativeUrl = "/".$strServerRelativeUrl;

            $checkUrl = strtolower($strServerRelativeUrl);
            if( strpos( $checkUrl, "/content" ) !== 0 && strpos( $checkUrl, "/plugin" ) !== 0 ) // Only /content and /plugin directories
                return false;

            // ** Prepare
            global $wp_version;
            $bForwardPostData = $bForwardPostData && count($_POST) > 0;
            if( $dicAdditionalHeaders !== false )
                $dicHeaders = $dicAdditionalHeaders;
            else
                $dicHeaders = array();

            $dicHeaders["User-Agent"] = "CrankyAdsProxy/".CRANKY_ADS_PLUGIN_VERSION;
            $dicHeaders["X-CrankyAds-Blog-Guid"] = $blog_id;
            $dicHeaders["X-CrankyAds-Plugin-Version"] = CRANKY_ADS_PLUGIN_VERSION;

            if(isset($wp_version))
            {
                $dicHeaders["X-CrankyAds-Wordpress-Version"] = $wp_version;
            }

            if( $bForwardCookies && isset($_SERVER["HTTP_COOKIE"]) )
            {
                // Dev Note:
                // We can forward cookies through javascript using the following code if we are unable to write the cookies directly to the response headers
                // echo $httpResponse->GenerateSetCookiesJavascript();
                $dicHeaders["Cookie"] = stripslashes_deep($_SERVER["HTTP_COOKIE"]);
            }

            if($bFlagAsPublic)
            {
                $dicHeaders["X-CrankyAds-Public-Content-Only"] = "true";
            }

            if($bForwardStandardHeaders)
            {
                $this->AddStandardHttpRequestHeaders($dicHeaders);
            }
            $this->AddReferenceRequestData($dicHeaders);

            // ** Get the content

            // * With Post Data
            if( $bForwardPostData && (is_array($_POST) && count($_POST) > 0 ||  is_array($_FILES) && count($_FILES) > 0) )
            {
                $result = $this->Browser->MakeRequest( $this->DefaultSSL, $strServerRelativeUrl, stripslashes_deep($_POST), $_FILES, $intAllowedRedirects, $dicHeaders ); // TODO: bSSL will need to change to TRUE at some stage (or dynamic based on the request)
            }
            // * With Caching
            else if( $intCacheResultForSeconds !== false )
            {
                $fDoNotCacheHtml = ($flagsCacheBehaviour & CrankyAdsCacheBehaviourFlags::DoNotCacheHtml) > 0;
                $fDoNotMakeRequestToServer = ($flagsCacheBehaviour & CrankyAdsCacheBehaviourFlags::DoNotMakeRequestToServer) > 0;
                $fIgnoreCacheTimeout = ($flagsCacheBehaviour & CrankyAdsCacheBehaviourFlags::IgnoreCacheTimeout) > 0;
                $fUseTimedOutCacheAsFallbackOnServerError = ($flagsCacheBehaviour & CrankyAdsCacheBehaviourFlags::UseTimedOutCacheAsFallbackOnServerError) > 0;
                $fReplaceContentUrlPlaceholdersOnServerResponse = ($flagsCacheBehaviour & CrankyAdsCacheBehaviourFlags::ReplaceContentUrlPlaceholdersOnServerResponse) > 0;
                $cacheUrl = $strServerRelativeUrl;
                if( $strCachePrefix !== false )
                    $cacheUrl = $strCachePrefix . $cacheUrl;

                // Check the cache
                $result = false;
                $cacheResult = $this->Cache->GetCache( $cacheUrl, $dicHeaders, true);
                $isHtmlContent = $cacheResult !== false && strpos( strtolower($cacheResult->hContentType), "text/html" ) !== false;

                // Disallow html
                if( $fDoNotCacheHtml && $isHtmlContent )
                    $cacheResult = false; // As though we didn't find anything in the cache

                // Cache is invalid (no valid cache or timed out)
                if( $cacheResult === false || $cacheResult->cIsTimedOut && !$fIgnoreCacheTimeout )
                {
                    // Renew the internal cache - add 304 complient headers to inform the server about our own cache
                    $dicHeadersWithCacheRenew = $dicHeaders;
                    if( $cacheResult !== false )
                        $dicHeadersWithCacheRenew = array_merge($dicHeaders, $cacheResult->cRenewHeaders ); // This allows the server to pass back a much smaller (304) response instead of the entire content if our cache is still valid

                    // Make the call to the server
                    if( $fDoNotMakeRequestToServer )
                        $result = false;
                    else
                        $result = $this->Browser->MakeRequest( $this->DefaultSSL, $strServerRelativeUrl, false, false, $intAllowedRedirects, $dicHeadersWithCacheRenew ); // TODO: bSSL will need to change to TRUE at some stage (or dynamic based on the request)

                    // Server response - update the cache (if applicable)
                    if( $result !== false )
                    {

                        // Valid content - update cache
                        if( $result->HttpResponseCode == 200 || $result->HttpResponseCode == 304 )
                        {
                            // Check the content type
                            $isHtmlContent = strpos( strtolower($result->hContentType), "text/html" ) !== false;

                            // Content disallowed in cache - Do not cache
                            if( $fDoNotCacheHtml && $isHtmlContent )
                            {
                                // Return the $result from the server, but DO NOT CACHE since Html content is disallowed
                            }
                            // Content allowed in cache
                            else
                            {
                            	if( $fReplaceContentUrlPlaceholdersOnServerResponse )
                            		$result->ReplaceContentUrlPlaceholders();
                            		
                                $this->Cache->UpdateCache( $cacheUrl, $result, $intCacheResultForSeconds, $strCacheType);

                                // Update $result - since the cache was updated we need to get the new $result
                                //
                                // Note:  
                                //   . We need to get this result from the cache again since the call to the server may have returned a different response (based on the headers) than the one we need to pass to the caller
                                //   . If there is a complete cache failure the direct server $result will always just be returned since $cacheResult will always be null
                                //
                                // ATTENTION: 
                                //   . If $cacheResult exists but there was a problem with the UpdateCache(..) call above (i.e. error saving to file or db) then the old $cacheResult will be returned below. 
                                //   . We can't just test if UpdateCache(..) succeeded since the server may have returned a 304 in response to our $cacheResult->cRenewHeaders
                                //   . This should only happen rarely (if ever)
                                if( $cacheResult !== false && count($cacheResult->cRenewHeaders) > 0 )
                                    $result = $this->Cache->GetCache( $cacheUrl, $dicHeaders, true);
                            }
                        }
                        // Valid content - Redirect (do not cache)
                        else if( $result->HttpResponseCode == 301 || $result->HttpResponseCode == 302 )
                        {
                            // Return the redirect $result but do not cache this
                        }
                        // Invalid response (404,500,...) - return a fallback response (if applicable)
                        else
                        {
                            if( $cacheResult !== false && $fUseTimedOutCacheAsFallbackOnServerError )
                                $result = $cacheResult;
                        }
                    }
                    // No server response - return a fallback response (if applicable)
                    else
                    {
                        if( $cacheResult !== false && $fUseTimedOutCacheAsFallbackOnServerError )
                            $result = $cacheResult;
                    }
                }
                // Cached result is valid
                else
                {
                    $result = $cacheResult;
                }
            }
            // * No Caching
            else
            {
                $result = $this->Browser->MakeRequest( $this->DefaultSSL, $strServerRelativeUrl, false, false, $intAllowedRedirects, $dicHeaders ); // TODO: bSSL will need to change to TRUE at some stage (or dynamic based on the request)
            }

            return $result;
        }

        /// <summary>
        /// Returns the remote server action url for the specified controller[/action] combination
        /// </summary>
        function ToServerUrlFromActionUrl($strControllerAction)
        {
            // ** Build the complete server url
            $url = "/Plugin";
            if( strlen( $strControllerAction ) <= 0 || $strControllerAction[0] != '/' )
                $url .= "/";
            $url .= $strControllerAction;

            return $url;
        }

        // ==========================================================================================================
        //                                              Helper Methods
        // ==========================================================================================================

        /// <summary>
        /// Adds standard HTTP headers from the Request to $dicHeaders (if those headers aren't defined). This includes the following headers:
        /// . Cache-Control
        /// . If-Modified-Since
        /// . If-None-Match
        /// </summary>
        function AddStandardHttpRequestHeaders( &$dicHeaders )
        {
            if( isset($_SERVER["HTTP_CACHE_CONTROL"]) && !CrankyAdsHelper::ArrayKeyExistsCaseInsensitive("Cache-Control",$dicHeaders) )
                $dicHeaders["Cache-Control"] = stripslashes_deep($_SERVER["HTTP_CACHE_CONTROL"]);

            if( isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) && !CrankyAdsHelper::ArrayKeyExistsCaseInsensitive("If-Modified-Since",$dicHeaders) )
                $dicHeaders["If-Modified-Since"] = stripslashes_deep($_SERVER["HTTP_IF_MODIFIED_SINCE"]);

            if( isset($_SERVER["HTTP_IF_NONE_MATCH"]) && !CrankyAdsHelper::ArrayKeyExistsCaseInsensitive("If-None-Match",$dicHeaders) )
                $dicHeaders["If-None-Match"] = stripslashes_deep($_SERVER["HTTP_IF_NONE_MATCH"]);
        }

        /// <summary>
        /// Adds the following headers to the array
        /// . X-CrankyAds-Source-IP         - The IP of the original request to the server
        /// . X-CrankyAds-Source-UserAgent  - The UserAgent of the original request to the server
        /// </summary>
        function AddReferenceRequestData( &$dicHeaders )
        {
            if( isset($_SERVER["REMOTE_ADDR"])  )
                $dicHeaders["X-CrankyAds-Source-IP"] = stripslashes_deep($_SERVER["REMOTE_ADDR"]);

            if( isset($_SERVER["HTTP_USER_AGENT"])  )
                $dicHeaders["X-CrankyAds-Source-UserAgent"] = stripslashes_deep($_SERVER["HTTP_USER_AGENT"]);
        }
       
    }
    
}


    
?>