<?php
declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use Exception;

/**
 * Contenedor de Inyeccion de Dependencias PSR-11 ligero para PHP 8.
 * Soporta autowiring mediante Reflection API.
 */
class Container {
    private static ?Container $instance = null;
    private array $instances = [];
    private array $bindings = [];

    public static function getInstance(): Container {
        if (self::$instance === null) {
            self::$instance = new Container();
        }
        return self::$instance;
    }

    public static function setInstance(?Container $container): void {
        self::$instance = $container;
    }

    public function set(string $id, object $concrete): void {
        $this->instances[$id] = $concrete;
    }

    public function bind(string $abstract, callable|string $factory): void {
        $this->bindings[$abstract] = $factory;
    }

    public function has(string $id): bool {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    public function get(string $id): object {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $factory = $this->bindings[$id];
            $object = is_callable($factory) ? $factory($this) : $this->get($factory);
            $this->instances[$id] = $object;
            return $object;
        }

        if (!class_exists($id)) {
            throw new Exception("Core Container: Clase no encontrada '{$id}'.");
        }

        $reflector = new ReflectionClass($id);
        if (!$reflector->isInstantiable()) {
            throw new Exception("Core Container: La clase '{$id}' no es instanciable.");
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            $object = new $id();
            $this->instances[$id] = $object;
            return $object;
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type && !$type->isBuiltin()) {
                $dependencyClassName = $type->getName();
                $dependencies[] = $this->get($dependencyClassName);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $dependencies[] = null;
            } else {
                throw new Exception("Core Container: No se pudo resolver el parámetro '{$parameter->getName()}' para la clase '{$id}'.");
            }
        }

        $object = $reflector->newInstanceArgs($dependencies);
        return $object;
    }
}
