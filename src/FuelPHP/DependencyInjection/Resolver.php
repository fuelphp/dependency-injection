<?php

namespace FuelPHP\DependencyInjection;

abstract class Resolver
{
	/**
	 * @var  boolean  $allowSingleton  wether to allow singletons
	 */
	protected $allowSingleton = true;

	/**
	 * @var  boolean  $preferSingleton  wether to prefer singletons
	 */
	protected $preferSingleton = false;

	/**
	 * @var  FuelPHP\DependencyInjection\Container  $container  container
	 */
	protected $container;

	/**
	 * @var  string  $root  resolver root
	 */
	public $root;

	/**
	 * @var  array  $methodInjections  method injections
	 */
	protected $methodInjections = array();

	/**
	 * @var  boolean  $resolveParamAlias  wether to resolve named parameters
	 */
	protected $resolveParamAlias = true;

	/**
	 * @var  boolean  $resolveParamClass  wether to resolve named parameters
	 */
	protected $resolveParamClass = true;

	/**
	 * Sets the Container
	 *
	 * @param   FuelPHP\DependencyInjection\Container  $container  container
	 * @return  $this
	 */
	public function setContainer(Container $container)
	{
		$this->container = $container;

		return $this;
	}

	/**
	 * Sets the identifier root
	 *
	 * @param   string  $root  root
	 * @return  $this
	 */
	public function setRoot($root)
	{
		$this->root = $root;

		return $this;
	}

	/**
	 * Block singletons
	 *
	 * @param   boolean  $allow  wether to block singletons
	 * @return  $this
	 */
	public function blockSingleton($allow = true)
	{
		$this->allowSingleton = ! $allow;

		return $this;
	}

	/**
	 * Allow singletons
	 *
	 * @param   boolean  $allow  wether to allow singletons
	 * @return  $this
	 */
	public function allowSingleton($allow = true)
	{
		$this->allowSingleton = $allow;

		return $this;
	}

	/**
	 * Set wether singletons are prefered
	 *
	 * @param   boolean  $prefer  wether to prefer singletons
	 * @return  $this
	 */
	public function preferSingleton($prefer = true)
	{
		$this->preferSingleton = $prefer;

		return $this;
	}

	/**
	 * Prepare the identifiers based on singleton preference
	 *
	 * @param   string  $identifier  identifier
	 * @param   string  $name        instance name
	 * @return  void
	 */
	protected function prepareIdentifiers($identifier, &$name)
	{
		if ( ! $this->allowSingleton and $identifier === $name)
		{
			throw new ResolveException($identifier.' does not allow singleton usage.');
		}

		if ($name === null)
		{
			$name = $this->preferSingleton ? $identifier : false;
		}
	}

	/**
	 * Resolve a dependency
	 *
	 * @param   string  $identifier  identifier
	 * @param   string  $name        instance name
	 * @param   array   $arguments   arguments
	 * @return  object  resolved dependency
	 * @throws  FuelPHP\DependencyInjection\ResolveException
	 */
	public function resolve($identifier, $name, $arguments)
	{
		// Get singleton preferences.
		$this->prepareIdentifiers($identifier, $name);

		// Strip the root from the identifier
		$suffix = substr($identifier, strlen($this->root));

		if ($suffix)
		{
			$suffix = trim($suffix, '.\\');
		}

		$result = $this->forge($this->container, $suffix ?: null, $arguments);

		// Forge can return a string, in which case we'll need
		// to convert that into a class.
		$result = $this->formatOutput($result, $arguments);

		// Resolve method injections.
		if ( ! empty($this->methodInjections))
			$this->injectMethodDependencies($result);

		if ($name)
		{
			// Cache named instances
			$this->container->inject($identifier, $result, $name);
		}

		return $result;
	}

	/**
	 * Ensure an an object instance.
	 *
	 * @param   mixed   $result     forge result
	 * @param   array   $arguments  arguments
	 * @return  object  resolved dependency
	 * @throws  ResolveException
	 */
	protected function formatOutput($result, $arguments)
	{
		if (is_callable($result))
		{
			$result = call_user_func($result, $this->container);
		}

		if (is_object($result))
		{
			return $result;
		}

		if (is_string($result) and class_exists($result, true))
		{
			return $this->createInstance($result, $arguments);
		}

		throw new ResolveException('Could not resolve, unexpected output output: '.print_r($result, true));
	}

	/**
	 * Resolve a reflection parameter depencency
	 *
	 * @param   ReflectionParameter  $param  relfection parameter
	 * @return  object               resolved dependency
	 * @throws  ResolveException
	 */
	public function resolveParameter($param)
	{
		if ( ! $param instanceof \ReflectionParameter)
		{
			return $param;
		}

		if ($this->resolveParamAlias)
		{
			$identifier = $param->getName();
			$name = null;

			// Resolve param alias
			if (isset($this->paramAlias[$identifier]))
			{
				list($identifier, $name) = $this->paramAlias[$identifier];
			}

			try
			{
				return $this->container->resolve($identifier, $name);
			}
			catch (ResolveException $e)
			{
				// Let this exception fly, there are
				// other ways to retrieve the dependency
			}
		}

		if ($this->resolveParamClass and $class = $param->getClass())
		{
			try
			{
				return $this->container->resolve($class->getName());
			}
			catch(ResolveException $e) {}
		}

		// We'll fall back to a default value
		if ($param->isDefaultValueAvailable())
		{
			return $param->getDefaultValue();
		}

		// We're not in luck, resolving has failed
		$class = $param->getDeclaringClass()->getName();
		throw new ResolveException('Could not resolve parameter '.$param->getName().' for '.$class);
	}

	/**
	 * Set wether to resolve params by class hinting
	 *
	 * @param   boolean  $resolve  wether to resolve by class hinting
	 * @return  $this
	 */
	public function resolveParamClass($resolve = true)
	{
		$this->resolveParamClass = $resolve;

		return $this;
	}

	/**
	 * Set wether to resolve params by name
	 *
	 * @param   boolean  $resolve  wether to resolve by name
	 * @return  $this
	 */
	public function resolveParamAlias($resolve = true)
	{
		$this->resolveParamAlias = $resolve;

		return $this;
	}

	/**
	 * Resolve class dependencies.
	 *
	 * @param   string  $class      class name
	 * @param   array   $arguments  arguments
	 * @return  object  resolved object with injected dependencies
	 */
	protected function createInstance($class, $arguments)
	{
		if ( ! $this->resolveParamAlias and ! $this->resolveParamClass)
		{
			return new $class;
		}

		$reflection = new \ReflectionClass($class);
		$constructor = $reflection->getConstructor();

		if ( ! $constructor)
		{
			// In this case there are no dependencies to inject
			// So we can just return a new instance
			return $reflection->newInstance();
		}

		// Get the reflection parameters
		$parameters = $constructor->getParameters();

		// And replace any that are given
		foreach (array_values($arguments) as $position => $argument)
		{
			$parameters[$position] = $argument;
		}

		$resolver = array($this, 'resolveParameter');
		$params = array_map($resolver, $parameters);

		return $reflection->newInstanceArgs($params);
	}

	/**
	 * Add an injector method.
	 *
	 * @param   string  $method      injection method
	 * @param   string  $identifier  dependency identifier
	 * @param   string  $name        instance name
	 * @return  $this
	 */
	public function methodInjection($method, $identifier, $name = null)
	{
		$this->methodInjections[$method] = array($identifier, $name);

		return $this;
	}

	/**
	 * Inject method dependencies.
	 *
	 * @param   object  $instance  dependency
	 */
	protected function injectMethodDependencies(&$instance)
	{
		foreach ($this->methodInjections as $method => $injection)
		{
			list($dependency, $name) = $injection;

			if (is_string($dependency))
			{
				$dependency = $this->container->resolve($dependency, $name);
			}

			$instance->{$method}($dependency);
		}
	}

	/**
	 * Add an named parameter injection.
	 *
	 * @param   string  $method      injection method
	 * @param   string  $identifier  dependency identifier
	 * @param   string  $name        instance name
	 * @return  $this
	 */
	public function aliasParam($param, $identifier, $name = null)
	{
		$this->paramAlias[$param] = array($identifier, $name);

		return $this;
	}
}