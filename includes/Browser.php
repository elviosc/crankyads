<?php

include_once dirname(__FILE__).'/settings.php';
include_once dirname(__FILE__).'/HttpResponse.php';

if (!class_exists("CrankyAdsBrowser"))
{
    /// <summary>
    /// Class used as a browser to make HTTP requests
    /// </summary>
    /// <remarks>For security this browser can only be used to make requests to the CrankyAds server</remarks>
    class CrankyAdsBrowser
    {
        /// <summary>Whether the browser calls should be routed through to the Fiddler development proxy (for development only)</summary>
        var $DEBUG_ROUTE_THROUGH_FIDDLER = false;
        
        /// <summary>Instance of the DataContext used to persist and retrieve values</summary>
        var $DataContext = null;

        /// <summary>The last error number if an error occurs in the browser</summary>
        var $lastErrorNumber=0;

        /// <summary>The last error description if an error occurs in the browser</summary>
        var $lastErrorString="";

        /// <summary>The milliseconds at which a timeout occurs</summary>
        var $timeoutMilliseconds = 120000; // 2 minutes

        /// <summary>Whether to convert post data key '_' values to '.'</summary>
        /// <remarks>This is required because of the (default) use of periods in MVC and the default conversion of periods to underscores by PHP</remarks>
        var $bPostDataConvertUnderscoreToPeriod=true;


        /// <summary>
        /// Constructor - initialize the Cranky Ads Browser
        /// </summary>
        function CrankyAdsBrowser( $dataContext )
        {
            $this->DataContext = $dataContext;
        }
        

        /// <summary>
        /// Make a request to the specifed Url with the specified data. 
        /// Returns false if there was an error (see lastErrorNumber and lastErrorString) or a CrankyAdsHttpResponse otherwise.
        /// </summary>
        function MakeRequest($bSSL, $strRelativeUrl, $dicPostData=false, $dicPostDataFiles=false, $intAllowedRedirects=3, $dicAdditionalHeaders=false )
        {
            $isPost = is_array($dicPostData);
            $url = "";
            $returnData = "";

            // ** Build the Url
            $url = "http";
            if($bSSL)
                $url .= "s";
            $url .= "://".CRANKY_ADS_DOMAIN;

            if( strlen($strRelativeUrl) <= 0 || strlen($strRelativeUrl) > 0 && $strRelativeUrl[0] != '/' )
                $url .= "/";

            $url .= $strRelativeUrl;

            // ** Make the actual request
            if  (in_array('curl', get_loaded_extensions()))
                $curl_installed = true;
            else
                $curl_installed = false;

            // * Make request with Curl
            if ($curl_installed)
            { 
                // Get cURL version information
                $curlVersion = curl_version();
                $curlVersionParts = explode(".",$curlVersion["version"]);
                $curlVersionId = 10000 * $curlVersionParts[0] + 100 * $curlVersionParts[1] + $curlVersionParts[2];

                // Init curl
                $curl = curl_init($url);
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HEADER, true);
                if( $this->timeoutMilliseconds > 0 )
                {
                    if( $curlVersionId > 71602) // Only available since cURL 7.16.2
                    {
                        curl_setopt($curl, CURLOPT_TIMEOUT_MS, $this->timeoutMilliseconds);
                    }
                }

                // Development: Fiddler proxy to inspect packets sent by the CrankyAds Browser
                if( $this->DEBUG_ROUTE_THROUGH_FIDDLER )
                {
                    curl_setopt($curl, CURLOPT_PROXY, "127.0.0.1:8888");
                    curl_setopt($curl, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                }

                // Build additional custom headers array (set in cURL further down)
                $dicAdditionalCurlHeaders=array();
                if( is_array($dicAdditionalHeaders) )
                {
                    foreach($dicAdditionalHeaders as $key=>$value) 
                    {    
                        $dicAdditionalCurlHeaders[] = $key.": ".$value;
                    } 
                }

                // Set post data
                // Note: This has to be done before setting additional headers since we need to add the explicit Content-Type
                $contentType = false;
                if ($isPost)
                {
                    curl_setopt($curl, CURLOPT_POST, true);

                    $postdata = $this->BuildPostData( $dicPostData, $dicPostDataFiles, $contentType );
                    $dicAdditionalCurlHeaders[] = "Content-Type: ".$contentType; // Add an explicit Content-Type header

                    curl_setopt($curl, CURLOPT_POSTFIELDS, $postdata);
                }
                else
                {
                    curl_setopt($curl, CURLOPT_POST, false);
                }

                // Set headers
                // Note: This has to be done here since we may add more headers after building the initial $dicAdditionalCurlHeaders array (like 'Content-Type')
                if( count($dicAdditionalCurlHeaders) > 0 )
                {
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $dicAdditionalCurlHeaders);
                }

                // Setup redirects
                if($intAllowedRedirects > 0)
                {
                    //curl_setopt($curl, CURLOPT_MAXREDIRS, $intAllowedRedirects);
                    //curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // We're going to handle this manually because cURL returns ALL headers with the response upon a redirect
                }
                else
                {
                    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
                }

                
                // EXECUTE
                $returnData = curl_exec ($curl);
                if (!$returnData) 
                { 
                    $this->lastErrorNumber = -1;
                    $this->lastErrorString = "ERROR: Did not receive data using CURL";

                    return false;
                }
                curl_close ($curl);

                // Manually handle redirects
                if( $intAllowedRedirects > 0 )
                {
                    $response = new CrankyAdsHttpResponse( $this->DataContext, $returnData );

                    if($response->hLocation !== false && ($response->HttpResponseCode === 301 || $response->HttpResponseCode === 302) )
                    {
                        return $this->MakeRequest($bSSL, $response->hLocation, $dicPostData, $dicPostDataFiles, $intAllowedRedirects-1, $dicAdditionalHeaders );
                    }
                }

             }
             // * Make request with raw socket read / write
             else
             {            
                // Open the raw socket
                $port = 80;
                if($bIsSSL)
                    $port = 443;

                // Init connection parameters
                $socketTarget = CRANKY_ADS_DOMAIN;
                $socketPort = $port;

                // For Development: Connect through fiddler proxy so we can inspect the packet
                if( $this->DEBUG_ROUTE_THROUGH_FIDDLER )
                {
                    $socketTarget = "127.0.0.1";
                    $socketPort = "8888";
                }

                if( $this->timeoutMilliseconds > 0 )
                    $fp = @fsockopen($socketTarget, $socketPort, $errno, $errstr, $this->timeoutMilliseconds/1000.0);
                else
                    $fp = @fsockopen($socketTarget, $socketPort, $errno, $errstr);

                if(!$fp) 
                {
                    $this->lastErrorNumber = $errno;
                    $this->lastErrorString = $errstr;
                    return false;
                }
                if( $this->timeoutMilliseconds > 0 )
                    stream_set_timeout( $fp , 0, $this->timeoutMilliseconds * 1000.0 );
            
                // Build the post data (if any)
                $postdata = "";
                $contentType = "";
            
                if ($isPost)
                {
                    $postdata = $this->BuildPostData( $dicPostData, $dicPostDataFiles, $contentType );
                }

                // Build the raw HTTP packet
                $packet = "";
                if($isPost)
                    $packet .= "POST";
                else
                    $packet .= "GET";
                $packet .= " ".$url." HTTP/1.0\r\n";
                $packet .= "Host: ".CRANKY_ADS_DOMAIN."\r\n";
                if($isPost)
                {
                    $packet .= "Content-type: ".$contentType."\r\n";
                    $packet .= "Content-length: " . strlen($postdata) . "\r\n";
                }
                if( is_array($dicAdditionalHeaders) )
                {
                    foreach($dicAdditionalHeaders as $key=>$value) 
                    {    
                        $packet .= $key.": ".$value."\r\n";
                    } 
                }
                $packet .= "\r\n";
                if($isPost)
                {
                    $packet .= $postdata;
                }

                // Request
                //fputs($fp, $packet);

                $bytesWritten = 0;
                while( strlen($packet) > 0 && $bytesWritten !== false ) // We need to write the packet in bursts. fwrite seems to have a problem with large (>32KB packets)
                {
                    $packet = substr($packet, $bytesWritten);
                    $bytesWritten = fwrite($fp, $packet, min(strlen($packet),1024));
                }


                // Read the response header
                // TODO (446): Refactor the code below to read all the data, construct a HttpResponse object and read its data
                $header .= fgets ( $fp, 1024 ); // Will stop on \n (and include \n) or 1024 bytes (no header should ever be this many bytes)

                // Timeout check
                $streamInfo = stream_get_meta_data($fp);
                if ($streamInfo['timed_out']) {return false;}

                // Check for redirect
                $isRedirect = ( strpos($header,"302") !== false || strpos($header,"301") !== false );

                // loop until the end of the header
                while ( strpos ( $header, "\r\n\r\n" ) === false )
                {
                    $headerLine = fgets ( $fp, 1024 );

                    // Timeout check
                    $streamInfo = stream_get_meta_data($fp);
                    if ($streamInfo['timed_out']) {return false;}

                    // Redirect this call
                    if( $isRedirect && $intAllowedRedirects > 0 && strpos(strtolower($headerLine),"location:") !== false )
                    {
                        return $this->MakeRequest($bSSL, trim(substr($headerLine,9)), $dicPostData, $dicPostDataFiles, $intAllowedRedirects - 1, $dicAdditionalHeaders );
                    }

                    $header .= $headerLine;
                }
                $returnData = $header;
            
                // Read the response data
                while(!feof($fp)) 
                {
                    $returnData .= fgets($fp);

                    // Timeout check
                    $streamInfo = stream_get_meta_data($fp);
                    if ($streamInfo['timed_out']) {return false;}
                }

                fclose($fp);
           
            
             }     
         
    
            $finalResult = new CrankyAdsHttpResponse( $this->DataContext, $returnData );
            if($finalResult->bError)
                return false;
            else
                return $finalResult;
        }

        /// <summary>
        /// Builds the raw post data string from the specified data
        /// </summary>
        function BuildPostData( $dicPostData, $dicFiles, &$contentType )
        {
            $isMultipart = is_array($dicFiles) && count($dicFiles) > 0;
            $result = "";

            // ** Multipart post
            if($isMultipart)
            {
                $boundary = "----CrankyAdsBrowserBoundary2a84c2ab17e842b590ca5a0e43e756fa";
                $contentType = "multipart/form-data; boundary=".$boundary;

                // Write post data (in multipart format)
                if(is_array($dicPostData))
                {
                    foreach( $dicPostData as $key=>$value )
                    {
                        if( $this->bPostDataConvertUnderscoreToPeriod )
                            $key = str_replace ( "_", "." , $key );

                        $result .= CrankyAdsHelper::BuildFormDataMultipart($key, $value, $boundary);

                    }
                }

                // Write files (in multipart format)
                if(is_array($dicFiles))
                {
                    foreach( $dicFiles as $key=>$value )
                    {
                        if( $this->bPostDataConvertUnderscoreToPeriod )
                            $key = str_replace ( "_", "." , $key );

                        if( $value["name"] != null )
                        {
                            $result .= "--".$boundary."\r\n";
                            $result .= "Content-Disposition: form-data; name=\"".$key."\"; filename=\"".$value["name"]."\"\r\n";
                            $result .= "Content-Type: ".$value["type"];
                            $result .= "\r\n\r\n";

                            $fileContent = file_get_contents($value["tmp_name"]);
                            $result .= $fileContent;
                            $result .= "\r\n";
                        }
                    }
                }

                // Terminator
                $result .= "--".$boundary."--\r\n";
            }
            // ** UrlEncoded post
            else
            {
                $contentType = "application/x-www-form-urlencoded";

                foreach($dicPostData as $key=>$value) 
                {    
                    if( $this->bPostDataConvertUnderscoreToPeriod )
                        $key = str_replace ( "_", "." , $key );

                    if(strlen($result) > 0)
                        $result .= "&";

                    $result .= CrankyAdsHelper::BuildFormDataUrlEncoded($key,$value);

                }
            }

            return $result;
        }

    }
    
}


    
?>