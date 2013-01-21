<?php

include_once dirname(__FILE__).'/settings.php';
include_once dirname(__FILE__).'/Proxy.php';

if (!class_exists("CrankyAdsDataContext"))
{
    /// <summary>
    /// A class used to persist and retrieve values
    /// </summary>
    /// <remarks>
    /// This class is used in place of directly calling Wordpress data storage (like $wpdb and get_options()) so that we can maintain data operations in a single place
    /// </remarks>
    class CrankyAdsDataContext
    {
        // ==========================================================================================================
        //                                                Constants
        // ==========================================================================================================

        /// <summary>Prefix used for all option keys</summary>
        var $WPDB_OPTIONS_PREFIX = "cranky_ads_option_";

        /// <summary>The option that stores the Advertise Here Page Id</summary>
        var $OPTION_KEY_ADVERTISE_PAGE_ID = "advertise_here_page_id";

        /// <summary>The option that stores the plugin version</summary>
        var $OPTION_KEY_INSTALLED_PLUGIN_VERSION = "installed_plugin_version";

        /// <summary>Wordpress Options key that stores the Blog Guid</summary>
        var $OPTION_KEY_BLOG_GUID = "blog_guid";

        /// <summary>The Wordpress Options key for whether the DataContext has been setup / installed</summary>
        var $OPTION_KEY_DATA_CONTEXT_SETUP = "data_context_setup";

        /// <summary>The size of each part in the byte data table</summary>
        var $BYTE_DATA_TABLE_PART_SIZE = 1024;

        // ==========================================================================================================
        //                                                Data
        // ==========================================================================================================

        /// <summary>Proxy used to get remote data from the server</summary>
        var $Proxy=false;

        // ==========================================================================================================
        //                                                Setup Methods
        // ==========================================================================================================

        /// <summary>
        /// Constructor - initialize the class
        /// </summary>
        /// <remarks>Before the DataContext can be used, a call to Init(..) must be made</remarks>
    	function CrankyAdsDataContext()
    	{
            global $wpdb;
            $wpdb->CrankyAdsZonesTable = $wpdb->base_prefix . "cranky_ads_zones";
            $wpdb->CrankyAdsCacheTable = $wpdb->base_prefix . "cranky_ads_cache";
            $wpdb->CrankyAdsByteDataTable = $wpdb->base_prefix . "cranky_ads_byte_data";

            if( CRANKY_ADS_DEBUG )
            {
                $wpdb->show_errors();
            }

    	}

        /// <summary>
        /// Initialize the DataContext
        /// </summary>
        /// <remarks>This call can be made at any time before the DataContext is first used. You DO NOT have to wait for Wordpress to be fully loaded before calling this method.</remarks>
        function Init( $proxy )
    	{
            $this->Proxy = $proxy;
    	}

        /// <summary>
        /// Performs a one time setup to run the plugin
        /// </summary>
        function InstallOrUpgrade( $vMajor, $vMinor, $vRevision )
        {
            // ** Init
            global $wpdb;

            // ** 1.1.0
            if( $vMajor < 1 || $vMajor == 1 && $vMinor < 1 )
            {
                // * Zones Table
                $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->CrankyAdsZonesTable}`;" );
                if($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->CrankyAdsZonesTable}'") != $wpdb->CrankyAdsZonesTable)
                {
                    $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->CrankyAdsZonesTable}` (
                        `id` bigint(20) NOT NULL auto_increment,
                        `name` varchar(255) NOT NULL,
                        `server_zone_id` bigint(20) NOT NULL,
                        PRIMARY KEY  (`id`)
                        );" );
                }
            }

            // ** 1.2.0
            if( $vMajor < 1 || $vMajor == 1 && $vMinor < 2 )
            {
                // * Cache Table
                $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->CrankyAdsCacheTable}`;" );
                if($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->CrankyAdsCacheTable}'") != $wpdb->CrankyAdsCacheTable)
                {
                    $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->CrankyAdsCacheTable}` (
                        `id` bigint(20) NOT NULL auto_increment,
                        `url` varchar(2048) NOT NULL,
                        `timestamp` DATETIME NOT NULL,
                        `httpContentType` varchar(256) NULL,
                        `httpContentLength` bigint(20) NULL,
                        `httpLastModified` varchar(256) NULL,
                        `httpETag` varchar(256) NULL,
                        `dataFilename` varchar(256) NULL,
                        `dataDbFileId` int NULL,
                        `timeoutSeconds` int NOT NULL,
                        PRIMARY KEY  (`id`)
                        );" );
                }

                // * Binary Data Table
                $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->CrankyAdsByteDataTable}`;" );
                if($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->CrankyAdsByteDataTable}'") != $wpdb->CrankyAdsByteDataTable)
                {
                    $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->CrankyAdsByteDataTable}` (
                        `id` bigint(20) NOT NULL auto_increment,
                        `dataId` bigint(20) NOT NULL,
                        `partNumber` int NOT NULL,
                        `data` varbinary({$this->BYTE_DATA_TABLE_PART_SIZE}) NULL,
                        PRIMARY KEY  (`id`)
                        );" );
                }
            }

            // ** 1.5.0
            if( $vMajor < 1 || $vMajor == 1 && $vMinor < 5 )
            {
                $wpdb->query( "ALTER TABLE `{$wpdb->CrankyAdsCacheTable}` ADD COLUMN `cacheType` varchar(16) NULL;" );
            }

            // ** Flag that the DataContext is setup
            $this->SetOption( $this->OPTION_KEY_DATA_CONTEXT_SETUP, "1" );

        }

        /// <summary>
        /// Clears all the one time Wordpress setup performed by the plugin during Install()
        /// </summary>
        function Uninstall()
        {
            // ** Init
            global $wpdb;

            // ** Clear the setup flag
            $this->SetOption( $this->OPTION_KEY_DATA_CONTEXT_SETUP, false);

            // ** 1.2.0
            {
                // Drop the cranky ads database tables
                $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->CrankyAdsByteDataTable}`;" );
                $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->CrankyAdsCacheTable}`;" );
            }

            // ** 1.1.0
            {
                // Drop the cranky ads database tables
                $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->CrankyAdsZonesTable}`;" );

                // Remove all cranky ads options
                $wpdb->query( "DELETE FROM `$wpdb->options` WHERE option_name like '$this->WPDB_OPTIONS_PREFIX%';" );
            }

        }

        /// <summary>
        /// Whether the DataContext is setup (i.e. InstallOrUpgrade() has been called at least once)
        /// </summary>
    	function IsSetup()
    	{
            return $this->GetOptionAsBool( $this->OPTION_KEY_DATA_CONTEXT_SETUP );
    	}

        // ==========================================================================================================
        //                                             General Data Methods
        // ==========================================================================================================

        /// <summary>
        /// Returns the value of the specified option or false if the value does not exist
        /// </summary>
    	function GetOption( $strOptionName )
    	{
            // ** Get the modified option key
            $strOptionName = $this->WPDB_OPTIONS_PREFIX . $strOptionName;

            return get_option($strOptionName);
    	}

        /// <summary>
        /// Returns the value of the specified option as a boolean or $bDefault if the value does not exist
        /// </summary>
        /// <remarks>Values must be either "true" or "false" or "1" or "0" otherwise $bDefault is also returned</remarks>
    	function GetOptionAsBool( $strOptionName, $bDefault=false )
    	{
            // Get the option
            $value = $this->GetOption($strOptionName);

            // No option set
            if( $value === false )
                return $bDefault;

            // Convert to Boolean
            $value = strtolower($value);
            if( $value == "true" || $value == "1" )
                return true;
            else if( $value == "false" || $value == "0" )
                return false;
            else
                return $bDefault;
    	}

        /// <summary>
        /// Returns the value of the specified option passed through strtotime(..) or $bDefault if the value does not exist
        /// </summary>
    	function GetOptionAsTime( $strOptionName, $bDefault=false )
    	{
            // Get the option
            $value = $this->GetOption($strOptionName);

            // No option set
            if( $value === false )
                return $bDefault;

            // Convert to time
            $value = strtotime($value);
            if( $value === false )
                return $bDefault;
            else
                return $value;
    	}

        /// <summary>
        /// Set the specified option and cache it locally. This value is NOT persisted until SaveChanges() is called.
        /// </summary>
        /// <remarks>If the option value is set to boolean false then the option will be deleted</remarks>
    	function SetOption( $strOptionName, $strOptionValue )
    	{
            // ** Get the modified option key
            $strOptionName = $this->WPDB_OPTIONS_PREFIX . $strOptionName;

            if($strOptionValue === false)
                delete_option($strOptionName);
            else
                update_option( $strOptionName, $strOptionValue );
    	}

        /// <summary>
        /// Returns the current int value of the option and increments the value by 1.
        /// </summary>
        /// <remarks>
        /// . If the current value is at $intMax then the value will be reset to $intMin
        /// . If the option has not been set then $intMin is returned and incremented.
        /// . If the value of the option is already initialized then it must be a valid integer.
        /// </remarks>
    	function IncrementCounterOption( $strCounterOptionName, $intMin, $intMax )
    	{
            $value = $this->GetOption( $strCounterOptionName );

            if( $value !== false )
                $value = intval($value);
            else
                $value = $intMin;

            $this->SetOption( $strCounterOptionName, strval( $value == $intMax ? $intMin : ($value+1) ) );

            return $value;
    	}

        /// <summary>
        /// Get/Set the Advertise Here page id
        /// </summary>
        /// <remarks>If the Advertise Here Page has not been set then this value will be boolean false</remarks>
        function GetAdvertiseHerePageId()
        {
            $result = $this->GetOption($this->OPTION_KEY_ADVERTISE_PAGE_ID);

            if($result === false)
                return false;
            else
                return intval($result);
        }
        function SetAdvertiseHerePageId( $intPageId )
        {
            return $this->SetOption($this->OPTION_KEY_ADVERTISE_PAGE_ID,$intPageId);
        }

        /// <summary>
        /// Returns the Url of the AdvertiseHere page
        /// </summary>
    	function GetAdvertiseHerePageUrl()
    	{
            return get_permalink( $this->GetAdvertiseHerePageId() );
    	}

        /// <summary>
        /// Get/Set the version of the plugin that is currently installed
        /// </summary>
        /// <remarks>The version of this plugin is defined in CRANKY_ADS_PLUGIN_VERSION however this might differ to the version of the plugin which is/was installed (if any)</remarks>
        function GetInstalledPluginVersion()
        {
            return $this->GetOption($this->OPTION_KEY_INSTALLED_PLUGIN_VERSION);
        }
        function SetInstalledPluginVersion( $strInstalledVersion )
        {
            return $this->SetOption($this->OPTION_KEY_INSTALLED_PLUGIN_VERSION,$strInstalledVersion);
        }

        /// <summary>
        /// Get/Set the GUID of the blog which this plugin is linked to
        /// </summary>
        function GetBlogGuid()
        {
            return $this->GetOption($this->OPTION_KEY_BLOG_GUID);
        }
        function SetBlogGuid( $strBlogGuid )
        {
            return $this->SetOption($this->OPTION_KEY_BLOG_GUID,$strBlogGuid);
        }

        /// <summary>
        /// Returns the XML DomDocument object representing the CSS files that should be loaded in the <head> of Admin pages and Advertise Here pages
        /// </summary>
        /// <remarks>
        /// . This data is retrieved from the remote server via $this->Proxy (or the cache if applicable)
        /// . Considerations should be made for calling this method in user sensitive situations since this method may block while performing remote calls
        /// . false is returned if an error occurred
        /// . $bLinkToCacheFiles - Indicates whether the data should be re-written so that any content is linked directly to the associated cache file on disk (if available)
        /// </remarks>
        function Remote_GetHeadCssData( $flagsCacheBehaviour=0, $bLinkToCacheFiles=false )
        {
            $cssDataRemoteUrl = $this->Proxy->ToServerUrlFromActionUrl("Common/HeadCssBlock");

            $cssResponse = $this->Proxy->GetRemoteContent($cssDataRemoteUrl,false,false,0,3,false,false,false,$flagsCacheBehaviour);

            $cssData = false;
            if($cssResponse !== false && $cssResponse->HttpResponseCode === 200)
            {
                if( $bLinkToCacheFiles )
                    $cssResponse->LinkContentUrlPlaceholdersToCache();
                $cssResponse->ReplaceAllContentPlaceholders();
                $cssResponse->Content = str_replace("&", "&amp;", $cssResponse->Content); // loadXML complains about URLs with &'s in them within the XML. However I'm a bit uneasy about doing a blanket replace in case the server ever decides to send back &amp; itself

                $cssData = new DomDocument('1.0');
                if( !$cssData->loadXML($cssResponse->Content) )
                    $cssData = false;
            }

            return $cssData;
        }

        /// <summary>
        /// Returns the XML DomDocument object representing the Script files that should be loaded in the <head> of Admin pages and Advertise Here pages
        /// </summary>
        /// <remarks>
        /// . This data is retrieved from the remote server via $this->Proxy (or the cache if applicable)
        /// . Considerations should be made for calling this method in user sensitive situations since this method may block while performing remote calls
        /// . false is returned if an error occurred
        /// . $bLinkToCacheFiles - Indicates whether the data should be re-written so that any content is linked directly to the associated cache file on disk (if available)
        /// </remarks>
        function Remote_GetHeadScriptData( $flagsCacheBehaviour=0, $bLinkToCacheFiles=false )
        {
            $scriptRemoteUrl = $this->Proxy->ToServerUrlFromActionUrl("Common/HeadScriptBlock");

            $scriptResponse = $this->Proxy->GetRemoteContent($scriptRemoteUrl,false,false,0,3,false,false,false,$flagsCacheBehaviour);

            $scriptData = false;
            if($scriptResponse !== false && $scriptResponse->HttpResponseCode === 200)
            {
                if( $bLinkToCacheFiles )
                    $scriptResponse->LinkContentUrlPlaceholdersToCache();
                $scriptResponse->ReplaceAllContentPlaceholders();
                $scriptResponse->Content = str_replace("&", "&amp;", $scriptResponse->Content); // loadXML complains about URLs with &'s in them within the XML. However I'm a bit uneasy about doing a blanket replace in case the server ever decides to send back &amp; itself

                $scriptData = new DomDocument('1.0');
                if( !$scriptData->loadXML($scriptResponse->Content) )
                    $scriptData = false;
            }

            return $scriptData;
        }

        // ==========================================================================================================
        //                                                Zone Methods
        // ==========================================================================================================

        /// <summary>
        /// Returns the array of of all Zone{int server_zone_id, string name} objects
        /// </summary>
    	function GetAllZones()
        {
            global $wpdb;
            $allZones = $wpdb->get_results("SELECT server_zone_id, name FROM `$wpdb->CrankyAdsZonesTable`;");
            return $allZones;
        }

        /// <summary>
        /// Add or Update a zone
        /// </summary>
    	function UpdateZone( $intServerZoneId, $strName )
        {
            global $wpdb;

            $intServerZoneId = mysql_real_escape_string($intServerZoneId);
            $strName = mysql_real_escape_string($strName);

            $wpdb->query( "Delete from `{$wpdb->CrankyAdsZonesTable}`
                           Where `server_zone_id` = {$intServerZoneId};" );

            $wpdb->query( "INSERT INTO `{$wpdb->CrankyAdsZonesTable}`
                           (`name`, `server_zone_id`)
                           VALUES 
                           ('{$strName}', {$intServerZoneId});" );
        }

        /// <summary>
        /// Synchronize all zones saved locally with those supplied in the CrankyAds ZoneList xml DOM
        /// </summary>
        function UpdateAllZones( $xmlDom )
        {
            // ** Clear all the zones
            $this->ClearAllZones();

            // ** Insert new zones
            $zoneList = $xmlDom->getElementsByTagName("Zone");
            foreach ($zoneList AS $iZone)
            {
                $this->UpdateZone( intval($iZone->attributes->getNamedItem("zoneId")->nodeValue) , $iZone->attributes->getNamedItem("name")->nodeValue );
            }
        }

        /// <summary>
        /// Synchronize all zones saved locally with those supplied by the CrankyAds server.
        /// </summary>
        function Remote_UpdateAllZonesFromServer() 
        {
            // ** Get the data
            $remoteUrl = $this->Proxy->ToServerUrlFromActionUrl("Data/ZoneList");

            $httpResponse = $this->Proxy->GetRemoteContent($remoteUrl,false,false,false);

            // ** Success
            if($httpResponse !== false && $httpResponse->HttpResponseCode === 200)
            {
                $httpResponse->ReplaceAllContentPlaceholders();

                $data = new DomDocument('1.0');
                $data->loadXML($httpResponse->Content);

                if ($data)
                {
                    $this->UpdateAllZones($data);
                    return true;
                }
                else
                {
                    return false;
                }
            }
            // ** Failure
            else
            {
                return false;
            }
        }

        /// <summary>
        /// Clears all zones
        /// </summary>
    	function ClearAllZones()
        {
            global $wpdb;
            $wpdb->query( "Delete from `{$wpdb->CrankyAdsZonesTable}`;" );
        }

        // ==========================================================================================================
        //                                                Cache Methods
        // ==========================================================================================================

        /// <summary>
        /// Save the byte data to the database and return the ID of this data chunk (or false if an error occurred).
        /// ** NOTE: $data is expected to be a string and not a byte array. This is just because strings are easier to work with and correspond to a byte array in functionality.
        /// ** WARNING: Currently byte data is expected to only come from the cache and hence is strongly coupled with the cache. 
        ///             If we want to save other types of data in future then we'll need to introduce a type column into the table.
        /// </summary>
    	function SaveByteData( $data )
        {
            global $wpdb;
            $dataId = false;

            // ** Insert the first entry and get the ID of the row
            $dbResult = $wpdb->insert(  $wpdb->CrankyAdsByteDataTable, 
                                        array( 
                                            'dataId' => -1, 
                                            'partNumber' => 0,
                                            'data' => ""
                                        ), 
                                        array( 
                                            '%d',
                                            '%d', 
                                            '%s'
                                        ) 
                        );

            if($dbResult === false)
                return false;
            else
                $dataId = $wpdb->insert_id;

            // Update the first row
            $dataPart = strlen($data) > $this->BYTE_DATA_TABLE_PART_SIZE ? substr($data,0,$this->BYTE_DATA_TABLE_PART_SIZE) : $data;
            $dbResult = $wpdb->update(  $wpdb->CrankyAdsByteDataTable, 
                                        array( 
                                            'dataId' => $dataId,    // Set the dataId to be the same as the auto increment ID of the first row (since we know this is unique)
                                            'partNumber' => 0,
                                            'data' => $dataPart,
                                        ), 
                                        array( 'id' => $dataId ), 
                                        array( 
                                            '%d',
                                            '%d',
                                            '%s'
                                        ), 
                                        array( '%d' ) 
                        );


            // ** Insert all additional data parts
            $offset = 1;
            
            while( strlen($data) > ($offset * $this->BYTE_DATA_TABLE_PART_SIZE) && $dbResult)
            {
                $partStart = ($offset * $this->BYTE_DATA_TABLE_PART_SIZE);
                $partSize = min( strlen($data) - $partStart, $this->BYTE_DATA_TABLE_PART_SIZE);
                $dataPart = substr($data,$partStart,$partSize);

                $dbResult = $wpdb->insert(  $wpdb->CrankyAdsByteDataTable, 
                                            array( 
                                                'dataId' => $dataId, 
                                                'partNumber' => $offset,
                                                'data' => $dataPart
                                            ), 
                                            array( 
                                                '%d',
                                                '%d', 
                                                '%s'
                                            ) 
                            );

                $offset++;
            }


            // ** Check the result
            if($dbResult === false)
            {
                // Delete all partial entries
                $wpdb->query( "DELETE FROM `$wpdb->CrankyAdsByteDataTable` WHERE dataId = $dataId;" );
                return false;
            }

            return $dataId;
        }

        /// <summary>
        /// Load the byte data from the database and return the data as a string (or false if an error occurred).
        /// </summary>
    	function GetByteData( $dataId )
        {
            global $wpdb;

            // ** Setup
            $dataId = mysql_real_escape_string($dataId);

            // ** Get the parts
            $dataParts = $wpdb->get_results("SELECT * FROM `$wpdb->CrankyAdsByteDataTable` where dataId = '$dataId' order by partNumber asc;");
            if( count($dataParts) == 0 )
                return false;

            // ** Build the data
            $data = "";
            foreach ($dataParts as $iPart)
            {
                $data .= $iPart->data;
            }

            return $data;
        }

        /// <summary>
        /// Deletes the byte data from the database with the specified Id
        /// </summary>
    	function DeleteByteData( $dataId )
        {
            global $wpdb;

            $dataId = mysql_real_escape_string($dataId);

            return $wpdb->query( "DELETE FROM `$wpdb->CrankyAdsByteDataTable` WHERE dataId = $dataId;" ) !== false;
        }

        /// <summary>
        /// Returns the cache entry for the specified url or false if not found
        /// </summary>
    	function GetCacheEntryByUrl( $url )
        {
            global $wpdb;

            $url = mysql_real_escape_string($url);
            $entry = $wpdb->get_results("SELECT * FROM `$wpdb->CrankyAdsCacheTable` where url = '$url' order by timestamp desc LIMIT 1;");

            if( count($entry) == 0 )
                return false;
            else
                return $entry[0];
        }

        /// <summary>
        /// Returns all cache entries or false if an error occurred
        /// </summary>
    	function GetAllCacheEntries()
        {
            global $wpdb;

            $entries = $wpdb->get_results("SELECT * FROM `$wpdb->CrankyAdsCacheTable`;");
            return $entries;
        }

        /// <summary>
        /// Returns an array of all the data filenames specified by cache entries
        /// </summary>
    	function GetAllCacheEntryDataFilenames()
        {
            global $wpdb;

            $result = array();

            $entries = $wpdb->get_results("SELECT dataFilename FROM `$wpdb->CrankyAdsCacheTable` where dataFilename is not null;");

            if( $entries === false )
                return false;

            foreach( $entries as $iEntry )
                $result[] = $iEntry->dataFilename;

            return $result;
        }

        /// <summary>
        /// Returns the array of cache entries that are timed out (or only those the have been timed out for more than the specified grace period).
        /// Returns false if an error occurred.
        /// </summary>
    	function GetTimedOutCacheEntries( $gracePeriodAfterTimeoutInSeconds = 0 )
        {
            global $wpdb;

            // Calculate the timeout time (adjusting for any additional grace period)
            $now = strtotime( gmdate("Y-m-d H:i:s") . " UTC" );
            $timeoutAt = $now;
            if( $gracePeriodAfterTimeoutInSeconds != 0 )
            {
                $offset = "";
                if( $gracePeriodAfterTimeoutInSeconds > 0 )
                    $offset = "-";
                else
                    $offset = "+";
                $offset .= $gracePeriodAfterTimeoutInSeconds . " seconds";

                $timeoutAt = strtotime($offset, $now );
            }

            // Get all timed out entries
            $entries = $wpdb->get_results("SELECT * FROM `$wpdb->CrankyAdsCacheTable` where (timestamp + INTERVAL timeoutSeconds SECOND) < '" . date("Y-m-d H:i:s",$timeoutAt) . "';");

            return $entries;
        }

        /// <summary>
        /// Returns the array of cache entries for the specified type. If $strCacheType is false then all the cache entries without a type will be returned.
        /// Returns false if an error occurred.
        /// . $strCacheType - The Type of the cache entries to return
        /// . $bExcludeTimedOut - Whether to exclude timed out cache entries from the results
        /// </summary>
    	function GetCacheEntriesByType( $strCacheType = false, $bExcludeTimedOut = false )
        {
            global $wpdb;

            // Setup
            if( $strCacheType === false )
                $strCacheType = "is NULL";
            else
                $strCacheType = "= '".mysql_real_escape_string(strtolower($strCacheType))."'";

            if($bExcludeTimedOut)
                $bExcludeTimedOut = " and (timestamp + INTERVAL timeoutSeconds SECOND) > '" . gmdate("Y-m-d H:i:s") . "'";
            else
                $bExcludeTimedOut = "";

            // Get all entries
            $entries = $wpdb->get_results("SELECT * FROM `$wpdb->CrankyAdsCacheTable` where cacheType $strCacheType $bExcludeTimedOut ;");

            return $entries;
        }

        /// <summary>
        /// This will timeout all cache entires with the specified type.
        /// If $cacheType is false then all the cache entries WITHOUT a type will be timed out.
        /// </summary>
        function TimeoutCacheEntriesByType( $strCacheType = false )
        {
            global $wpdb;

            // Setup
            if( $strCacheType === false )
                $strCacheType = "is NULL";
            else
                $strCacheType = "= '".mysql_real_escape_string(strtolower($strCacheType))."'";

            // Timeout the entries
            return $wpdb->query( "UPDATE `$wpdb->CrankyAdsCacheTable` SET timeoutSeconds = 0 WHERE cacheType $strCacheType;" ) !== false;
        }

        /// <summary>
        /// Delete all Byte Data that does not have an associated cache entry
        /// ** WARNING: Currently byte data is expected to only come from the cache and hence is strongly coupled with the cache. 
        ///             If we want to save other types of data in future then we'll need to introduce a type column into the byte data table.
        /// </summary>
    	function DeleteOrphanedCacheEntryByteData()
        {
            global $wpdb;

            return $wpdb->query( "DELETE FROM `$wpdb->CrankyAdsByteDataTable` WHERE NOT EXISTS (select * from `$wpdb->CrankyAdsCacheTable` where `$wpdb->CrankyAdsCacheTable`.dataDbFileId = `$wpdb->CrankyAdsByteDataTable`.dataId );" ) !== false;
        }

        /// <summary>
        /// Update the cacheEntry in the database with the one supplied. If $cacheEntry does not have an id parameter then a new cache entry is inserted into the database.
        /// </summary>
    	function UpdateCacheEntry( $cacheEntry )
        {
            global $wpdb;

            if( is_object($cacheEntry) )
                $cacheEntry = (array)$cacheEntry;

            if(isset($cacheEntry["id"]) )
                $this->WpDbUpdateRow( $wpdb->CrankyAdsCacheTable, $cacheEntry );
            else
                $this->WpDbInsertRow( $wpdb->CrankyAdsCacheTable, $cacheEntry );
        }

        /// <summary>
        /// Delete the supplied cacheEntry from the database and return whether the operation succeeded
        /// </summary>
        function DeleteCacheEntry( $cacheEntryId )
        {
            global $wpdb;

            $cacheEntryId = mysql_real_escape_string($cacheEntryId);

            return $wpdb->query( "DELETE FROM `$wpdb->CrankyAdsCacheTable` WHERE `id` = $cacheEntryId;" ) !== false;
        }

        // ==========================================================================================================
        //                                                Helper Methods
        // ==========================================================================================================

        /// <summary>
        /// Similar to $wpdb->Insert(..) but does not require a format, and handles NULL values. Returns the number of rows affected or false if an error occurred.
        /// </summary>
        /// <remarks>
        /// . $wpdb will contain the value of any AUTO_INCREMENT value inserted in the $wpdb->insert_id field
        /// . This method determines the format of the parameters in $row via is_string(..) and is_null(..)
        /// </remarks>
    	function WpDbInsertRow( $table, $row )
        {
            global $wpdb;

            // ** Convert
            if( is_object($row) )
                $row = (array)$row;

            // ** Check
            if( !is_array($row) )
                return false;

            // ** Build the SQL statement
            $columns = "";
            $values = "";

            foreach( $row as $key=>$value )
            {
                // Columns
                if(strlen($columns) > 0)
                    $columns .= ",";
                $columns .= "`".$key."`";

                // Values
                if(strlen($values) > 0)
                    $values .= ",";

                if( is_null($value) )
                    $values .= "NULL";
                else if( is_string($value) )
                    $values .= "'" . mysql_real_escape_string($value) . "'";
                else
                    $values .= $value;
            }

            $sql = "INSERT INTO `$table` (".$columns.") VALUES (".$values.");";

            return $wpdb->query($sql);
        }

        /// <summary>
        /// Similar to $wpdb->Update(..) but does not require a format, and handles NULL values. Returns the number of rows affected or false if an error occurred.
        /// </summary>
        /// <remarks>
        /// . $wpdb will contain the value of any AUTO_INCREMENT value inserted in the $wpdb->insert_id field
        /// . This method determines the format of the parameters in $row via is_string(..) and is_null(..)
        /// </remarks>
    	function WpDbUpdateRow( $table, $row, $primaryKeyColumn = "id" )
        {
            global $wpdb;

            // ** Convert
            if( is_object($row) )
                $row = (array)$row;

            // ** Check
            if( !is_array($row) || !isset($row[$primaryKeyColumn]) )
                return false;

            // ** Build the SQL statement
            $first = true;
            $sql = "UPDATE `$table` SET ";
            foreach( $row as $key=>$value )
            {
                if( $key == $primaryKeyColumn )
                    continue;

                // Comma
                if($first)
                    $first = false;
                else
                    $sql .= ", ";

                // Set Values
                $sql .= "`".$key."` = ";

                if( is_null($value) )
                    $sql .= "NULL";
                else if( is_string($value) )
                    $sql .= "'" . mysql_real_escape_string($value) . "'";
                else
                    $sql .= $value;
            }

            // WHERE
            $value = $row[$primaryKeyColumn];

            $sql .= " WHERE `".$primaryKeyColumn."` = ";

            if( is_null($value) )
                $sql .= "NULL";
            else if( is_string($value) )
                $sql .= "'" . mysql_real_escape_string($value) . "'";
            else
                $sql .= $value;

            $sql .= ";";

            return $wpdb->query($sql);
        }
        
    }
    
}


	
?>