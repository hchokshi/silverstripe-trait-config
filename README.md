# silverstripe-trait-config
Merge SilverStripe configuration from traits into class configuration, while avoiding private static property conflicts.

## Installation
1. Require composer package with `composer require hchokshi/silverstripe-trait-config`.

2. Add `TraitConfigTransformer` to your config generation chain in `/index.php` or `/public/index.php` (4.1+).
```php
<?php

// ...

$kernel = new CoreKernel(BASE_PATH); // Already there from default SilverStripe install
TraitConfigTransformer::addToKernel($kernel); // Add this line
```

## Why?
Traits provide characteristics for classes using them. By default, SilverStripe does not process YAML config from traits and 
PHP limits classes from overriding properties set by traits, meaning if a trait defines a config `private static`, any using 
classes and their subclasses cannot define that config value except by YAML.

This package allows traits to define config by some non-conflicting name in their `private static`s and map them to an actual 
SilverStripe config. This module also merges trait YAML into using classes, which SilverStripe does not do by default.

SilverStripe suggests extensions to achieve the desired behaviour, but this comes with its own set of drawbacks - performance, 
IDE-hinting of method calls, less explicit code. While extensions are still necessary to apply code to classes from vendors etc.,
traits allow your own classes to be more explicit about their behaviour.

## How?
Install the module as instructed under `Installation` above.

Create a trait and use it in your classes. Define `private static` properties as below in your trait. Both the `@internal` and 
`@aliasConfig` annotations must be applied for this module to map a static to a different name. Don't add the `@config` annotation, 
as that will cause SilverStripe to pick up that property's unmapped name/value and merge it into using classes.

Trait private static properties should be named randomly or with some unique prefix so that there's no chance a using class may want 
to actually use a property with the same name.

Traits can define config via mapped `private static` properties or via YAML using aliased names / normal unaliased names. E.g. a trait 
can define `db:` in YAML and it'll be merged into the config for any using classes.

While traits can also define YAML using aliased names, it is recommended to use unaliased names in YAML as there is no naming conflict and 
YAML does not have the inline information about where the value will be merged like `@aliasConfig` provides for `private static` variables.
If a config value is defined using aliased and unaliased names, the unaliased name will be merged with a higher priority.

### Example:
In the example below, `MyTrait`'s database columns will be merged into `MyObject`.

```php
<?php

trait MyTrait {
    /**
     * @internal
     * @aliasConfig $db
     * @var array 
     */
    private static $_mytrait_db = [ // This could be called $a09sdf0asfoiasdfj - "@aliasConfig $db" is the important part
        // ...
    ];
}

class MyObject extends DataObject {
    use MyTrait;
    
    private static $db = [
        // ...
    ];
}
```

### Priority:
Configuration is merged in the same way as SilverStripe's normal config system (arrays are merged, higher priority scalar values overrule arrays).

Configuration sources for a class are prioritised in the following order, from highest to lowest:

1. Class config
  - Class YAML
  - Class private statics
2. Trait config
  - Trait YAML with unmapped config name (e.g. `db:` in a trait's YAML)
  - Trait YAML with mapped names (e.g. `jdalsfjlafdfl_db:` in a trait's YAML, where the trait has a `private static $jdalsfjlafdfl_db` with `@internal @aliasConfig $db` annotations.)
  - Trait private statics
  - Nested trait config (e.g. if `Trait1` has `use Trait2`, `Trait2` config is merged in "Trait config" priority)
