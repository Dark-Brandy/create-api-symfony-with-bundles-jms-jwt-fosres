<?php

/*
 * This file is part of Handcrafted in the Alps - Rest Routing Bundle Project.
 *
 * (c) 2011-2020 FriendsOfSymfony <http://friendsofsymfony.github.com/>
 * (c) 2020 Sulu GmbH <hello@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HandcraftedInTheAlps\RestRoutingBundle\Routing\Loader\Reader;

use Doctrine\Common\Annotations\Reader;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\Request\ParamReaderInterface;
use HandcraftedInTheAlps\RestRoutingBundle\Inflector\InflectorInterface;
use HandcraftedInTheAlps\RestRoutingBundle\Routing\RestRouteCollection;
use Psr\Http\Message\MessageInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route as RouteAnnotation;
use Symfony\Component\Routing\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * REST controller actions reader.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * @internal
 */
class RestActionReader
{
    const COLLECTION_ROUTE_PREFIX = 'c';

    private $annotationReader;
    private $paramReader;
    private $inflector;
    private $formats;
    private $includeFormat;
    private $routePrefix;
    private $namePrefix;
    private $versions;
    private $pluralize;
    private $parents = [];
    private $availableHTTPMethods = [
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'link',
        'unlink',
        'head',
        'options',
        'mkcol',
        'propfind',
        'proppatch',
        'move',
        'copy',
        'lock',
        'unlock',
    ];
    private $availableConventionalActions = ['new', 'edit', 'remove'];
    private $hasMethodPrefix;
    private $ignoredClasses = [
        ConstraintViolationListInterface::class,
        MessageInterface::class,
        ParamConverter::class,
        ParamFetcherInterface::class,
        Request::class,
        SessionInterface::class,
        UserInterface::class,
    ];

    public function __construct(Reader $annotationReader, ?ParamReaderInterface $paramReader, InflectorInterface $inflector, bool $includeFormat, array $formats = [], bool $hasMethodPrefix = true)
    {
        $this->annotationReader = $annotationReader;
        $this->paramReader = $paramReader;
        $this->inflector = $inflector;
        $this->includeFormat = $includeFormat;
        $this->formats = $formats;
        $this->hasMethodPrefix = $hasMethodPrefix;
    }

    /**
     * Set ignored classes.
     *
     * These classes will be ignored for route construction when
     * type hinted as method argument.
     *
     * @param string[] $ignoredClasses
     */
    public function setIgnoredClasses(array $ignoredClasses): void
    {
        $this->ignoredClasses = $ignoredClasses;
    }

    /**
     * Get ignored classes.
     *
     * @return string[]
     */
    public function getIgnoredClasses(): array
    {
        return $this->ignoredClasses;
    }

    public function setRoutePrefix(?string $prefix = null): void
    {
        $this->routePrefix = $prefix;
    }

    public function getRoutePrefix(): ?string
    {
        return $this->routePrefix;
    }

    public function setNamePrefix(?string $prefix = null): void
    {
        $this->namePrefix = $prefix;
    }

    public function getNamePrefix(): string
    {
        return $this->namePrefix;
    }

    /**
     * @param string[]|string|null $versions
     */
    public function setVersions($versions = null): void
    {
        $this->versions = (array) $versions;
    }

    /**
     * @return string[]|null
     */
    public function getVersions(): ?array
    {
        return $this->versions;
    }

    public function setPluralize(?bool $pluralize): void
    {
        $this->pluralize = $pluralize;
    }

    /**
     * @return bool|null
     */
    public function getPluralize(): ?bool
    {
        return $this->pluralize;
    }

    /**
     * @param string[] $parents Array of parent resources names
     */
    public function setParents(array $parents): void
    {
        $this->parents = $parents;
    }

    /**
     * @return string[]
     */
    public function getParents(): array
    {
        return $this->parents;
    }

    /**
     * @param string[] $resource
     *
     * @throws \InvalidArgumentException
     */
    public function read(RestRouteCollection $collection, \ReflectionMethod $method, array $resource): void
    {
        // check that every route parent has non-empty singular name
        foreach ($this->parents as $parent) {
            if (empty($parent) || '/' === substr($parent, -1)) {
                throw new \InvalidArgumentException('Every parent controller must have `get{SINGULAR}Action(\$id)` method where {SINGULAR} is a singular form of associated object');
            }
        }

        // if method is not readable - skip
        if (!$this->isMethodReadable($method)) {
            return;
        }

        // if we can't get http-method and resources from method name - skip
        $httpMethodAndResources = $this->getHttpMethodAndResourcesFromMethod($method, $resource);
        if (!$httpMethodAndResources) {
            return;
        }

        list($httpMethod, $resources, $isCollection, $isInflectable) = $httpMethodAndResources;
        $arguments = $this->getMethodArguments($method);

        // if we have only 1 resource & 1 argument passed, then it's object call, so
        // we can set collection singular name
        if (1 === \count($resources) && 1 === \count($arguments) - \count($this->parents)) {
            $collection->setSingularName($resources[0]);
        }

        // if we have parents passed - merge them with own resource names
        if (\count($this->parents)) {
            $resources = array_merge($this->parents, $resources);
        }

        if (empty($resources)) {
            $resources[] = null;
        }

        $routeName = $httpMethod . $this->generateRouteName($resources);
        $urlParts = $this->generateUrlParts($resources, $arguments, $httpMethod);

        // if passed method is not valid HTTP method then it's either
        // a hypertext driver, a custom object (PUT) or collection (GET)
        // method
        if (!\in_array($httpMethod, $this->availableHTTPMethods, true)) {
            $urlParts[] = $httpMethod;
            $httpMethod = $this->getCustomHttpMethod($httpMethod, $resources, $arguments);
        }

        // generated parameters
        $routeName = strtolower($routeName);
        $path = implode('/', $urlParts);
        $defaults = ['_controller' => $method->getName()];
        $requirements = [];
        $options = [];
        $host = '';
        $versionCondition = $this->getVersionCondition();
        $versionRequirement = $this->getVersionRequirement();

        $annotations = $this->readRouteAnnotation($method);

        if (!empty($annotations)) {
            foreach ($annotations as $annotation) {
                $path = implode('/', $urlParts);
                $defaults = ['_controller' => $method->getName()];
                $requirements = [];
                $options = [];
                $methods = explode('|', $httpMethod);

                $annoRequirements = $annotation->getRequirements();
                $annoMethods = $annotation->getMethods();

                if (!empty($annoMethods)) {
                    $methods = $annoMethods;
                }

                $path = null !== $annotation->getPath() ? $this->routePrefix . $annotation->getPath() : $path;
                $requirements = array_merge($requirements, $annoRequirements);
                $options = array_merge($options, $annotation->getOptions());
                $defaults = array_merge($defaults, $annotation->getDefaults());
                $host = $annotation->getHost();
                $schemes = $annotation->getSchemes();

                if ($this->hasVersionPlaceholder($path)) {
                    $combinedCondition = $annotation->getCondition();
                    $requirements = array_merge($versionRequirement, $requirements);
                } else {
                    $combinedCondition = $this->combineConditions($versionCondition, $annotation->getCondition());
                }

                $this->includeFormatIfNeeded($path, $requirements);

                // add route to collection
                $route = new Route(
                    $path,
                    $defaults,
                    $requirements,
                    $options,
                    $host,
                    $schemes,
                    $methods,
                    $combinedCondition
                );
                $this->addRoute($collection, $routeName, $route, $isCollection, $isInflectable, $annotation);
            }
        } else {
            if ($this->hasVersionPlaceholder($path)) {
                $versionCondition = null;
                $requirements = $versionRequirement;
            }

            $this->includeFormatIfNeeded($path, $requirements);

            $methods = explode('|', strtoupper($httpMethod));

            // add route to collection
            $route = new Route(
                $path,
                $defaults,
                $requirements,
                $options,
                $host,
                [],
                $methods,
                $versionCondition
            );
            $this->addRoute($collection, $routeName, $route, $isCollection, $isInflectable);
        }
    }

    private function getVersionCondition(): ?string
    {
        if (empty($this->versions)) {
            return null;
        }

        return sprintf("request.attributes.get('version') in ['%s']", implode("', '", $this->versions));
    }

    private function combineConditions(?string $conditionOne, ?string $conditionTwo): ?string
    {
        if (null === $conditionOne) {
            return $conditionTwo;
        }

        if (null === $conditionTwo) {
            return $conditionOne;
        }

        return sprintf('(%s) and (%s)', $conditionOne, $conditionTwo);
    }

    private function getVersionRequirement(): array
    {
        if (empty($this->versions)) {
            return [];
        }

        return ['version' => implode('|', $this->versions)];
    }

    private function hasVersionPlaceholder(string $path): bool
    {
        return false !== strpos($path, '{version}');
    }

    private function includeFormatIfNeeded(string &$path, array &$requirements): void
    {
        if (true === $this->includeFormat) {
            $path .= '.{_format}';

            if (!isset($requirements['_format']) && !empty($this->formats)) {
                $requirements['_format'] = implode('|', array_keys($this->formats));
            }
        }
    }

    private function isMethodReadable(\ReflectionMethod $method): bool
    {
        // if method starts with _ - skip
        if ('_' === substr($method->getName(), 0, 1)) {
            return false;
        }

        $hasNoRouteMethod = (bool) $this->readMethodAnnotation($method, 'NoRoute');
        $hasNoRouteClass = (bool) $this->readClassAnnotation($method->getDeclaringClass(), 'NoRoute');

        $hasNoRoute = $hasNoRouteMethod || $hasNoRouteClass;
        // since NoRoute extends Route we need to exclude all the method NoRoute annotations
        $hasRoute = (bool) $this->readMethodAnnotation($method, 'Route') && !$hasNoRouteMethod;

        // if method has NoRoute annotation and does not have Route annotation - skip
        if ($hasNoRoute && !$hasRoute) {
            return false;
        }

        return true;
    }

    /**
     * @param string[] $resource
     */
    private function getHttpMethodAndResourcesFromMethod(\ReflectionMethod $method, array $resource): ?array
    {
        // if method doesn't match regex - skip
        if (!preg_match('/([a-z][_a-z0-9]+)(.*)Action/', $method->getName(), $matches)) {
            return null;
        }

        $httpMethod = strtolower($matches[1]);
        $resources = preg_split(
            '/([A-Z][^A-Z]*)/',
            $matches[2],
            -1,
            \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE
        );
        $isCollection = false;
        $isInflectable = true;

        if (0 === strpos($httpMethod, self::COLLECTION_ROUTE_PREFIX)
            && \in_array(substr($httpMethod, 1), $this->availableHTTPMethods, true)
        ) {
            $isCollection = true;
            $httpMethod = substr($httpMethod, 1);
        } elseif ('options' === $httpMethod) {
            $isCollection = true;
        }

        if ($isCollection && !empty($resource)) {
            $resourcePluralized = $this->generateResourceName(end($resource));
            $isInflectable = ($resourcePluralized !== $resource[\count($resource) - 1]);
            $resource[\count($resource) - 1] = $resourcePluralized;
        }

        $resources = array_merge($resource, $resources);

        return [$httpMethod, $resources, $isCollection, $isInflectable];
    }

    /**
     * @return \ReflectionParameter[]
     */
    private function getMethodArguments(\ReflectionMethod $method): array
    {
        $params = [];
        if ($this->paramReader) {
            // ignore all query params
            $params = $this->paramReader->getParamsFromMethod($method);
        }

        // check if a parameter is coming from the request body
        $ignoreParameters = [];
        if (class_exists(ParamConverter::class)) {
            $ignoreParameters = array_map(function ($annotation) {
                return
                    $annotation instanceof ParamConverter &&
                    'fos_rest.request_body' === $annotation->getConverter()
                        ? $annotation->getName() : null;
            }, $this->annotationReader->getMethodAnnotations($method));
        }

        // ignore several type hinted arguments
        $ignoreClasses = $this->getIgnoredClasses();

        $supportsUnion = \PHP_VERSION_ID > 80000 && class_exists('\ReflectionUnionType');

        $arguments = [];
        foreach ($method->getParameters() as $argument) {
            if (isset($params[$argument->getName()])) {
                continue;
            }

            try {
                $argumentClass = $argument->getType();
            } catch (\ReflectionException $e) {
                $argumentClass = null;
            }

            if ($argumentClass) {
                if ($supportsUnion && $argumentClass instanceof \ReflectionUnionType) {
                    $types = $argumentClass->getTypes();
                } else {
                    $types = [$argumentClass];
                }

                foreach ($types as $type) {
                    $className = $type->getName();
                    foreach ($ignoreClasses as $class) {
                        if ($className === $class || is_subclass_of($className, $class)) {
                            continue 3;
                        }
                    }
                }
            }

            if (\in_array($argument->getName(), $ignoreParameters, true)) {
                continue;
            }

            $arguments[] = $argument;
        }

        return $arguments;
    }

    private function generateResourceName(string $resource): string
    {
        if (false === $this->pluralize) {
            return $resource;
        }

        return $this->inflector->pluralize($resource);
    }

    /**
     * @param string[] $resources
     */
    private function generateRouteName(array $resources): string
    {
        $routeName = '';
        foreach ($resources as $resource) {
            if (null !== $resource) {
                $routeName .= '_' . basename($resource);
            }
        }

        return $routeName;
    }

    /**
     * @param string[]               $resources
     * @param \ReflectionParameter[] $arguments
     */
    private function generateUrlParts(array $resources, array $arguments, string $httpMethod): array
    {
        $urlParts = [];
        foreach ($resources as $i => $resource) {
            // if we already added all parent routes paths to URL & we have
            // prefix - add it
            if (!empty($this->routePrefix) && $i === \count($this->parents)) {
                $urlParts[] = $this->routePrefix;
            }

            // if we have argument for current resource, then it's object.
            // otherwise - it's collection
            if (isset($arguments[$i])) {
                if (null !== $resource) {
                    $urlParts[] =
                        strtolower($this->generateResourceName($resource))
                        . '/{' . $arguments[$i]->getName() . '}';
                } else {
                    $urlParts[] = '{' . $arguments[$i]->getName() . '}';
                }
            } elseif (null !== $resource) {
                if ((0 === \count($arguments) && !\in_array($httpMethod, $this->availableHTTPMethods, true))
                    || 'new' === $httpMethod
                    || 'post' === $httpMethod
                ) {
                    $urlParts[] = $this->generateResourceName(strtolower($resource));
                } else {
                    $urlParts[] = strtolower($resource);
                }
            }
        }

        return $urlParts;
    }

    /**
     * @param string[]               $resources
     * @param \ReflectionParameter[] $arguments
     */
    private function getCustomHttpMethod(string $httpMethod, array $resources, array $arguments): string
    {
        if (\in_array($httpMethod, $this->availableConventionalActions, true)) {
            // allow hypertext as the engine of application state
            // through conventional GET actions
            return 'get';
        }

        if (\count($arguments) < \count($resources)) {
            // resource collection
            return 'get';
        }

        // custom object
        return 'patch';
    }

    /**
     * @return RouteAnnotation[]
     */
    private function readRouteAnnotation(\ReflectionMethod $reflectionMethod): array
    {
        $annotations = [];

        if ($newAnnotations = $this->readMethodAnnotations($reflectionMethod, 'Route')) {
            $annotations = array_merge($annotations, $newAnnotations);
        }

        return $annotations;
    }

    private function readClassAnnotation(\ReflectionClass $reflectionClass, string $annotationName): ?RouteAnnotation
    {
        $annotationClass = "HandcraftedInTheAlps\\RestRoutingBundle\\Controller\\Annotations\\$annotationName";

        if ($annotation = $this->annotationReader->getClassAnnotation($reflectionClass, $annotationClass)) {
            return $annotation;
        }

        $oldAnnotationClass = "FOS\\RestBundle\\Controller\\Annotations\\$annotationName";

        if ($oldAnnotation = $this->annotationReader->getClassAnnotation($reflectionClass, $oldAnnotationClass)) {
            return $oldAnnotation;
        }

        return null;
    }

    private function readMethodAnnotation(\ReflectionMethod $reflectionMethod, string $annotationName): ?RouteAnnotation
    {
        $annotationClass = "HandcraftedInTheAlps\\RestRoutingBundle\\Controller\\Annotations\\$annotationName";
        $oldAnnotationClass = "FOS\\RestBundle\\Controller\\Annotations\\$annotationName";

        if (class_exists($annotationClass)) {
            if ($annotation = $this->annotationReader->getMethodAnnotation($reflectionMethod, $annotationClass)) {
                return $annotation;
            }
        }

        if (class_exists($oldAnnotationClass)
            && $oldAnnotation = $this->annotationReader->getMethodAnnotation($reflectionMethod, $oldAnnotationClass)
        ) {
            return $oldAnnotation;
        }

        return null;
    }

    /**
     * @return RouteAnnotation[]
     */
    private function readMethodAnnotations(\ReflectionMethod $reflectionMethod, string $annotationName): array
    {
        $annotations = [];
        $annotationClass = "HandcraftedInTheAlps\\RestRoutingBundle\\Controller\\Annotations\\$annotationName";
        $oldAnnotationClass = "FOS\\RestBundle\\Controller\\Annotations\\$annotationName";

        if ($annotations_new = $this->annotationReader->getMethodAnnotations($reflectionMethod)) {
            foreach ($annotations_new as $annotation) {
                if ($annotation instanceof $annotationClass || $annotation instanceof $oldAnnotationClass) {
                    $annotations[] = $annotation;
                }
            }
        }

        return $annotations;
    }

    private function addRoute(RestRouteCollection $collection, string $routeName, Route $route, bool $isCollection, bool $isInflectable, RouteAnnotation $annotation = null): void
    {
        if ($annotation && null !== $annotation->getName()) {
            $options = $annotation->getOptions();

            if (false === $this->hasMethodPrefix || (isset($options['method_prefix']) && false === $options['method_prefix'])) {
                $routeName = $annotation->getName();
            } else {
                $routeName .= $annotation->getName();
            }
        }

        $fullRouteName = $this->namePrefix . $routeName;

        if ($isCollection && !$isInflectable) {
            $collection->add($this->namePrefix . self::COLLECTION_ROUTE_PREFIX . $routeName, $route);
            if (!$collection->get($fullRouteName)) {
                $collection->add($fullRouteName, clone $route);
            }
        } else {
            $collection->add($fullRouteName, $route);
        }
    }
}
