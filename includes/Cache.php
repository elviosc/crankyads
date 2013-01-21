<?php

include_once dirname(__FILE__).'/settings.php';
include_once dirname(__FILE__).'/DataContext.php';
include_once dirname(__FILE__).'/Helper.php';

if (!class_exists("CrankyAdsCache"))
{

    /// <summary>
    /// Class used to manage the local caching of server data
    /// </summary>
    class CrankyAdsCache
    {
        /// <summary>Whether to disable the ability to save cache data to file</summary>
        var $DISABLE_CACHE_DATA_FILE_WRITE = false;

        /// <summary>Whether to disable the ability to save cache data to file</summary>
        var $CACHE_DATA_FILE_SUBDIRECTORY = "cachedata";

        /// <summary>The default timeout for when cache items should be refreshed (in seconds)</summary>
        /// <remarks>Even though a cache entry has timed out it is NOT removed from the cache. See Cleanup.</remarks>
        var $DEFAULT_CACHE_TIMEOUT_SECONDS = 14400; // 4 hours // TODO: Should we make overrides for this in DataContext->GetOptionAsInt(..,$intDefault) (as well as CLEANUP and GRACE period)? Then let those options be get/set via push_notifications?

        /// <summary>The number of hours between which the cache should be cleaned up (i.e. remove timed out and orphaned items)</summary>
        /// <remarks>This should be smaller than or at least equal to CACHE_CLEANUP_TIMED_OUT_GRACE_PERIOD_IN_SECONDS</remarks>
        var $CACHE_CLEANUP_EVERY_HOURS = 72; // 3 days

        /// <summary>The additional grace period after an item times out after which it will be cleaned up (in seconds)</summary>
        /// <remarks>The longest an item will remain in the cache after timeout is CACHE_CLEANUP_EVERY_HOURS + CACHE_CLEANUP_TIMED_OUT_GRACE_PERIOD_IN_SECONDS</remarks>
        var $CACHE_CLEANUP_TIMED_OUT_GRACE_PERIOD_IN_SECONDS = 604800; // 7 days (long grace periods make the blog robust if there are CrankyAds server issues)

        /// <summary>The options key for when the last cleanup occurred</summary>
        var $CACHE_CLEANUP_LAST_CALLED_TIMESTAMP_OPTION_KEY = "last_cache_cleanup";


        /// <summary>Instance of the DataContext used to persist and retrieve values</summary>
        var $DataContext = null;


        /// <summary>
        /// Constructor - initialize the Cranky Ads Content Controller
        /// </summary>
        function CrankyAdsCache( $dataContext )
        {
            $this->DataContext = $dataContext;
        }

        /// <summary>
        /// Perform any initialization tasks after Wordpress and the Plugin have fully loaded
        /// </summary>
        function Init()
        {
            // ** Cleanup the cache
            $lastCleanup = $this->DataContext->GetOption( $this->CACHE_CLEANUP_LAST_CALLED_TIMESTAMP_OPTION_KEY);
            if( $lastCleanup !== false )
            {
                $lastCleanup = strtotime($lastCleanup);
                $nextCleanup = strtotime("+".$this->CACHE_CLEANUP_EVERY_HOURS." hours", $lastCleanup );
                $now = strtotime( gmdate("Y-m-d H:i:s") . " UTC" );

                if( $now > $nextCleanup )
                    $this->Cleanup();
            }
            // Cleanup now - we've never cleaned up the cache
            else
            {
                $this->Cleanup();
            }
        }
        
        /// <summary>
        /// Setup the environment for the cache
        /// </summary>
        function InstallOrUpgrade( $vMajor, $vMinor, $vRevision )
        {
            // ** Perform Other Upgrades
            // None so far

            // ** Clear the cache
            // If the plugin is updated then the /cachedata directory is cleared but the database still refers to the cached files,
            // which would lead us to serving references to removed files.
            $this->Clear(); // TODO: Add a parameter to Clear() to queue those cleared cache items for an async update (once async cache refresh is implemented)
        }

        /// <summary>
        /// Clears all Cache setup
        /// </summary>
        function Uninstall()
        {
            // ** 1.2.0
            {
                $this->Clear(); // Clear the cache
            }
        }

        // ==========================================================================================================
        //                                                 Cache Methods
        // ==========================================================================================================

        /// <summary>
        /// Returns whether a cached version of $url exists
        /// </summary>
        /// <remarks>
        /// If $ignoreTimeout then a cache entry will be returned even if it has timed out (check HttpResponse->cIsTimedOut)
        /// </remarks>
        function IsInCache( $url, $ignoreTimeout = false )
        {
            // ** NO CACHE
            if( CRANKY_ADS_DISABLE_CACHE )
                return false;

            // ** Clean input
            $url = trim(strtolower($url));
            if(strlen($url) <= 0)
                return false;

            // ** Get the cache entry
            $cacheEntry = $this->DataContext->GetCacheEntryByUrl($url);

            if( $cacheEntry === false )
                return false;

            // ** Check Timeout
            if( $ignoreTimeout )
            {
                return true;
            }
            else
            {
                $now = strtotime( gmdate("Y-m-d H:i:s") . " UTC" );
                $timestamp = strtotime( $cacheEntry->timestamp . " UTC" );
                $timeoutAt = strtotime("+".$cacheEntry->timeoutSeconds." seconds", $timestamp );

                if( $now > $timeoutAt)
                    return false;
                else
                    return true;
            }

        }

        /// <summary>
        /// Returns a HttpResponse object containing the cached version of $url or false if not found
        /// </summary>
        /// <remarks>
        /// . If $ignoreTimeout then a cache entry will be returned even if it has timed out (check HttpResponse->cIsTimedOut)
        /// . $requestHeaders are the Http Headers sent by the request to retrieve this cache object. If specified this method will check for cache headers to determine
        ///   whether to respond with a 304 or a 200 HttpResponse
        /// . The returned HttpResponse is not a complete HttpResponse in that it is missing some required HTTP headers like Date (and Server)
        /// </remarks>
        function GetCache( $url, $requestHeaders = false, $ignoreTimeout = false )
        {
            // ** NO CACHE
            if( CRANKY_ADS_DISABLE_CACHE )
                return false;

            // ** Clean input
            $url = trim(strtolower($url));
            if(strlen($url) <= 0)
                return false;

            if( is_array($requestHeaders) )
                $requestHeaders = CrankyAdsHelper::ArrayKeyToLower($requestHeaders);

            // ** Get the cache entry
            $cacheEntry = $this->DataContext->GetCacheEntryByUrl($url);

            if( $cacheEntry === false )
                return false;

            // ** Check Timeout
            $now = strtotime( gmdate("Y-m-d H:i:s") . " UTC" );
            $timestamp = strtotime( $cacheEntry->timestamp . " UTC" );
            $timeoutAt = strtotime("+".$cacheEntry->timeoutSeconds." seconds", $timestamp );

            if( !$ignoreTimeout && $now > $timeoutAt)
                return false;

            // ** Check the response code
            $responseCode = 200;
            if(    is_array($requestHeaders)

                && (isset($requestHeaders["if-modified-since"]) || isset($requestHeaders["if-none-match"])) // The request expects a 304 (if applicable)

                && (!isset($cacheEntry->httpLastModified) || isset($cacheEntry->httpLastModified) && isset($requestHeaders["if-modified-since"]) && trim(strtolower($cacheEntry->httpLastModified)) == trim(strtolower($requestHeaders["if-modified-since"]) ) ) // Last-Modified matches
                && (!isset($cacheEntry->httpETag) || isset($cacheEntry->httpETag) && isset($requestHeaders["if-none-match"]) && trim(strtolower($cacheEntry->httpETag)) == trim(strtolower($requestHeaders["if-none-match"]) ) ) // ETag matches
              )
            {
                $responseCode = 304;
            }

            // ** Read the cached data
            $cacheData = false;

            if( $responseCode == 304 )
            {
                $cacheData = "";
            }
            else
            {
                if( isset($cacheEntry->dataFilename) )
                    $cacheData = $this->LoadDataFromFile( $cacheEntry->dataFilename );
                else if( isset($cacheEntry->dataDbFileId) )
                    $cacheData = $this->LoadDataFromDb( $cacheEntry->dataDbFileId );

                // Could not read the data - invalid cache?
                if( $cacheData === false )
                {
                    // Delete this cache since there is an error with it
                    $this->DeleteCacheForCacheEntry($cacheEntry);
                    return false;
                }
            }

            // ** Construct the HttpResponse

            // Headers
            $headers = array();
            $headers["Content-Type"][] = $cacheEntry->httpContentType;
            if( isset($cacheEntry->httpLastModified) )
                $headers["Last-Modified"][] = $cacheEntry->httpLastModified;
            if( isset($cacheEntry->httpETag) )
                $headers["ETag"][] = $cacheEntry->httpETag;

            // Response
            $result = new CrankyAdsHttpResponse( $this->DataContext );
            $result->Init( $responseCode, $headers, $cacheData );

            // Cache parameters
            $result->cIsFromCache = true;
            $result->cIsTimedOut = ($now > $timeoutAt);
            if($result->cIsTimedOut)
                $result->cSecondsSinceTimeout = ($now - $timeoutAt);
            $result->cRenewHeaders = array();
            if( isset($cacheEntry->httpLastModified) )
                $result->cRenewHeaders["If-Modified-Since"] = $cacheEntry->httpLastModified;
            if( isset($cacheEntry->httpETag) )
                $result->cRenewHeaders["If-None-Match"] = $cacheEntry->httpETag;

            return $result;
        }

        /// <summary>
        /// Update the cache with the contents of the HttpResponse
        /// </summary>
        /// <remarks>
        /// . A timeout value of 0 implies that the default timeout value should be used (DEFAULT_CACHE_TIMEOUT_SECONDS)
        /// . Even though a cache entry has timed out it is NOT removed from the cache. See DeleteTimedOutCache(..) and Cleanup(..).
        /// . Returns whether the httpResponse was added or updated successfully (and is now valid and retrievable from the cache)
        /// </remarks>
        function UpdateCache( $url, $httpResponse, $timeoutSeconds = 0, $strCacheType = false )
        {
            // ** NO CACHE
            if( CRANKY_ADS_DISABLE_CACHE )
                return false;

            // ** Check
            if( !isset($httpResponse) || $httpResponse === false || $httpResponse->cIsFromCache )
                return false;

            // ** Prepare

            if( $strCacheType !== false )
                $strCacheType = strtolower($strCacheType);

            // Timeout
            if($timeoutSeconds === false || $timeoutSeconds <= 0 )
                $timeoutSeconds = $this->DEFAULT_CACHE_TIMEOUT_SECONDS;

            // Only support 200 (OK) and 304 (No Change) responses
            if( $httpResponse == false || $httpResponse->HttpResponseCode != 200 && $httpResponse->HttpResponseCode != 304 )
                return false;

            // Clean up the url
            $url = trim(strtolower($url));
            if(strlen($url) <= 0)
                return false;

            // ** Check if url is in database
            $cacheEntry = $this->DataContext->GetCacheEntryByUrl($url);

            // ** Existing Cache Entry
            if( $cacheEntry !== false )
            {
                // * Check HttpResponse (304)
                //   Update the current cache entry (or delete the entry if out of date)
                if( $httpResponse->HttpResponseCode == 304 )
                {
                    // Cache is still valid - update timestamp
                    if(    trim(strtolower($cacheEntry->httpLastModified)) == trim(strtolower($httpResponse->hLastModified))
                        && trim(strtolower($cacheEntry->httpETag)) == trim(strtolower($httpResponse->hETag)) )
                    {
                        $cacheEntry->timestamp = gmdate("Y-m-d H:i:s");
                        $cacheEntry->timeoutSeconds = $timeoutSeconds;
                        if( $strCacheType !== false )
                            $cacheEntry->cacheType = $strCacheType;

                        $this->DataContext->UpdateCacheEntry($cacheEntry);

                        return true;
                    }
                    // Cached item is invalid (but we didnt get the new content) - Delete
                    else
                    {
                        $this->DeleteCacheForCacheEntry($cacheEntry);
                        return false;
                    }
                }
                // * Check HttpResponse (200) - resave
                else if( $httpResponse->HttpResponseCode == 200 )
                {
                    // Delete current entry
                    $this->DeleteCacheForCacheEntry($cacheEntry);

                    // Insert new entry (below)
                    $cacheEntry = false;
                }
                // * Unexpected
                else
                {
                    return false;
                }
            }

            // ** New Cache Entry
            if( $cacheEntry === false )
            {
                // * Check HttpResponse (304)
                // Can't do anything here since we don't have content to compare against
                if( $httpResponse->HttpResponseCode == 304 )
                    return false;

                // * Check HttpResponse (200)
                if( $httpResponse->HttpResponseCode != 200 )
                    return false;

                // * Save the data

                // Save data to file
                $dataFile = $this->SaveDataToFile($url, $httpResponse);

                // Save data to database
                $dataDbFileId = false;
                if($dataFile === false)
                {
                    $dataDbFileId = $this->SaveDataToDb($httpResponse);

                    // We can't save the data.
                    if( $dataDbFileId === false )
                        return false;
                }

                // Create a new cache entry
                $cacheEntry = array(
                
                    "url" => $url,
                    "timestamp" => gmdate("Y-m-d H:i:s"),
                    "httpContentLength" => strlen($httpResponse->Content),
                    "timeoutSeconds" => $timeoutSeconds,
                    "cacheType" => null
                );
                if( $httpResponse->hContentType !== false )
                    $cacheEntry["httpContentType"] = $httpResponse->hContentType;
                if( $httpResponse->hLastModified !== false )
                    $cacheEntry["httpLastModified"] = $httpResponse->hLastModified;
                if( $httpResponse->hETag !== false )
                    $cacheEntry["httpETag"] = $httpResponse->hETag;
                if( $dataFile !== false )
                    $cacheEntry["dataFilename"] = $dataFile;
                else
                    $cacheEntry["dataFilename"] = null;
                if( $dataDbFileId !== false )
                    $cacheEntry["dataDbFileId"] = $dataDbFileId;
                else
                    $cacheEntry["dataDbFileId"] = null;
                if( $strCacheType !== false )
                    $cacheEntry["cacheType"] = $strCacheType;

                $dbResult = $this->DataContext->UpdateCacheEntry($cacheEntry);

                // Error - remove the newly inserted data
                if($dbResult === false)
                {
                    if( $dataFile !== false )
                        $this->DeleteDataFile($dataFile);
                    if( $dataDbFileId !== false )
                        $this->DeleteDataFromDb($dataDbFileId);

                    return false;
                }

                return true;
            }
        }

        /// <summary>
        /// This will timeout all cache entires with the specified type.
        /// If $cacheType is false then all the cache entries WITHOUT a type will be timed out.
        /// </summary>
        function TimeoutCacheForCacheType( $strCacheType = false )
        {
            $this->DataContext->TimeoutCacheEntriesByType( $strCacheType );
        }

        /// <summary>
        /// Deletes the cache for the specified url
        /// </summary>
        function DeleteCacheForUrl( $url )
        {
            // ** Clean input
            $url = trim(strtolower($url));
            if(strlen($url) <= 0)
                return;

            // ** Get the cache entry
            $cacheEntry = $this->DataContext->GetCacheEntryByUrl($url);

            // ** Delete the cache
            if( $cacheEntry !== false )
                $this->DeleteCacheForCacheEntry($cacheEntry);
        }

        /// <summary>
        /// Deletes the cache for the specified cache type. 
        /// If $cacheType is false then all the cache entries WITHOUT a type will be deleted.
        /// </summary>
        function DeleteCacheForCacheType( $strCacheType = false )
        {
            // ** Clean input
            if( $strCacheType !== false )
            {
                $strCacheType = trim(strtolower($strCacheType));
                if(strlen($strCacheType) <= 0)
                    return;
            }

            // ** Delete the cache entries
            $entries = $this->DataContext->GetCacheEntriesByType( $strCacheType );

            if( !is_array($entries) )
                return;

            foreach( $entries as $iEntry )
            {
                $this->DeleteCacheForCacheEntry( $iEntry );
            }
        }

        /// <summary>
        /// Deletes the cache for the specified DataContext cache entry
        /// </summary>
        function DeleteCacheForCacheEntry( $cacheEntry )
        {
            // Delete the entry and associated file(s)
            $this->DataContext->DeleteCacheEntry( $cacheEntry->id );
            if( isset($cacheEntry->dataFilename) )
                $this->DeleteDataFile($cacheEntry->dataFilename);
            if( isset($cacheEntry->dataDbFileId) )
                $this->DeleteDataFromDb($cacheEntry->dataDbFileId);
        }

        /// <summary>
        /// Deletes the cache for all items that have timed out
        /// </summary>
        /// <remarks>
        /// $gracePeriodAfterTimeoutInSeconds provides an additional period after timeout at which point the cache will NOT be deleted but will still be considered timed out
        /// . This is useful as it allows a timed out cache to be served instantly while it is updated asynchronously (via a separate iFrame call)
        /// . The timed out cache can also be used as a fallback if there is a problem connecting to the server
        /// </remarks>
        function DeleteTimedOutCache( $gracePeriodAfterTimeoutInSeconds = 0 )
        {
            $timedOut = $this->DataContext->GetTimedOutCacheEntries( $gracePeriodAfterTimeoutInSeconds );

            if( !is_array($timedOut) )
                return;

            foreach( $timedOut as $iTimedOutEntry )
            {
                $this->DeleteCacheForCacheEntry( $iTimedOutEntry );
            }
        }

        /// <summary>
        /// Deletes all cached items
        /// </summary>
        function Clear()
        {
            $fullCache = $this->DataContext->GetAllCacheEntries();
            if( $fullCache === false )
                return;

            foreach( $fullCache as $iCacheEntry )
            {
                $this->DeleteCacheForCacheEntry( $iCacheEntry );
            }
        }

        /// <summary>
        /// Cleans up the cache by deleting timed out items and orphaned data
        /// </summary>
        /// <remarks>
        /// . Timed out items are given a grace period before they are deleted (See CACHE_CLEANUP_TIMED_OUT_GRACE_PERIOD_IN_SECONDS)
        /// . This method is called automatically every CACHE_CLEANUP_EVERY_HOURS hours. It does not need to be called explicitly.
        /// </remarks>
        function Cleanup()
        {
            // ** Save the timestamp at which this cleanup occurred
            // Note: We do this first in case the actual delete causes a problem. This way the plugin will not repeatedly call Cleanup() on each refresh.
            $this->DataContext->SetOption( $this->CACHE_CLEANUP_LAST_CALLED_TIMESTAMP_OPTION_KEY, gmdate("Y-m-d H:i:s") . " UTC");

            // ** Delete timed out values
            $this->DeleteTimedOutCache( $this->CACHE_CLEANUP_TIMED_OUT_GRACE_PERIOD_IN_SECONDS );

            // ** Delete orphaned data
            $this->DeleteOrphanedFileData();
            $this->DeleteOrphanedDbData();
        }

        // ==========================================================================================================
        //                                                Helper Methods
        // ==========================================================================================================

        /// <summary>
        /// Saves the contents of the $httpResponse to the file system and returns the filename (or false if an error occurred)
        /// </summary>
        function SaveDataToFile( $url, $httpResponse )
        {
            // ** Check
            if( $this->DISABLE_CACHE_DATA_FILE_WRITE || $httpResponse === false )
                return false;

            // ** Disable all output
            // Note: 
            // There's a chance the following file system calls could produce warnings.
            // Since this function is called quite often and there's a chance these warnings will be permanent
            // (due to file system permission issues) we don't want the warnings to be output permanently as well
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Init directory

            // Get the directory name
            $dirname = dirname(realpath(__FILE__)) . "/" . $this->CACHE_DATA_FILE_SUBDIRECTORY;

            // Create the directory
            if(!file_exists($dirname))
            {
                $dirCreated = mkdir($dirname);

                if( !$dirCreated || !file_exists($dirname) )
                {
                    error_reporting($errorReportingSave);
                    return false;
                }
            }

            // ** Save data to file
            $filename = false;
            $file = $this->GetUniqueFile($dirname,$filename,"cache","s".strlen($httpResponse->Content). ($this->ExtractCacheExtension($url,$httpResponse)) );
            if($file === false )
            {
                error_reporting($errorReportingSave);
                return false;
            }

            $writeResult = fwrite( $file, $httpResponse->Content, strlen($httpResponse->Content));
            $writeResult = fclose( $file ) && ($writeResult !== false);

            if($writeResult === false)
            {
                unlink( $dirname . "/" . $filename ); // Delete the file (if any)
                $filename = false;
            }

            // ** Restore error reporting
            error_reporting($errorReportingSave);

            // ** Result
            return $filename; // Will be false on error
        }

        /// <summary>
        /// Saves the contents of the $httpResponse to the db and returns the id of the file (or false if an error occurred)
        /// </summary>
        function SaveDataToDb( $httpResponse )
        {
            // ** Check
            if( $httpResponse === false )
                return false;

            // ** Disable all output
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Save data to db
            $fileId = $this->DataContext->SaveByteData($httpResponse->Content);

            // ** Restore error reporting
            error_reporting($errorReportingSave);

            return $fileId;
        }

        /// <summary>
        /// Deletes the specified cache file and returns whether the operation succeeded
        /// </summary>
        function DeleteDataFile( $filename )
        {
            // ** Disable all output
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Delete the file
            $result = false;

            // Get the directory name
            $dirname = dirname(realpath(__FILE__)) . "/" . $this->CACHE_DATA_FILE_SUBDIRECTORY;

            // Delete the file
            $fullFilename = $dirname . "/" . $filename;
            if(file_exists($dirname))
                $result = unlink( $fullFilename );

            // ** Restore error reporting
            error_reporting($errorReportingSave);

            return $result;
        }

        /// <summary>
        /// Deletes the specified cache data from the database and returns whether the operation succeeded
        /// </summary>
        function DeleteDataFromDb( $dataId )
        {
            // ** Disable all output
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Delete the data from the Db
            $result = $this->DataContext->DeleteByteData($dataId);

            // ** Restore error reporting
            error_reporting($errorReportingSave);

            return $result;
        }

        /// <summary>
        /// Deletes any cache data files that do not have an associated cache entry
        /// </summary>
        /// <remarks>This situation should never occur. However, it is part of a good cleanup routine to check anyway.</remarks>
        function DeleteOrphanedFileData()
        {
            // ** Disable all output
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Get the list of files

            // Get the list of physical files
            $dirname = dirname(realpath(__FILE__)) . "/" . $this->CACHE_DATA_FILE_SUBDIRECTORY;

            $physicalFiles = false;
            if( file_exists ($dirname) )
                $physicalFiles = scandir( $dirname );
            
            // Get the list of cache data files
            $cacheDataFilenames = $this->DataContext->GetAllCacheEntryDataFilenames();

            if( $physicalFiles === false || $cacheDataFilenames === false )
            {
                error_reporting($errorReportingSave);
                return;
            }


            // ** Delete all orphaned files
            foreach( $physicalFiles as $iPhysicalFile )
            {
                $fullFilename = $dirname . "/" . $iPhysicalFile;
                if( is_file( $fullFilename ) && !in_array( $iPhysicalFile, $cacheDataFilenames ) )
                {
                    unlink( $fullFilename );
                }
            }

            // ** Restore error reporting
            error_reporting($errorReportingSave);
        }

        /// <summary>
        /// Deletes any cache data from the DB that does not have an associated cache entry
        /// </summary>
        /// <remarks>This situation should never occur. However, it is part of a good cleanup routine to check anyway.</remarks>
        function DeleteOrphanedDbData()
        {
            // ** Disable all output
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Delete the data from the Db
            $result = $this->DataContext->DeleteOrphanedCacheEntryByteData();

            // ** Restore error reporting
            error_reporting($errorReportingSave);

            return $result;
        }

        /// <summary>
        /// Loads cache data from the specified file and returns the result (or false if an error occurred)
        /// </summary>
        function LoadDataFromFile( $filename )
        {
            // ** Disable all output
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Get the directory name
            $dirname = dirname(realpath(__FILE__)) . "/" . $this->CACHE_DATA_FILE_SUBDIRECTORY;

            // ** Load from file
            $fullFilename = $dirname . "/" . $filename;
            if(!file_exists($fullFilename) )
            {
                error_reporting($errorReportingSave);
                return false;
            }

            $data = file_get_contents( $fullFilename );

            // ** Restore error reporting
            error_reporting($errorReportingSave);

            // ** Result
            return $data; // Will be false on error
        }

        /// <summary>
        /// Loads cache data from the data and returns the result (or false if an error occurred)
        /// </summary>
        function LoadDataFromDb( $dataFileId )
        {
            // ** Disable all output
            $errorReportingSave = error_reporting(0); 
            if( CRANKY_ADS_DISABLE_ERROR_SUPPRESSION )
                error_reporting($errorReportingSave);

            // ** Save data to db
            $data = $this->DataContext->GetByteData($dataFileId);

            // ** Restore error reporting
            error_reporting($errorReportingSave);

            return $data;
        }

        /// <summary>
        /// Returns a unique file and filename for the specified directory. Returns false if an error occurred.
        /// </summary>
        function GetUniqueFile( $directory, &$filename, $prefix="", $suffix="" )
        {
            // Init
            $filename = false;
            if(strlen($prefix) <= 0)
                $prefix = "u";
                
            $filenameBase = $prefix . gmdate("YmdHis");   // Unique name is based on the current timestamp. We could use microtime() but that depends on some OS features.
            $filename = $filenameBase . $suffix;
            $uniqueId = 0;
            $ioErrorRetry = 0;
            $file = false;

            // Try filenames until we successfully open up a new unique file (or fail too many times)
            while( $file === false && $ioErrorRetry < 10 )
            {
                $uniqueId++;
                $filename = $filenameBase . $uniqueId . $suffix;

                if(!file_exists( $directory . "/" . $filename ) )
                {
                    $file = fopen( $directory . "/" . $filename, "x" );
                    if($file === false)
                        $ioErrorRetry++;
                }
            }

            return $file;
        }

        /// <summary>
        /// Returns the extension of the cache file based on the $url and $httpResponse including the leading ".".
        /// If no extension can be determined then ".cae" is returned.
        /// </summary>
        function ExtractCacheExtension( $url, $httpResponse )
        {
            $fileExtensionInUrl = '/\.[a-zA-Z]{1,5}\s*$/i';
            if( preg_match($fileExtensionInUrl, $url, $matches) )
            {
                return $matches[0];
            }
            else
            {
                if( strpos( $httpResponse->hContentType, "text/xml") !== false )
                    return ".xml";
                else if( strpos( $httpResponse->hContentType, "text/css") !== false )
                    return ".css";
                else if( strpos( $httpResponse->hContentType, "/x-javascript") !== false || strpos( $httpResponse->hContentType, "/javascript") !== false )
                    return ".js";
                else if( strpos( $httpResponse->hContentType, "image/png") !== false )
                    return ".png";
                else if( strpos( $httpResponse->hContentType, "image/gif") !== false )
                    return ".gif";
                else if( strpos( $httpResponse->hContentType, "image/jpeg") !== false || strpos( $httpResponse->hContentType, "image/jpg") !== false )
                    return ".jpg";
                else if( strpos( $httpResponse->hContentType, "text/html") !== false )
                    return ".htm";
                else
                    return ".cae";
            }
        }
    }
    
}


    
?>