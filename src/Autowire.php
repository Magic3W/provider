<?php namespace spitfire\provider;

use ReflectionClass as RC;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use spitfire\provider\attributes\DefaultImplementation;

class Autowire
{
	
	/**
	 * The container to look in for components that may be needed for autowiring
	 * the current component.
	 *
	 * @var Container
	 */
	private Container $container;
	
	public function __construct(Container $container)
	{
		$this->container = $container;
	}
	
	/**
	 *
	 * @template T of Object
	 * @param RC<T> $reflection
	 * @param mixed[] $overrides
	 * @return T
	 */
	public function class(RC $reflection, array $overrides = []) : object
	{
		try {
			$method = $reflection->getMethod('__construct');
			if ($method === null) { return $reflection->newInstance(); }
			return $this->makeClassWithParameters($reflection, $method->getParameters(), $overrides);
		}
		catch (ReflectionException $e) {
			return $reflection->newInstance();
		}
	}
	
	/**
	 * @template T of Object
	 * @param RC<T> $reflection
	 * @param ReflectionParameter[] $params
	 * @param mixed[] $overrides
	 * @return T
	 */
	private function makeClassWithParameters(RC $reflection, array $params = [], array $overrides = []) : object
	{
		$parameters = array_map(function (ReflectionParameter $e) use ($overrides) {
			$name  = $e->getName();
			
			if (!array_key_exists($name, $overrides)) {
				return $this->argument($e);
			}
			
			return $overrides[$name];
		}, $params);
		
		return $reflection->newInstance(...$parameters);
	}
	
	/**
	 * @return mixed
	 */
	public function argument(ReflectionParameter $e)
	{
		$name  = $e->getName();
		
		# Container will only resolve required parameters. This means that if the param
		# is optional, we will not feel compelled to resolve it.
		if ($e->isDefaultValueAvailable()) {
			return $e->getDefaultValue();
		}
		
		try {
			$type = $e->getType();
			
			/**
			 * PHP doesn't require the developer of a class to explicitly determine the
			 * types of the arguments. If this is the case, we cannot help the instancing
			 * of the class beyond using a default if available.
			 */
			if (!($type instanceof ReflectionNamedType)) {
				throw new NotFoundException('Anonymous types cannot be resolved');
			}
			
			$name = $type->getName();
			
			/**
			 * If the class we're trying to locate is unavailable, we will not continue, since
			 * it will obviously produce no valid result.
			 */
			if (!class_exists($name) && !interface_exists($name)) {
				throw new NotFoundException(sprintf("Service %s was not found", $name));
			}
			
			try {
				return $this->container->get($name);
			}
			catch (NotFoundException $e) {
				return $this->findImplementation($name);
			}
		} catch (ReflectionException $e) {
			throw new NotFoundException($e->getMessage());
		}
	}
	
	/**
	 *
	 * @template T of object
	 * @param class-string<T> $interfaceName
	 * @return T
	 */
	private function findImplementation(string $interfaceName)
	{
		
		$attribute  = DefaultImplementation::for($interfaceName);
		
		if ($attribute === null) {
			throw new NotFoundException(sprintf('Service %s was not found', $interfaceName), 0);
		}
		
		return $this->container->get($attribute->getImplementation());
	}
}
