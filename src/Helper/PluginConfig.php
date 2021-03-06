<?php

namespace IO\Helper;

use Plenty\Plugin\ConfigRepository;

class PluginConfig
{
    private $pluginName;

    /** @var ConfigRepository $configRepository */
    private $configRepository;

    public function __construct( ConfigRepository $configRepository, $pluginName )
    {
        $this->pluginName = $pluginName;
        $this->configRepository = $configRepository;
    }

    protected function getMultiSelectValue( $key, $possibleValues = [], $default = null )
    {
        $configValue = $this->getConfigValue( $key, "all" );
        if ( $configValue === "all" )
        {
            return $possibleValues;
        }
        elseif ( !strlen( $configValue ) )
        {
            if ( $default === null )
            {
                return $possibleValues;
            }
            else
            {
                return $default;
            }
        }
        else
        {
            return explode( ", ", $configValue );
        }
    }

    protected function getTextValue( $key, $default = "", $transformDefault = "" )
    {
        $value = $this->getConfigValue( $key, $default );
        if ($value === $transformDefault)
        {
            return $default;
        }
        return $value;
    }

    protected function getIntegerValue( $key, $default = 0 )
    {
        return intval( $this->getConfigValue( $key, $default) );
    }

    protected function getBooleanValue( $key, $default = false )
    {
        $value = $this->getConfigValue($key);

        if ( $value === "true" || $value === "false"  || $value === "1" || $value === "0" || $value === 1 || $value === 0)
        {
            return $value === "true" || $value === "1" || $value === 1;
        }

        return $default;
    }

    protected function getConfigValue( $key, $default = null )
    {
        return $this->configRepository->get( $this->pluginName . "." . $key, $default );
    }
}
