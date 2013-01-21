<?php

include_once dirname(__FILE__).'/settings.php';

if (!class_exists("CrankyAdsHelper"))
{
    /// <summary>
    /// A set of helper methods used throughout the plugin
    /// </summary>
    class CrankyAdsHelper
    {
        /// <summary>
        /// Returns the current request Url (either $_SERVER["REQUEST_URL"] or $_SERVER["URL"] ) or false if not found
        /// </summary>
    	static function GetRequestUrl()
        {
            $result = $_SERVER["REQUEST_URL"];

            if( !isset($result) )
                $result = $_SERVER["URL"];

            if( isset($result) )
                return $result;
            else
                return false;
        }

        /// <summary>
        /// Returns the current request Uri (either $_SERVER["REQUEST_URI"] or $_SERVER["URI"] ) or false if not found
        /// </summary>
    	static function GetRequestUri()
        {
            $result = $_SERVER["REQUEST_URI"];

            if( !isset($result) )
                $result = $_SERVER["URI"];

            if( isset($result) )
                return $result;
            else
                return false;
        }

        /// <summary>
        /// Returns whether the case insensitive $key exists in $array
        /// </summary>
        static function ArrayKeyExistsCaseInsensitive( $key, $array )
        {
            $key = strtolower($key);

            foreach ($array as $name => $value) 
            {
                if( strtolower($name) == $key )
                    return true;
            }

            return false;
        }

        /// <summary>
        /// Returns whether value if $array[$key] where $key is case insensitive. If $key cannot be found then boolean FALSE is returned.
        /// </summary>
        static function ArrayGetCaseInsensitive( $key, $array )
        {
            $key = strtolower($key);

            foreach ($array as $name => $value) 
            {
                if( strtolower($name) == $key )
                    return $value;
            }

            return false;
        }

        /// <summary>
        /// Returns a copy of $array with each key modified by strtolower($key)
        /// </summary>
        static function ArrayKeyToLower( $array )
        {
            $result = array();

            foreach ($array as $key => $value) 
            {
                $result[ strtolower($key) ] = $value;
            }

            return $result;
        }

        /// <summary>
        /// Similar to GetRequestUri() except will return the full URI including scheme and hostname
        /// </summary>
        /// <remarks>
        /// . $stripQuerystringParam may be either a string or an array of strings. Each entry represents a parameter that should be stripped out of the request Uri
        /// </remarks>
    	static function GetFullRequestUri( $stripQuerystringParam = false )
        {
            $result = "";

            // Build the full Uri
            if ( getenv('HTTPS') == 'on' ) 
            {
                $result = 'https://'.$_SERVER['HTTP_HOST'].CrankyAdsHelper::GetRequestUri();
            } 
            else 
            {
                $result = 'http://'.$_SERVER['HTTP_HOST'].CrankyAdsHelper::GetRequestUri();
            }

            // Strip Parameters
            if( $stripQuerystringParam !== false )
            {
                // Array
                if( is_array($stripQuerystringParam) )
                {
                    $newResult = parse_url($result);

                    foreach( $stripQuerystringParam as $paramName )
                    {
                        $newResult['query'] = CrankyAdsHelper::RemoveFromQuerystring($newResult['query'],$paramName);
                    }

                    $result = CrankyAdsHelper::BuildUrlFromParts($newResult);
                }
                // Single parameter
                else
                {
                    $result = CrankyAdsHelper::UrlRemoveFromQuerystring($result,$stripQuerystringParam);
                }
            }

            return $result;
        }

        /// <summary>
        /// Returns the url minus the specified query string parameter
        /// </summary>
        static function UrlRemoveFromQuerystring($url, $queryStringParameter) 
        {
            // Parse the URL
            $newUrl = parse_url($url);

            // No query string
            if(!isset($newUrl['query']))
                return $url;

            // Remove the querystring parameter
            $newUrl['query'] = CrankyAdsHelper::RemoveFromQuerystring($newUrl['query'],$queryStringParameter);
            return CrankyAdsHelper::BuildUrlFromParts($newUrl);
        }

        /// <summary>
        /// Returns the url minus the specified query string parameter
        /// </summary>
        static function UrlAddToQuerystring($url, $queryStringParameter, $queryStringValue, $bReplace=true) 
        {
            $newUrl = parse_url($url);
            $newUrl['query'] = CrankyAdsHelper::AddToQuerystring($newUrl['query'],$queryStringParameter,$queryStringValue,$bReplace);
            return CrankyAdsHelper::BuildUrlFromParts($newUrl);
        }

        /// <summary>
        /// Returns the value of the specified Query String parameter (case insensitive) or false if not found
        /// </summary>
        static function UrlGetQuerystringValue($url, $queryStringParameter) 
        {
            $newUrl = parse_url($url);
            $parsedQueryString = CrankyAdsHelper::ParseQuerystring( isset($newUrl['query'])?$newUrl['query']:"" );
            return CrankyAdsHelper::ArrayGetCaseInsensitive($queryStringParameter,$parsedQueryString);
        }

        /// <summary>
        /// Returns a string version of the url represented by the array ([scheme] => ,[host] => ,[port] => ,[user] =>, [pass] =>, [path] =>, [query] =>, [fragment] => )
        /// </summary>
        /// <remarks>These parts are generated by a call to parse_url(..)</remarks>
        static function BuildUrlFromParts($dicUrlParts) 
        {
            $url = $dicUrlParts['scheme'] . "://";

            if( isset($dicUrlParts['user']) )
            {
                $url .= $dicUrlParts['user'];

                if( isset($dicUrlParts['pass']) )
                    $url .= ":" . $dicUrlParts['pass'];

                $url .= "@";
            }

            $url .= $dicUrlParts['host'];

            if( isset($dicUrlParts['port']) && $dicUrlParts['port'] !== 80 )
                $url .= ":" . $dicUrlParts['port'];

            if( isset($dicUrlParts['path']) )
                $url .= $dicUrlParts['path'];

            if( isset($dicUrlParts['query']) )
                $url .= "?" . $dicUrlParts['query'];

            if( isset($dicUrlParts['fragment']) )
                $url .= "#" . $dicUrlParts['fragment'];


           return $url;
        }

        /// <summary>
        /// Returns the passed in query string minus the specified parameter
        /// </summary>
        /// <remarks>The queryString should NOT contain a leading ? character</remarks>
        static function RemoveFromQuerystring($queryString, $parameter) 
        {
            // Check
            if( $queryString == null || strlen(trim($queryString)) == 0 )
                return null;

            // Prepare
            $parameter = strtolower($parameter);

            // Remove the offending parameter
            $queryString = preg_replace("/(\&|^)$parameter=[^\&#]*/i", '', $queryString);
            $queryString = preg_replace("/\&+/","&",$queryString); // Supposed to pull out all repeating &s

            // Special case, leading &
            if( strlen($queryString) > 0 && $queryString[0] == '&' )
                $queryString = substr($queryString,1);

            // Special case NULL
            if( strlen(trim($queryString)) == 0 )
                $queryString = null;

            return $queryString;
        }

        /// <summary>
        /// Returns the passed in query string plus the specified parameter
        /// </summary>
        /// <remarks>
        /// . The queryString should NOT contain a leading ? character
        /// . $bReplace - if true and $queryString already contains $parameter, 
        ///               then the existing $parameter is first removed before being re-added with $value
        /// </remarks>
        static function AddToQuerystring($queryString, $parameter, $value, $bReplace=true) 
        {
            // Replace - remove existing parameter
            if( $bReplace )
                $queryString = CrankyAdsHelper::RemoveFromQuerystring($queryString,$parameter);

            // Special Case
            if( $queryString == null || strlen(trim($queryString)) == 0 )
                $queryString = "";

            // Append &
            if( strlen($queryString) > 0 )
                $queryString .= "&";

            // Append QueryString
            $queryString .= urlencode($parameter) . "=" . urlencode($value);

            return $queryString;
        }

        /// <summary>
        /// Parse a query string and return an array ($key => $value) where all $values are URL Decoded
        /// </summary>
        static function ParseQuerystring($strQueryString) 
        {
        	if(strlen($strQueryString) == 0)
        		return array();
        	
            $result = array();
    
            // Iterate through all parameters
            $parameters = explode("&",$strQueryString);

            foreach( $parameters as $iParam )
            {
                $pair = explode("=",$iParam);
                $result[$pair[0]] = urldecode($pair[1]);
            }

            return $result;
        }
      

        /// <summary>
        /// Will build url encoded form data as follows:
        /// . $key=$value - if $value is not an array
        /// . $key[$valueKey]=$valuesValue - if $value is an array with an integer key
        /// . $key.$valueKey=$valueValue - if $value is an array with a non-integer key
        ///
        /// . All array values are encoded recursively so array values may contain nested array values (i.e. Prices[0].Region[0]=100)
        /// . All array $values are url encoded into multiple $compositeKey=$stringValue pairs and are combined via & characters (i.e. Prices[0].Region[0]=100&Prices[0].Region[1]=150)
        /// . Final $key and $value values are both passed through urlencoded(..)
        /// </summary>
        static function BuildFormDataUrlEncoded($key, $value)
        {
            $result = "";

            // ** Array $value
            if( is_array($value) )
            {
                // * Create and Encode a composite key for each array value
                foreach($value as $valueKey=>$valueValue) 
                {    
                    // Create the composite key
                    $compositeKey = $key;
                    if (preg_match("/^\d+$/i", $valueKey)) // int Array Key
                        $compositeKey .= "[$valueKey]";
                    else // string Array Key
                        $compositeKey .= ".$valueKey";

                    // Encode this Value
                    if(strlen($result) > 0)
                        $result .= "&";
                    $result .= CrankyAdsHelper::BuildFormDataUrlEncoded($compositeKey,$valueValue);
                }
            }
            // ** Simple $value
            else
            {
                $result .= urlencode($key)."=".urlencode($value);
            }

            return $result;
        }

        /// <summary>
        /// Will build multipart form data as follows:
        /// ==================================================
        /// --$boundary
        /// Content-Disposition: form-data; name="$key"
        ///
        /// $value
        /// 
        /// ==================================================
        /// With the values:
        /// . $key and $value - if $value is not an array
        /// . $key[$valueKey] and $valueValue - if $value is an array with an integer key
        /// . $key.$valueKey and $valueValue - if $value is an array with a non-integer key
        ///
        /// . All array values are encoded recursively so array values may contain nested array values (i.e. Prices[0].Region[0]=100)
        /// . Multiple array values are encoded separately and appended together (i.e. Multipart block for Prices[0].Region[0]=100 . Multipart block for Prices[0].Region[1]=150)
        /// </summary>
        static function BuildFormDataMultipart($key, $value, $boundary)
        {
            $result = "";

            // ** Array $value
            if( is_array($value) )
            {
                // * Create and Encode a composite key for each array value
                foreach($value as $valueKey=>$valueValue) 
                {    
                    // Create the composite key
                    $compositeKey = $key;
                    if (preg_match("/^\d+$/i", $valueKey)) // int Array Key
                        $compositeKey .= "[$valueKey]";
                    else // string Array Key
                        $compositeKey .= ".$valueKey";

                    // Encode this Value
                    $result .= CrankyAdsHelper::BuildFormDataMultipart($compositeKey,$valueValue,$boundary);
                }
            }
            // ** Simple $value
            else
            {
                $result .= "--".$boundary."\r\n";
                $result .= "Content-Disposition: form-data; name=\"".$key."\"";
                $result .= "\r\n\r\n";
                $result .= $value;
                $result .= "\r\n";
            }

            return $result;
        }
    }
    
}


    
?>