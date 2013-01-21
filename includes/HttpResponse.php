<?php

include_once dirname(__FILE__).'/settings.php';
include_once dirname(__FILE__).'/Helper.php';

if (!class_exists("CrankyAdsHttpResponse"))
{
    /// <summary>
    /// A class used to encapsulate a HTTP Response (for use by the CrankyAdsBrowser)
    /// </summary>
    class CrankyAdsHttpResponse
    {
        /// <summary>Server Content Url placeholders</summary>
        /// <remarks>These placeholders are sent by the server to allow the plugin to rewrite the urls to point to itself</remarks>
        var $CA_SERVER_CONTENT_URL_PLACEHOLDER = "%%cranky_ads_plugin_content_url%%";

        /// <summary>Server Action Url placeholders (used for links and form actions)</summary>
        /// <remarks>These placeholders are sent by the server to allow the plugin to rewrite the urls to point to itself</remarks>
        var $CA_SERVER_ACTION_URL_PLACEHOLDER = "%%cranky_ads_plugin_action_url%%";


        /// <summary>Instance of the DataContext used to persist and retrieve values</summary>
        var $DataContext = null;



        /// <summary>Whether there was an error creating this HttpResponse</summary>
        var $bError=true;

        /// <summary>The raw response used to construct this CrankyAdsHttpResponse</summary>
        var $strRawResponse=false;



        /// <summary>The integer Http Response code: 200, 301, 302 ...</summary>
        var $HttpResponseCode=false;

        /// <summary>The full Http response header i.e. Http/1.1 302 Moved Temporarily</summary>
        var $HttpResponseHeader=false;

        /// <summary>Collection of all Http headers. Use array_key_exists(Headers,"headerToTest") to check whether the header exists.</summary>
        /// <remarks>This collection is an array of arrays since the same header can occur multiple times with different values (i.e. $Headers["X-Powered-By"][]="Apache")</remarks>
        var $Headers=array();

        /// <summary>The response content as a string.</summary>
        var $Content="";



        /// <summary>(Special)The value of the "Location: " header if specified or false otherwise</summary>
        var $hLocation=false;

        /// <summary>(Special)The value of all "Set-Cookie: " headers as an array() if specified or false otherwise.</summary>
        /// <remarks> See GenerateSetCookiesJavascript() </remarks>
        var $hSetCookie=false;

        /// <summary>(Special)The value of the "Content-Type: " header if specified or false otherwise. If specified then the strtolower() value is set</summary>
        var $hContentType=false;

        /// <summary>(Special)The value of the "Last-Modified: " header if specified or false otherwise.</summary>
        var $hLastModified=false;

        /// <summary>(Special)The value of the "ETag: " header if specified or false otherwise.</summary>
        var $hETag=false;

        /// <summary>(Special)The value of the "Date: " header if specified or false otherwise.</summary>
        var $hDate=false;

        /// <summary>(Special)The value of the "X-CrankyAds-Content-Type: " header if specified or false otherwise. If specified then the strtolower() value is set</summary>
        var $hCrankyAdsContentType=false;

        /// <summary>(Special)The value of the "X-CrankyAds-Notification: " header if specified or false otherwise. If specified then the strtolower() value is set</summary>
        var $hCrankyAdsNotification=false;



        /// <summary>(Cache)Whether this HttpResponse originated from the cache. If false then the additional cache properties are invalid.</summary>
        var $cIsFromCache=false;

        /// <summary>(Cache)Whether this cached HttpResponse has already timed out</summary>
        var $cIsTimedOut=false;

        /// <summary>(Cache)If $cIsTimedOut then this value will contain the number of seconds since the entry has timed out</summary>
        var $cSecondsSinceTimeout=false;

        /// <summary>(Cache)The headers to pass along to the server in order to renew the cache (i.e. If-Modified-Since and If-None-Match)</summary>
        var $cRenewHeaders=false;

        /// <summary>(Cache)The DataContext cache entry used to construct this HttpResponse</summary>
        var $cCacheEntry=false;


        /// <summary>
        /// Constructor - creates a new HttpResponse
        /// </summary>
        /// <remarks>
        /// . If $strRawResponse is specified then InitFromHttpResponseString(..) is automatically called
        /// . If no $strRawResponse is specified then this HttpResponse should be initialized by a call to one of the Init(..) methods
        /// . Make sure to check for $this->bError 
        /// </remarks>
    	function CrankyAdsHttpResponse( $dataContext, $strRawResponse = false )
    	{
            // Init
            $this->DataContext = $dataContext;

            // Parse
            if($strRawResponse !== false)
                $this->bError = !$this->InitFromHttpResponseString( $strRawResponse );
            else
                $this->bError = false;
    	}

        /// <summary>
        /// Initialize this HttpResponse from the supplied values.
        /// </summary>
        /// <remarks>
        /// . $arrHeaders - This collection is an array of arrays since the same header can occur multiple times with different values (i.e. $Headers["X-Powered-By"][]="Apache")
        /// . This method should NOT be called if the constructor was passed a $strRawResponse
        /// . This method always succeeds.
        /// </remarks>
        function Init( $intResponseCode=200, $arrHeaders=array(), $strContent="" )
        {
            $this->SetHttpResponseCode($intResponseCode);
            $this->Headers = $arrHeaders;
            $this->Content = $strContent;

            $this->ProcessSpecialHeaders();
        }

        /// <summary>
        /// Initialize this HttpResponse by parsing the raw http response string. Returns whether the operation completed successfully.
        /// </summary>
        /// <remarks>
        /// This method is automatically called by the constructor if a $strRawResponse is supplied
        /// </remarks>
    	function InitFromHttpResponseString( $strRawResponse )
    	{
            // ** Init
            $this->strRawResponse = $strRawResponse;
            $headerLines = false;
            $intHeaderCheckOffset = 0;

            // ** Split Header/Content at the first non 100 status code header
            // Note: Http will send multiple headers, the header "HTTP/1.1 100 Continue" means the request should continue. Following is a new header.
            while ($this->HttpResponseCode === false || $this->HttpResponseCode === 100 )
            {
                // * Get the separation between header and content and split
                $intEndHeader = strpos( $strRawResponse , "\r\n\r\n", $intHeaderCheckOffset );

                if($intEndHeader < 0 )
                    return false;

                // * Split the response
                $headerBlock = substr( $strRawResponse , $intHeaderCheckOffset , $intEndHeader - $intHeaderCheckOffset );
                $this->Content = substr( $strRawResponse, $intEndHeader+4 );

                // * Split the header
                $headerLines = explode("\r\n",$headerBlock);

                // * Read the response code (first part of the header)
                $this->HttpResponseHeader = $headerLines[0];
                $responseCodeParts = explode(" ",$headerLines[0]);
                $this->HttpResponseCode = intval($responseCodeParts[1]);

                // * On the next pass (if this is not the final header) then start just past the last header
                $intHeaderCheckOffset = $intEndHeader + 4;
            }

            // ** Read all the headers
            for ($i = 1; $i < count($headerLines); $i++) 
            {
                $separatorPos = strpos( $headerLines[$i], ":" );
                $headerParts = array();
                $headerParts[0] = substr($headerLines[$i],0,$separatorPos);
                $headerParts[1] = substr($headerLines[$i],$separatorPos+1);

                $headerKey = trim($headerParts[0]);
                $headerValue = trim($headerParts[1]);

                $this->Headers[$headerKey][] = $headerValue;
            }
            
            $this->ProcessSpecialHeaders();
            
            // * Success
            return true;
    	}
   	

        /// <summary>
        /// Copies the standard HTTP header to the current response:
        /// . Content-Type (only if not text/html)
        /// . Last-Modified
        /// . ETag
        /// . Date
        ///
        /// It does NOT include:
        /// . Location
        /// </summary>
        function CopyStdHttpHeadersToResponse()
        {
            // ** Content-Type 
            // But don't set if content-type is "text/html" since this is default (and can cause an error if this text/html is returned as part of a bigger page)
            if( $this->hContentType !== false && strpos( $this->hContentType, "text/html" ) === false )
                header("Content-Type: " . $this->hContentType);

            // ** Last-Modified
            if( $this->hLastModified !== false )
                header("Last-Modified: " . $this->hLastModified);

            // ** ETag
            if( $this->hETag !== false )
                header("ETag: " . $this->hETag);

            // ** Date
            if( $this->hDate !== false )
                header("Date: " . $this->hDate);

            // ** Location
            // Ignore Location for now, since this is almost always relative to the server and NOT to the client
            //if( $this->hLocation !== false )
                //header("Location: " . $this->hLocation );

            // ** Cookies
            $this->CopySetCookieHeadersToResponse();
        }

        /// <summary>
        /// Copies the "set-cookie" headers to the current response
        /// </summary>
        function CopySetCookieHeadersToResponse()
        {
            if($this->hSetCookie !== false)
            {
                foreach ($this->hSetCookie as $iCookie) 
                {
                    header("Set-Cookie: " . $iCookie );
                }
            }
        }

        /// <summary>
        /// Returns a new cookie string comprising of the current $strCookie plus all the cookie values in the "set-cookie" headers
        /// </summary>
        function AppendSetCookieHeadersToCookieString( $strCookie = false )
        {

            // We have new cookies to contribute
            if($this->hSetCookie !== false)
            {
                // ** Break up $strCookie
                if($strCookie === false)
                    $cookieArray = array();
                else
                    $cookieArray = explode(";",$strCookie);

                // ** Add all Set-Cookie values
                foreach ($this->hSetCookie as $iCookie) 
                {
                    $parts = explode(";",$iCookie);
                    $cookieArray[] = $parts[0]; // Just get the first part of the set cookie statement: [name=]value
                }

                // ** Merge all named and unnamed cookies
                // Note: We do this so that new cookies set in $this->hSetCookie overwrite cookies stored in $strCookie
                $strCookie = false;
                $namedCookies = array();
                foreach ($cookieArray as $iCookie)
                {
                    $parts = explode("=",$iCookie);

                    if( count($parts) > 1 )
                    {
                        $namedCookies[trim($parts[0])] = trim($parts[1]);
                    }
                    else
                    {
                        if($strCookie === false )
                            $strCookie = "";
                        else
                            $strCookie .= "; ";

                        $strCookie .= trim($parts[0]);
                    }
                }

                foreach ($namedCookies as $key=>$value)
                {
                    if($strCookie === false )
                        $strCookie = "";
                    else
                        $strCookie .= "; ";

                    $strCookie .= $key . "=" . $value;
                }
            }

            return $strCookie;
        }
        
        /// <summary>
        /// Copies $Content to the current response (with / without the standard headers)
        /// </summary>
        function CopyToResponse( $bIncludeStdHeaders = true, $bIncludeHttpResultCode = false )
        {
            if($bIncludeStdHeaders)
                $this->CopyStdHttpHeadersToResponse();
            if($bIncludeHttpResultCode && $this->HttpResponseHeader !== false)
                header($this->HttpResponseHeader);
            echo($this->Content);
        }

        /// <summary>
        /// Returns the javascript required to set the cookies locally from the hSetCookie array (or "" if hSetCookies is false)
        /// </summary>
        function GenerateSetCookiesJavascript()
        {
            if($this->hSetCookie === false)
                return "";


            $result = "<script type='text/javascript'>\r\n";
            foreach ($this->hSetCookie as $iCookie) 
            {
                $result .= "    document.cookie = '" . str_replace ( "'" , "\\'" , $iCookie ) . "';\r\n";
            }
            $result .= "</script>";

            return $result;
        }

        /// <summary>
        /// Replaces all content url placeholders with direct links to the cached file for that content.
        /// This method must be called BEFORE ReplaceContentUrlPlaceholders()
        /// </summary>
        /// <remarks>
        /// . This method considers only "content" cache items which have an associated file cache
        /// . This method ignores any timed out cache
        /// </remarks>
    	function LinkContentUrlPlaceholdersToCache()
        {
            if( CRANKY_ADS_DISABLE_CACHE )
                return;

            // ** Setup
            $placeholder = $this->CA_SERVER_CONTENT_URL_PLACEHOLDER . "?serverurl=";
            $placeholderSize = strlen($placeholder);

            $nextPlaceholderPos = strpos($this->Content,$placeholder);

            // ** Replace - We have at least 1 placeholder to check
            if( $nextPlaceholderPos !== false )
            {
                // * Get the applicable cache entries
                $cacheEntriesSrc = $this->DataContext->GetCacheEntriesByType( "content", true );
                $cacheEntries = array();
                $maxCacheUrlSize = 0;

                // Filter the cache entries
                foreach ( $cacheEntriesSrc as $key => $value) 
                {
                    // Only cache entries that have a file
                    if( !isset($value->dataFilename) && strlen($value->dataFilename) == 0 )
                        continue;

                    // Clean the url, ready for comparing
                    $value->url = strtolower(urlencode($value->url));

                    // Save
                    $maxCacheUrlSize = max(strlen($value->url),$maxCacheUrlSize);
                    $cacheEntries[] = $value;
                }

                // No cache entries to replace
                if( count($cacheEntries) == 0 )
                    return;
                
                // * Replace Content
                while( $nextPlaceholderPos !== false )
                {
                    // Extract the potential url from content
                    $contentString = strtolower(substr($this->Content, $nextPlaceholderPos + $placeholderSize, $maxCacheUrlSize));

                    // Compare the content string against all cache strings
                    $foundCache = false;
                    foreach( $cacheEntries as $iCacheEntry )
                    {
                        if( strpos($contentString, $iCacheEntry->url) === 0 )
                        {
                            $foundCache = $iCacheEntry;
                            break;
                        }
                    }

                    // Replace
                    if( $foundCache !== false )
                    {
                        $replaceSize = $placeholderSize + strlen($foundCache->url);
                        $replaceWith = plugins_url( 'includes/cachedata/'.$foundCache->dataFilename , dirname(__FILE__) );

                        $this->Content = substr($this->Content,0,$nextPlaceholderPos) .
                                         $replaceWith .
                                         substr($this->Content,$nextPlaceholderPos+$placeholderSize+strlen($foundCache->url));

                        $nextPlaceholderPos = strpos($this->Content,$placeholder,$nextPlaceholderPos + strlen($replaceWith));
                    }
                    // Move on
                    else
                    {
                        $nextPlaceholderPos = strpos($this->Content,$placeholder,$nextPlaceholderPos + $placeholderSize);
                    }
                }
            }
        }

        /// <summary>
        /// Replaces all placeholders in content if content-type is "text/.."
        /// </summary>
        /// <remarks>If $strLocalActionProxyUrl is false then the current URL will be used</remarks>
    	function ReplaceAllContentPlaceholders($strLocalActionProxyUrl=false)
        {
            if( $this->hContentType !== false && strpos( $this->hContentType, "text/" ) !== false )
            {
                $this->ReplaceContentUrlPlaceholders();
                $this->ReplaceActionUrlPlaceholders($strLocalActionProxyUrl);
                $this->ReplaceWordpressUrlPlaceholders();
                $this->ReplaceWordpressDataPlaceholders();
            }
        }

        /// <summary>
        /// Replaces all instances of $CA_SERVER_CONTENT_URL_PLACEHOLDER in Content with references to the Wordpress AJAX call "crankyads_servecontent"
        /// </summary>
    	function ReplaceContentUrlPlaceholders()
        {
            $serveContentUrl = admin_url( 'admin-ajax.php?action=crankyads_servecontent' );

            $this->ReplaceUrlPlaceholder($this->CA_SERVER_CONTENT_URL_PLACEHOLDER, $serveContentUrl);
        }

        /// <summary>
        /// Replaces all instances of $CA_SERVER_ACTION_URL_PLACEHOLDER in Content with references to $strLocalActionProxyUrl
        /// </summary>
        /// <remarks>If $strLocalActionProxyUrl is false then the current URI will be used (minus the serverurl querystring parameter)</remarks>
    	function ReplaceActionUrlPlaceholders( $strLocalActionProxyUrl=false )
        {
            // Insert the current Url
            if( $strLocalActionProxyUrl === false )
            {
                $strLocalActionProxyUrl = CrankyAdsHelper::GetFullRequestUri("serverurl");
            }

            $this->ReplaceUrlPlaceholder($this->CA_SERVER_ACTION_URL_PLACEHOLDER,$strLocalActionProxyUrl);
        }

        /// <summary>
        /// Replaces all instances of Wordpress Urls in Content with references to the actual Wordpress Urls
        /// </summary>
    	function ReplaceWordpressUrlPlaceholders()
        {
            // Advertise Here Url
            if( isset($this->DataContext) )
            {
                $this->ReplaceUrlPlaceholder("%%wp_cranky_ads_advertise_here_url%%",$this->DataContext->GetAdvertiseHerePageUrl());
            }

            // Upgrade Plugin Url
            $this->ReplaceUrlPlaceholder("%%wp_cranky_ads_upgrade_plugin_url%%",wp_nonce_url( admin_url('update.php?action=upgrade-plugin&plugin=') . CRANKY_ADS_PLUGIN_FILE_RELATIVE, 'upgrade-plugin_' . CRANKY_ADS_PLUGIN_FILE_RELATIVE));
        }

        /// <summary>
        /// Replaces all instances of %%wp_xx%% in Content with the corresponding Wordpress data. The following Wordpress data is substituted:
        /// %%wp_siteurl%% => site_url()
        /// %%wp_advertise_here_page_id%% => Advertise Here Page Id
        /// %%wp_crankyads_option_OPTION_NAME%%
        /// </summary>
    	function ReplaceWordpressDataPlaceholders()
        {
            $this->Content = str_replace("%%wp_siteurl%%", site_url(), $this->Content);
            $this->Content = str_replace("%%wp_advertise_here_page_id%%", $this->DataContext->GetAdvertiseHerePageId(), $this->Content);
            $this->ReplaceWpDCOptionPlaceholders();
        }

        // ==========================================================================================================
        //                                                Helper Methods
        // ==========================================================================================================

        /// <summary>
        /// Replaces all instances of %%wp_crankyads_option_OPTION_NAME%% with the corresponding CrankyAds DataContext Option Value (html encoded)
        /// </summary>
    	function ReplaceWpDCOptionPlaceholders() // ReplaceWordpressDataContextOptionPlaceholders <- too long
        {
            $optionStart = strpos($this->Content, "%%wp_crankyads_option_");

            // Replace all option placeholders
            while( $optionStart !== false )
            {
                // Find the placeholder bounds
                $optionEnd = strpos($this->Content, "%%",$optionStart+22);
                if($optionEnd === false)
                    break;
                
                // Get the option value
                $optionName = substr($this->Content, $optionStart+22, $optionEnd - $optionStart - 22);
                $optionValue = $this->DataContext->GetOption($optionName);
                if($optionValue === false)
                    $optionValue = "";

                // Replace this option placeholder
                $this->Content = str_replace("%%wp_crankyads_option_$optionName%%", htmlspecialchars($optionValue), $this->Content);



                // Find the next placeholder
                $optionStart = strpos($this->Content, "%%wp_crankyads_option_");
            }
        }

        /// <summary>
        /// Replaces all instances of $strPlaceholderUrl in Content with references to $strActualUrl
        /// </summary>
    	function ReplaceUrlPlaceholder( $strPlaceholderUrl, $strActualUrl )
        {
            // $strActualUrl contains a ?
            if( strpos( $strActualUrl , "?" ) !== false )
            {
                $this->Content = str_replace($strPlaceholderUrl."?", $strActualUrl."&", $this->Content);
                $this->Content = str_replace($strPlaceholderUrl, $strActualUrl, $this->Content);
            }
            else
            {
                $this->Content = str_replace($strPlaceholderUrl, $strActualUrl, $this->Content);
            }
        }

        /// <summary>
        /// Iterate through all headers in $this->Headers and set all special $this->h.. properties appropriately
        /// </summary>
        function ProcessSpecialHeaders()
        {
            foreach( $this->Headers as $headerKey => $headerValues )
            {
                $headerKey = strtolower($headerKey);

                foreach( $headerValues as $headerValue )
                {
                    // Save special header properties
                    if($headerKey == "location")
                        $this->hLocation = $headerValue;
                    if($headerKey == "set-cookie")
                    {
                        if( $this->hSetCookie === false)
                            $this->hSetCookie = array();
                        $this->hSetCookie[] = $headerValue;
                    }
                    if($headerKey == "content-type")
                        $this->hContentType = strtolower($headerValue);
                    if($headerKey == "x-crankyads-content-type")
                        $this->hCrankyAdsContentType = strtolower($headerValue);
                    if($headerKey == "x-crankyads-notification")
                    {
                        if( $this->hCrankyAdsNotification === false )
                            $this->hCrankyAdsNotification = array();
                        $this->hCrankyAdsNotification[] = strtolower($headerValue);
                    }

                    if($headerKey == "last-modified")
                        $this->hLastModified = $headerValue;
                    if($headerKey == "etag")
                        $this->hETag = $headerValue;
                    if($headerKey == "date")
                        $this->hDate = $headerValue;
                }
            }
        }

        /// <summary>
        /// Sets the values of HttpResponseCode and HttpResponseHeader appropriately based on the response code
        /// </summary>
        function SetHttpResponseCode( $intResponseCode )
        {
            $this->HttpResponseCode = $intResponseCode;
            $this->HttpResponseHeader = "HTTP/1.1 " . $this->HttpResponseCode . " ";
            $explanation = "";

            switch( $intResponseCode )
            {
                case 100:
                    $explanation = "Continue";
                    break;
                case 101:
                    $explanation = "Switching Protocols";
                    break;
                case 102:
                    $explanation = "Processing";
                    break;
                case 103:
                    $explanation = "Checkpoint";
                    break;
                case 122:
                    $explanation = "Request-URI too long";
                    break;
                case 200:
                    $explanation = "OK";
                    break;
                case 201:
                    $explanation = "Created";
                    break;
                case 202:
                    $explanation = "Accepted";
                    break;
                case 203:
                    $explanation = "Non-Authoritative Information";
                    break;
                case 204:
                    $explanation = "No Content";
                    break;
                case 205:
                    $explanation = "Reset Content";
                    break;
                case 206:
                    $explanation = "Partial Content";
                    break;
                case 207:
                    $explanation = "Multi-Status";
                    break;
                case 226:
                    $explanation = "IM Used";
                    break;
                case 300:
                    $explanation = "Multiple Choices";
                    break;
                case 301:
                    $explanation = "Moved Permanently";
                    break;
                case 302:
                    $explanation = "Found";
                    break;
                case 303:
                    $explanation = "See Other";
                    break;
                case 304:
                    $explanation = "Not Modified";
                    break;
                case 305:
                    $explanation = "Use Proxy";
                    break;
                case 306:
                    $explanation = "Switch Proxy";
                    break;
                case 307:
                    $explanation = "Temporary Redirect";
                    break;
                case 308:
                    $explanation = "Resume Incomplete";
                    break;
                case 400:
                    $explanation = "Bad Request";
                    break;
                case 401:
                    $explanation = "Unauthorized";
                    break;
                case 402:
                    $explanation = "Payment Required";
                    break;
                case 403:
                    $explanation = "Forbidden";
                    break;
                case 404:
                    $explanation = "Not Found";
                    break;
                case 405:
                    $explanation = "Method Not Allowed";
                    break;
                case 406:
                    $explanation = "Not Acceptable";
                    break;
                case 407:
                    $explanation = "Proxy Authentication Required";
                    break;
                case 408:
                    $explanation = "Request Timeout";
                    break;
                case 409:
                    $explanation = "Conflict";
                    break;
                case 410:
                    $explanation = "Gone";
                    break;
                case 411:
                    $explanation = "Length Required";
                    break;
                case 412:
                    $explanation = "Precondition Failed";
                    break;
                case 413:
                    $explanation = "Request Entity Too Large";
                    break;
                case 414:
                    $explanation = "Request-URI Too Long";
                    break;
                case 415:
                    $explanation = "Unsupported Media Type";
                    break;
                case 416:
                    $explanation = "Requested Range Not Satisfiable";
                    break;
                case 417:
                    $explanation = "Expectation Failed";
                    break;
                case 422:
                    $explanation = "Unprocessable Entity";
                    break;
                case 423:
                    $explanation = "Locked";
                    break;
                case 424:
                    $explanation = "Failed Dependency";
                    break;
                case 425:
                    $explanation = "Unordered Collection";
                    break;
                case 426:
                    $explanation = "Upgrade Required";
                    break;
                case 444:
                    $explanation = "No Response";
                    break;
                case 449:
                    $explanation = "Retry With";
                    break;
                case 450:
                    $explanation = "Blocked by Windows Parental Controls";
                    break;
                case 499:
                    $explanation = "Client Closed Request";
                    break;
                case 500:
                    $explanation = "Internal Server Error";
                    break;
                case 501:
                    $explanation = "Not Implemented";
                    break;
                case 502:
                    $explanation = "Bad Gateway";
                    break;
                case 503:
                    $explanation = "Service Unavailable";
                    break;
                case 504:
                    $explanation = "Gateway Timeout";
                    break;
                case 505:
                    $explanation = "HTTP Version Not Supported";
                    break;
                case 506:
                    $explanation = "Variant Also Negotiates";
                    break;
                case 507:
                    $explanation = "Insufficient Storage";
                    break;
                case 509:
                    $explanation = "Bandwidth Limit Exceeded";
                    break;
                case 510:
                    $explanation = "Not Extended";
                    break;
            }

            $this->HttpResponseHeader .= $explanation;
        }
    }
    
}


	
?>