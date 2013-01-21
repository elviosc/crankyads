<?php

/// <summary>
/// This file contains enumerations used by the CrankyAds plugin
/// </summary>
if(!defined("CRANKY_ADS_ENUMS_DEFINED"))
{

    /// <summary>
    /// Defines the behaviour of the cache
    /// </summary>
    /// <remarks>
    /// . (Flags) The values in this enumeration are flags and can be combined with each other
    /// </remarks>
    class CrankyAdsCacheBehaviourFlags
    {
        /// <summary>Full default behaviour. This value is mutually exclusive with all other flags</summary>
        const DefaultBehaviour = 0;

        /// <summary>Do not cache (or serve cache) with mime type text/html</summary>
        const DoNotCacheHtml = 1;

        /// <summary>If for whatever reason the server needs to be called (either because the cache does not exists or has timed out) then this flag prevents the call to the server from being made</summary>
        /// <remarks>This flag is important for calls that do not want to block the current request but still return a cached result if one exists</remarks>
        const DoNotMakeRequestToServer = 2;

        /// <summary>If the cache exists but is timed out and this flag is set then the main server will not be hit</summary>
        const IgnoreCacheTimeout = 4;

        /// <summary>If the call to the server failed but a timed out cached result exists then use that timed out cache</summary>
        const UseTimedOutCacheAsFallbackOnServerError = 8;
        
        /// <summary>When a response is received (ONLY) from the server, HttpResponse->ReplaceContentUrlPlaceholders() is automatically called before the response is cached</summary>
        /// <remarks>This ensures that the cached file is ready to be served/linked to directly and contains no more content url placeholders</remarks>
        const ReplaceContentUrlPlaceholdersOnServerResponse = 16;
    }

    /// <summary>
    /// Defines how asynchronous content should be copied once loaded
    /// </summary>
    class CrankyAdsCopyAsyncContentEnum
    {
        /// <summary>Default behaviour</summary>
        const DefaultBehaviour = 1;

        /// <summary>Do not copy the content once loaded</summary>
        const DoNotCopy = 0;

        /// <summary>Copy the content above the loader block</summary>
        const CopyAbove = 1;

        /// <summary>Copy the content to the bottom of the html <head> block</summary>
        const CopyToHead = 2;
    }


    define("CRANKY_ADS_ENUMS_DEFINED", "1"); // Enums Defined
    
}


    
?>