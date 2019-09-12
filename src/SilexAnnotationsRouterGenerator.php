<?php

namespace LongTailVentures\SilexAnnotationsRouter;

use LongTailVentures;

class SilexAnnotationsRouterGenerator
{
    private $_controllersToProcess;

    /**
     * SilexAnnotationsRouterGenerator constructor.
     *
     * @param string $controllerDir
     * Indicates which directory to read controller classes from
     */
    public function __construct($controllerDir)
    {
        $this->_controllersToProcess = [];
    }


    /**
     * Generates a router file indicated by $routerFile
     *
     * @param string $routerFile
     * Indicates which file to write router file contents to
     */
    public function generateRouterFileContentsToFile($routerFile)
    {
        $routerData = $this->_parseRouterDataFromControllers();
        $routerFileContents = $this->_generateRouterFileContentsFromData($routerData);

        file_put_contents($routerFile, $routerFileContents);
    }


    private function _parseRouterDataFromControllers()
    {
        $routerData = [];

        return $routerData;
    }


    private function _generateRouterFileContentsFromData(array $routerData)
    {
        $routerFileContents = '';

        return $routerFileContents;
    }
}