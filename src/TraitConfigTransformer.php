<?php

namespace hchokshi\SilverStripe\TraitConfig;

use ReflectionClass;
use ReflectionProperty;
use SilverStripe\Config\Collections\CachedConfigCollection;
use SilverStripe\Config\Collections\ConfigCollectionInterface;
use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Config\Collections\MutableConfigCollectionInterface;
use SilverStripe\Config\MergeStrategy\Priority;
use SilverStripe\Config\Transformer\PrivateStaticTransformer;
use SilverStripe\Config\Transformer\TransformerInterface;
use SilverStripe\Core\Kernel;
use SilverStripe\Core\Manifest\ClassLoader;

/**
 * SilverStripe config transformer that takes configuration from traits and merges it into using classes, using aliases
 * to avoid private static conflicts.
 * @package hchokshi\SilverStripe\TraitConfig
 */
class TraitConfigTransformer implements TransformerInterface
{
    const ALIAS_PHPDOC = '@aliasConfig';

    /**
     * @var array
     */
    protected $traitConfigCache = [];

    /**
     * @var array
     */
    protected $ownStaticCache = [];

    /**
     * @var array
     */
    protected $configMapCache = [];

    /**
     * @var array|callable
     */
    protected $classes;

    /**
     * @param array|callable $classes List of classes, or callback to lazy-load.
     */
    public function __construct($classes)
    {
        $this->classes = $classes;
    }

    /**
     * Add TraitConfigTransformer to a kernel's config manifest.
     * @param Kernel $kernel
     */
    public static function addToKernel(Kernel $kernel)
    {
        /** @var CachedConfigCollection $config */
        $config = $kernel->getConfigLoader()->getManifest();
        $existingCollectionCreator = $config->getCollectionCreator();

        $config->setCollectionCreator(function () use ($existingCollectionCreator) {
            /** @var MemoryConfigCollection $existingCollection */
            $existingCollection = $existingCollectionCreator();
            return $existingCollection->transform([
                new static(function () {
                    return ClassLoader::inst()->getManifest()->getClassNames();
                }),
            ]);
        });
    }

    /**
     * @inheritDoc
     */
    public function transform(MutableConfigCollectionInterface $collection)
    {
        foreach ($this->getClasses() as $class) {
            try {
                $reflectionClass = new ReflectionClass($class);

                foreach ($reflectionClass->getTraits() as $trait) {
                    $this->mergeTraitConfig($collection, $class, $trait);
                }
            } catch (\ReflectionException $e) {
                // Class doesn't exist
                continue;
            }
        }

        return $collection;
    }

    /**
     * Get list of defined classes in the manifest.
     * @see PrivateStaticTransformer::getClasses()
     * @return array
     */
    protected function getClasses()
    {
        if (is_callable($this->classes)) {
            $this->classes = call_user_func($this->classes);
        }

        return $this->classes;
    }

    /**
     * Merge a used trait's config into $class's config.
     * @param MutableConfigCollectionInterface $collection
     * @param $class
     * @param ReflectionClass $trait
     */
    protected function mergeTraitConfig(MutableConfigCollectionInterface $collection, $class, ReflectionClass $trait)
    {
        $traitConfig = $this->getTraitConfig($collection, $trait);
        if (empty($traitConfig)) return;

        // Merge in trait config, giving the class a higher priority
        $classConfig = Priority::mergeArray(
            $collection->get($class, null, true),
            $traitConfig
        );

        $collection->set($class, null, $classConfig, [
            'from_trait' => $trait->getName(),
        ]);
    }

    /**
     * Get the config for a trait to be merged into using class, with aliasing applied.
     * @param ConfigCollectionInterface $collection
     * @param ReflectionClass $trait
     * @return array
     */
    protected function getTraitConfig(ConfigCollectionInterface $collection, ReflectionClass $trait)
    {
        return $this->getCachedOrCall($this->traitConfigCache, $trait->getName(),
            function () use ($trait, $collection) {
                $configFromNestedTraits = [];

                foreach ($trait->getTraits() as $nestedTrait) {
                    $configFromNestedTraits = Priority::mergeArray(
                        $this->getTraitConfig($collection, $nestedTrait),
                        $configFromNestedTraits
                    );
                }

                $ownYaml = $collection->get($trait->getName(), null, true);
                $ownMappedYaml = $this->applyTraitConfigAliases($trait, $ownYaml);
                $ownMappedStatics = $this->applyTraitConfigAliases($trait, $this->getTraitOwnAliasedStatics($collection, $trait));

                // Merge trait YAML over trait statics
                $traitConfig = Priority::mergeArray(
                    $ownMappedYaml,
                    $ownMappedStatics
                );

                // Merge trait's owm config over nested trait config
                return Priority::mergeArray($traitConfig, $configFromNestedTraits);
            });
    }

    /**
     * Get a cached value, transparently calling a function and caching the result if not already cached.
     * @param array $cache
     * @param string $cacheKey
     * @param callable $source
     * @return mixed
     */
    protected function getCachedOrCall(array &$cache, $cacheKey, callable $source)
    {
        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = $source();
        }

        return $cache[$cacheKey];
    }

    /**
     * Apply aliases to a trait's config.
     * @param ReflectionClass $trait
     * @param array $config
     * @return array
     */
    protected function applyTraitConfigAliases(ReflectionClass $trait, array $config)
    {
        $mappedValues = [];
        $unmappedValues = [];
        $map = $this->getTraitConfigMap($trait);

        foreach ($config as $source => $value) {
            if (!isset($map[$source])) {
                // Unmapped is simple - it can only be defined once per config. Set and move on.
                $unmappedValues[$source] = $value;
                continue;
            }

            $dest = $map[$source];

            if (isset($map[$dest])) {
                // Another value was already mapped to $dest - merge current over it.
                $mappedValues = Priority::mergeArray([
                    $dest => $value,
                ], $mappedValues);
            } else {
                $mappedValues[$dest] = $value;
            }
        }

        // When a value is defined as both its proper name and by an aliased name, prioritise the explicit name over the alias.
        return Priority::mergeArray($unmappedValues, $mappedValues);
    }

    /**
     * Get a map of (trait config name) => (actual config name).
     * @param ReflectionClass $trait
     * @return array
     */
    protected function getTraitConfigMap(ReflectionClass $trait)
    {
        return $this->getCachedOrCall($this->configMapCache, $trait->getName(),
            function () use ($trait) {
                $configMap = [];

                foreach ($this->getAliasedConfigProperties($trait) as $property) {
                    $mergeInto = $this->getAliasedConfigName($property);
                    if ($mergeInto !== null) {
                        $configMap[$property->getName()] = $mergeInto;
                    }
                }

                return $configMap;
            });
    }

    /**
     * Get the config private static properties for a trait that are aliased.
     * @param ReflectionClass $trait
     * @return ReflectionProperty[]
     */
    protected function getAliasedConfigProperties(ReflectionClass $trait)
    {
        $properties = [];

        foreach ($trait->getProperties(ReflectionProperty::IS_PRIVATE | ReflectionProperty::IS_STATIC) as $property) {
            if ($this->isConfigAliasProperty($property)) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * Check if a property is a config alias.
     * @param ReflectionProperty $prop
     * @return bool
     */
    protected function isConfigAliasProperty(ReflectionProperty $prop)
    {
        $docComment = $prop->getDocComment();
        return $prop->isPrivate() &&
            strpos($docComment, '@internal') !== false &&
            strpos($docComment, static::ALIAS_PHPDOC) !== false;
    }

    /**
     * Determine the config name a trait config property maps to.
     * @param ReflectionProperty $property
     * @return string|null Config name to map property value to, or null to ignore property.
     */
    protected function getAliasedConfigName(ReflectionProperty $property)
    {
        if (preg_match('/' . static::ALIAS_PHPDOC . '(\s+)\$([^\s]+)/', $property->getDocComment(), $matches)) {
            foreach (token_get_all("<?php {$matches[0]}") as $token) {
                if ($token[0] === T_VARIABLE) {
                    // Strip $ from front of variable
                    return substr($token[1], 1);
                }
            }
        }

        return null;
    }

    /**
     * Get the config properties defined by a trait, ignoring properties defined by nested traits.
     * @param ConfigCollectionInterface $collection
     * @param ReflectionClass $trait
     * @return array
     */
    protected function getTraitOwnAliasedStatics(ConfigCollectionInterface $collection, ReflectionClass $trait)
    {
        return $this->getCachedOrCall($this->ownStaticCache, $trait->getName(),
            function () use ($trait, $collection) {
                $ownStatics = [];
                $nestedTraitStatics = [];

                foreach ($trait->getTraits() as $nestedTrait) {
                    $nestedTraitStatics = Priority::mergeArray(
                        $this->getTraitOwnAliasedStatics($collection, $nestedTrait),
                        $nestedTraitStatics
                    );
                }

                foreach ($this->getAliasedConfigProperties($trait) as $property) {
                    $propName = $property->getName();
                    $property->setAccessible(true);

                    if (isset($nestedTraitStatics[$propName]) || !$this->isConfigValue($property->getValue())) {
                        // Skip non-config values and nested trait statics
                        continue;
                    }

                    $ownStatics[$propName] = $property->getValue();
                }

                return $ownStatics;
            });
    }

    /**
     * Detect if a value is a valid config value.
     * @see PrivateStaticTransformer::isConfigValue()
     * @param mixed $input
     * @return true
     */
    protected function isConfigValue($input)
    {
        if (is_array($input)) {
            foreach ($input as $next) {
                if (!$this->isConfigValue($next)) {
                    return false;
                }
            }
        }

        return !is_object($input) && !is_resource($input);
    }
}
