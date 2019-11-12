<?php

namespace LongTailVentures\SilexAnnotationsRouter;

use LongTailVentures;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use ReflectionClass;
use ReflectionMethod;

class SilexAnnotationsRouterGenerator
{
    /**
     * Generates a router file (indicated by $routerFile) for $controllers
     *
     * @param array $controllers
     * Numerical indexed array.
     * Indicates which controller to generate a router file for.
     * NOTE: each entry can either be an actual file name to a controller, or a directory of controllers
     *
     * @param string $routerFile
     * Indicates which file to write router file contents to
     *
     * @return bool $isRouterFileCreated
     */
    public function generateRouterFileForControllers(array $controllers, $routerFile) : bool
    {
        $controllersToProcess = [];

        // for each controller in $controllers, determine if controller class -or- controller directory
        // if controller directory, retrieve controller classes
        foreach ($controllers as $controller)
        {
            if (is_dir($controller))
            {
                $controllersToProcess = array_merge(
                    $controllersToProcess,
                    $this->_retrieveControllerFilesFromDirectory($controller)
                );
            }
            else if (is_file($controller))
                $controllersToProcess[] = $controller;
        }

        $routerData = $this->_parseRouterDataFromControllers($controllersToProcess);
        $routerFileContents = $this->_generateRouterFileContentsFromData($routerData);

        return file_put_contents($routerFile, $routerFileContents) !== false;
    }


    /**
     * Generates a router file (indicated by $routerFile) for $controllerFile
     *
     * @param string $controller
     * The controller file to generate a router file for
     *
     * @param string $routerFile
     * Indicates which file to write router file contents to
     *
     * @return bool $isRouterFileCreated
     */
    public function generateRouterFileForController($controller, $routerFile) : bool
    {
        return $this->generateRouterFileForControllers([$controller], $routerFile);
    }



    private function _retrieveControllerFilesFromDirectory($directory) : array
    {
        $controllers = [];

        $directories = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($directories as $file)
        {
            if ($file->isDir())
                continue;

            if (!LongTailVentures\StringUtils::endsWith($file->getPathname(), 'Controller.php'))
                continue;

            $controllers[] = $file->getPathname();
        }

        return $controllers;
    }


    private function _parseRouterDataFromControllers(array $controllersToProcess)
    {
        $routerData = [
            '__CONTROLLERS' => [],
            '__URLS' => []
        ];

        foreach ($controllersToProcess as $controllerFile)
        {
            $fileContents = file_get_contents($controllerFile);

            // We search for namespace
            $namespace = null;
            if (preg_match('/namespace\s+([\w\\\_-]+)/', $fileContents, $matches) === 1)
                $namespace = $matches[1];

            if (preg_match('/class\s+([\w_-]+)/', $fileContents, $matches) === 1)
            {
                $className = ($namespace !== null) ? $namespace . '\\' . $matches[1] : $matches[1];

                $reflector = new ReflectionClass($className);
                $controllerRoutingName = "";

                $docComments = $reflector->getDocComment();
                $docComments = str_replace(['/**', '*/', '*'], ['', '', ''], $docComments);
                $docComments = explode(PHP_EOL, $docComments);

                foreach ($docComments as $line)
                {
                    $line = trim($line);

                    if (empty($line))
                        continue;

                    if (LongTailVentures\StringUtils::startsWith($line, 'name='))
                    {
                        list($label, $name) = explode('=', $line);
                        $controllerRoutingName = $name;
                    }
                }

                if (empty($controllerRoutingName))
                    continue;

                if (!isset($routerData['__CONTROLLERS'][$controllerRoutingName]))
                {
                    $routerData['__CONTROLLERS'][$controllerRoutingName] = [
                        'className' => $className,
                        'actions' => []
                    ];
                }

                $methods = $reflector->getMethods(ReflectionMethod::IS_PUBLIC);

                foreach ($methods as $method)
                {
                    if ($method->isStatic() || $method->isConstructor() || $method->class !== $className)
                        continue;

                    $methodName = $method->getName();

                    $docComments = $method->getDocComment();
                    $docComments = str_replace(['/**', '*/', '*'], ['', '', ''], $docComments);
                    $docComments = explode(PHP_EOL, $docComments);

                    $isRoutingEntry = false;
                    $isAclEntry = false;

                    $routerData['__CONTROLLERS'][$controllerRoutingName]['actions'][$methodName] = [
                        'routes' => [],
                        'acl' => []
                    ];

                    $routingEntry = null;
                    foreach ($docComments as $line)
                    {
                        $line = trim($line);

                        if (empty($line))
                            continue;

                        if (LongTailVentures\StringUtils::startsWith($line, '@route'))
                        {
                            $isRoutingEntry = true;
                            $routingEntry = [
                                'asserts' => [],
                                'values' => [],
                                'url' => '',
                                'method' => '',
                                'name' => ''
                            ];
                            continue;
                        }

                        if ($isRoutingEntry)
                        {
                            if (LongTailVentures\StringUtils::startsWith($line, 'name='))
                            {
                                list($label, $name) = explode('=', $line);
                                $routingEntry['name'] = $name;
                                continue;
                            }

                            if (LongTailVentures\StringUtils::startsWith($line, 'url='))
                            {
                                list($label, $url) = explode('=', $line);
                                $routingEntry['url'] = $url;
                                $routerData['__URLS'][$url] = "$controllerRoutingName";
                                continue;
                            }

                            if (LongTailVentures\StringUtils::startsWith($line, 'method='))
                            {
                                list($label, $method) = explode('=', $line);
                                $routingEntry['method'] = $method;
                                continue;
                            }

                            if (LongTailVentures\StringUtils::startsWith($line, 'assert'))
                            {
                                $routingEntry['asserts'][] = "->" . $line;
                                continue;
                            }

                            if (LongTailVentures\StringUtils::startsWith($line, 'value'))
                            {
                                $routingEntry['values'][] = "->" . $line;
                                continue;
                            }

                            if ($line === ')')
                            {
                                $routerData['__CONTROLLERS'][$controllerRoutingName]['actions'][$methodName]['routes'][] = $routingEntry;
                                $isRoutingEntry = false;
                                $routingEntry = null;
                                continue;
                            }
                        }

                        if (LongTailVentures\StringUtils::startsWith($line, '@acl'))
                        {
                            $isAclEntry = true;
                            continue;
                        }

                        if ($isAclEntry)
                        {
                            if ($line === ')')
                            {
                                $isAclEntry = false;
                                continue;
                            }

                            $routerData['__CONTROLLERS'][$controllerRoutingName]['actions'][$methodName]['acl'][] = $line;
                        }
                    }
                }

                uasort($routerData['__CONTROLLERS'][$controllerRoutingName]['actions'], function ($actionA, $actionB) {
                    return strcasecmp(
                            max(array_column($actionA['routes'], 'url')),
                            max(array_column($actionB['routes'], 'url'))
                        ) < 0;
                });
            }
        }

        krsort($routerData['__URLS']);
        $routerData['__URLS'] = array_values(array_unique($routerData['__URLS']));

        return $routerData;
    }


    private function _generateRouterFileContentsFromData(array $routerData)
    {
        $commentBreak = "// ----------------------------------------------------------------------------";

        $routerFileContents = "<?php" . PHP_EOL . PHP_EOL;
        foreach ($routerData['__URLS'] as $url => $controllerName)
        {
            $controllerToProcess = $routerData['__CONTROLLERS'][$controllerName];

            $routerFileContents .= $commentBreak . PHP_EOL;
            $routerFileContents .= $commentBreak . PHP_EOL;

            $routerFileContents .= "\$controller = '$controllerName';" . PHP_EOL;
            $routerFileContents .= "\$app[\$controller] = function() use (\$app) {" . PHP_EOL;
            $routerFileContents .= "    return new {$controllerToProcess['className']}(\$app);" . PHP_EOL;
            $routerFileContents .= "};" . PHP_EOL;

            $routerFileContents .= PHP_EOL;

            foreach ($controllerToProcess['actions'] as $action => $actionData)
            {
                $routerFileContents .= $commentBreak. PHP_EOL;
                $routerFileContents .= "\$action = \"\$controller:$action\";" . PHP_EOL;

                $routingEntries = [];
                foreach ($actionData['routes'] as $i => $routerDatum)
                {
                    $routingEntries[$i] = '';
                    $routingEntries[$i] .= "\$app->{$routerDatum['method']}(" . PHP_EOL;
                    $routingEntries[$i] .= "    '{$routerDatum['url']}'," . PHP_EOL;
                    $routingEntries[$i] .= "    \$action" . PHP_EOL;

                    if (count($routerDatum['asserts']) > 0 || count($routerDatum['values']) > 0)
                    {
                        $routingEntries[$i] .= ')' . PHP_EOL;

                        $assertsAndValues = array_merge($routerDatum['asserts'], $routerDatum['values']);
                        $routingEntries[$i] .= implode(PHP_EOL, $assertsAndValues) . ';';

                        $routingEntries[$i] .= PHP_EOL;
                    }
                    else
                        $routingEntries[$i] .= ');' . PHP_EOL;
                }

                $routerFileContents .= implode(PHP_EOL, $routingEntries);

                if (count($actionData['acl']) > 0)
                {
                    $routerFileContents .= PHP_EOL;

                    $aclEntries = [];
                    foreach ($actionData['acl'] as $aclEntry)
                        $aclEntries[] = "        " . $aclEntry;

                    $routerFileContents .= "\$app['acl']->addResource(\$action);" . PHP_EOL;
                    $routerFileContents .= "\$app['acl']->allow([" . PHP_EOL;

                    $routerFileContents .= implode("," . PHP_EOL, $aclEntries);
                    $routerFileContents .= PHP_EOL;
                    $routerFileContents .= "    ]," . PHP_EOL;
                    $routerFileContents .= "    \$action" . PHP_EOL;
                    $routerFileContents .= ");" . PHP_EOL;
                }

                $routerFileContents .= $commentBreak . PHP_EOL;
                $routerFileContents .= PHP_EOL;
            }

            $routerFileContents .= PHP_EOL . PHP_EOL;
        }

        return $routerFileContents;
    }
}
