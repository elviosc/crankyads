<?php

include_once dirname(__FILE__).'/settings.php';
include_once dirname(__FILE__).'/ContentController.php';

if (!class_exists("CrankyAdsZoneWidgetBase") && class_exists("WP_Widget"))
{

    /// <summary>
    /// Base class of all Cranky Ads Zone Widgets. There is one Widget subclass per zone for ease of use.
    /// </summary>
    class CrankyAdsZoneWidgetBase extends WP_Widget 
    {
        /// <summary>The server zone Id</summary>
        var $ServerZoneId;

        /// <summary>
        /// Initialize the CrankyAdsWidget Base class
        /// </summary>
        function CrankyAdsZoneWidgetBase( $intZoneId, $strZoneName )
        {
            // Save parameters
            $this->ServerZoneId = $intZoneId;

            // Init the Widget
            $subsZoneName = htmlentities($strZoneName);

            $widget_ops = array('classname' => ('crankyadszonewidget'.$intZoneId), 'description' => 'Displays the Cranky Ads Zone \''.$subsZoneName.'\' on your site' );
            $this->WP_Widget( 'crankyads-zone-widget-'.$intZoneId, 'Ad - '.$subsZoneName, $widget_ops );
        }

        /// <summary>
        /// Display the Ad Zone content
        /// </summary>
        function widget($args, $instance) 
        {
            global $CrankyAdsPlugin;

            extract( $args ); // Set $before_widget, $before_title, $after_title and $after_widget

            echo $before_widget;
            $title = empty($instance['title']) ? '' : apply_filters('widget_title', $instance['title']);
            if ( !empty( $title ) ) { echo $before_title . $title . $after_title; };

            $CrankyAdsPlugin->ContentController->ServeAdZoneAds($this->ServerZoneId);

            echo $after_widget;
        }

        /// <summary>
        /// Update the widget
        /// </summary>
        function update($new_instance, $old_instance) 
        {
            $instance = $old_instance;
            $instance['title'] = strip_tags($new_instance['title']);
            return $new_instance;
        }

        /// <summary>
        /// Display the widget form
        /// </summary>
        function form($instance) 
        {
            $instance = wp_parse_args( (array) $instance, array( 'title' => '') );
            $title = strip_tags($instance['title']);
    ?>
            <p><label for="<?php echo $this->get_field_id('title'); ?>">Title: <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo attribute_escape($title); ?>" /></label></p>

            <p>To edit the settings for the zone being displayed by this Widget please go <a href='<?php echo admin_url(CRANKY_ADS_SETTINGS_PAGE_URL_RELATIVE) ?>'>here</a>.</p>
    <?php
        }
    }

    /// <summary>
    /// Class used to create all CrankyAdsZoneWidgets
    /// </summary>
    class CrankyAdsZoneWidgetFactory
    {
        /// <summary>
        /// Creates and registers a Widget for the specified zone
        /// </summary>
        function RegisterWidgetFor($intZoneId, $strZoneName) 
        {
            // Execute the widget class
            eval( $this->GetWidgetCode($intZoneId, $strZoneName) );

            // Register the widget with Wordpress
            register_widget($this->GetWidgetSubclassName($intZoneId));
        }

        /// <summary>
        /// Returns the PHP code for a new CrankyAdsZoneWidgetBase subclass
        /// </summary>
        function GetWidgetCode($intZoneId, $strZoneName) 
        {
            // ** Create substitutions
            $subsClassName = $this->GetWidgetSubclassName($intZoneId);

            // Careful to ensure no PHP special characters are present in the name
            $subsZoneName = $strZoneName;
            $subsZoneName = str_replace ( "\\" , "\\\\" , $subsZoneName);
            $subsZoneName = str_replace ( "\"" , "\\\"" , $subsZoneName);
            $subsZoneName = str_replace ( "$" , "\\$" , $subsZoneName);

            // ** Create the subclass widget code
            $result = 
                "if (!class_exists('{$subsClassName}'))
                {
                    class {$subsClassName} extends CrankyAdsZoneWidgetBase 
                    {
                            function {$subsClassName}() 
                            {
                                \$this->CrankyAdsZoneWidgetBase({$intZoneId},\"{$subsZoneName}\");
                            }
                    }
                }";

            return $result;
        }

        /// <summary>
        /// Returns the name of the CrankyAdsZoneWidgetBase subclass generated for the specified zone 
        /// </summary>
        function GetWidgetSubclassName($intZoneId)
        {
            return "CrankyAdsZoneWidget".$intZoneId;
        }
    }
}
?>