<?php
namespace EzMvc;

/**
 * Service container with explicit registration and three lifetimes.
 *
 * PHP is shared-nothing: the process starts at index.php and dies after the
 * response, so a singleton cannot outlive a request. In web dispatch the root
 * container IS the request scope, which makes SINGLETON and SCOPED yield the
 * same object. The distinction pays off where one process runs several units
 * of work — a cron runner or a CLI batch — and calls createScope() per unit,
 * keeping the connection singleton while each unit gets its own services.
 *
 * Registration is mandatory: get() on an unregistered id throws. Controllers
 * are the exception — index.php builds them with create(), which constructs an
 * unregistered class and resolves only its constructor arguments from here.
 *
 * Logger and MailSender come pre-registered, resolving to whatever the core's
 * own facades currently hold. Registering either throws in debug mode and is
 * ignored with a warning otherwise: a second registration would give services
 * one instance and the core another.
 */
class Container {

    const TRANSIENT = 'transient';
    const SCOPED    = 'scoped';
    const SINGLETON = 'singleton';

    private const FACADE_SETTERS = [
        Logger::class     => 'Core::setLogger()',
        MailSender::class => 'Mail::setSender()',
    ];

    private static ?Container $root = null;

    /** Constructor plans keyed by class name, shared by every scope. */
    private static array $plans = [];

    private ?Container $parent;
    private array $registrations = [];
    private array $instances     = [];
    private array $resolving     = [];

    /** Use root() or createScope() instead of constructing one directly. */
    public function __construct(?Container $parent = null) {
        $this->parent = $parent;
    }

    /**
     * The root scope. In a web request this is also the request scope, so
     * app.php registers against it and index.php resolves from it.
     */
    public static function root(): Container {
        if (self::$root === null) {
            self::$root = new Container();
            // transient, so an injected instance follows a later set*() call
            self::$root->registrations = [
                Logger::class     => ['lifetime' => self::TRANSIENT, 'concrete' => fn() => Core::logger()],
                MailSender::class => ['lifetime' => self::TRANSIENT, 'concrete' => fn() => Mail::sender()],
            ];
        }
        return self::$root;
    }

    /**
     * Drops the root scope and every cached constructor plan. For tests that
     * need a clean container between cases.
     */
    public static function reset(): void {
        self::$root  = null;
        self::$plans = [];
    }

    /**
     * A child scope. Services registered scoped resolve to one instance per
     * scope; singletons still come from the root.
     */
    public function createScope(): Container {
        return new Container($this);
    }

    /**
     * One instance for the whole process, built on the root scope even when
     * first requested from a child.
     *
     * @param string $id class or interface name to resolve by
     * @param string|\Closure|object|null $concrete implementation class, a
     *        factory receiving this Container, an already-built object, or
     *        null to construct $id itself
     */
    public function addSingleton(string $id, $concrete = null): Container {
        return $this->add(self::SINGLETON, $id, $concrete);
    }

    /**
     * One instance per scope. See addSingleton() for $concrete.
     */
    public function addScoped(string $id, $concrete = null): Container {
        return $this->add(self::SCOPED, $id, $concrete);
    }

    /**
     * A fresh instance on every get(). See addSingleton() for $concrete.
     */
    public function addTransient(string $id, $concrete = null): Container {
        return $this->add(self::TRANSIENT, $id, $concrete);
    }

    private function add(string $lifetime, string $id, $concrete): Container {
        $id = ltrim($id, '\\');
        if (isset(self::FACADE_SETTERS[$id])) {
            $message = "{$id} cannot be registered: it is owned by " . self::FACADE_SETTERS[$id] . ', which the '
                . 'core uses too. Call that in app.php; type-hinting ' . $id . ' then injects the same instance.';
            if (Core::isDebug()) {
                throw new \LogicException($message);
            }
            Core::log($message . ' The registration was ignored.', LogLevel::WARNING, 'Container');
            return $this;
        }
        $this->registrations[$id] =['lifetime' => $lifetime, 'concrete' => $concrete ?? $id];
        return $this;
    }

    /**
     * Whether $id is registered in this scope or any ancestor.
     */
    public function has(string $id): bool {
        return $this->findRegistration(ltrim($id, '\\')) !== null;
    }

    /**
     * The instance registered for $id, honoring its lifetime.
     * @throws \LogicException when nothing is registered for $id
     */
    public function get(string $id) {
        $id = ltrim($id, '\\');
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        $registration = $this->findRegistration($id);
        if ($registration === null) {
            throw new \LogicException(
                "Nothing registered for {$id}. Add \$container->addScoped({$id}::class) to app.php."
            );
        }
        return $this->resolve($id, $registration);
    }

    /**
     * Builds an unregistered class, resolving its constructor arguments from
     * the container. Nothing is cached — every call constructs a new object.
     * This is how controllers are built: the controller class itself needs no
     * registration, its dependencies do.
     */
    public function create(string $class): object {
        return $this->construct(ltrim($class, '\\'));
    }

    private function findRegistration(string $id): ?array {
        for ($scope = $this; $scope !== null; $scope = $scope->parent) {
            if (isset($scope->registrations[$id])) {
                return $scope->registrations[$id];
            }
        }
        return null;
    }

    private function rootScope(): Container {
        $scope = $this;
        while ($scope->parent !== null) {
            $scope = $scope->parent;
        }
        return $scope;
    }

    private function resolve(string $id, array $registration) {
        $lifetime = $registration['lifetime'];
        if ($lifetime === self::SINGLETON) {
            $root = $this->rootScope();
            if ($root !== $this) {
                return $root->get($id);
            }
        }

        if (isset($this->resolving[$id])) {
            throw new \LogicException(
                'Circular dependency: ' . implode(' -> ', array_keys($this->resolving)) . ' -> ' . $id
            );
        }
        $this->resolving[$id] = true;
        try {
            $instance = $this->build($id, $registration);
        } finally {
            unset($this->resolving[$id]);
        }

        if ($lifetime !== self::TRANSIENT) {
            $this->instances[$id] = $instance;
        }
        return $instance;
    }

    private function build(string $id, array $registration) {
        $concrete = $registration['concrete'];
        if ($concrete instanceof \Closure) {
            return $concrete($this);
        }
        if (is_object($concrete)) {
            return $concrete;
        }
        if ($registration['lifetime'] === self::SINGLETON && Core::isDebug()) {
            $this->assertNoCapturedScopedDependency($concrete, $id);
        }
        return $this->construct($concrete);
    }

    /**
     * A singleton holding a scoped dependency pins that dependency for the
     * life of the process, so later scopes silently share the first scope's
     * instance. Debug-only: the check costs a plan walk per singleton built.
     */
    private function assertNoCapturedScopedDependency(string $class, string $id): void {
        foreach (self::plan($class) ?? [] as $parameter) {
            $dependency = $parameter['id'] === null ? null : $this->findRegistration($parameter['id']);
            if ($dependency && $dependency['lifetime'] === self::SCOPED) {
                throw new \LogicException(
                    "Singleton {$id} depends on scoped {$parameter['id']}, which would capture the first "
                    . "scope's instance for every later scope. Register one of them differently."
                );
            }
        }
    }

    private function construct(string $class): object {
        $plan = self::plan($class);
        if ($plan === null) {
            return new $class();
        }
        $arguments = [];
        foreach ($plan as $parameter) {
            if ($parameter['id'] !== null && $this->has($parameter['id'])) {
                $arguments[] = $this->get($parameter['id']);
            } elseif ($parameter['hasDefault']) {
                $arguments[] = $parameter['default'];
            } else {
                throw new \LogicException(
                    "Cannot resolve \${$parameter['name']} of {$class}::__construct(): "
                    . ($parameter['id'] === null
                        ? 'it has no class type hint and no default value.'
                        : "nothing registered for {$parameter['id']}.")
                );
            }
        }
        return new $class(...$arguments);
    }

    /**
     * The constructor's parameters reduced to what construct() needs, so a
     * class is reflected once per process rather than once per instance.
     * Null means the class takes no constructor arguments.
     */
    private static function plan(string $class): ?array {
        if (array_key_exists($class, self::$plans)) {
            return self::$plans[$class];
        }
        if (! class_exists($class)) {
            throw new \LogicException("Cannot construct {$class}: no such class.");
        }
        $reflection = new \ReflectionClass($class);
        if (! $reflection->isInstantiable()) {
            throw new \LogicException(
                "Cannot construct {$class}: it is abstract or an interface. Register a concrete "
                . "implementation for it, e.g. \$container->addScoped({$class}::class, MyImpl::class)."
            );
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return self::$plans[$class] = null;
        }

        $plan = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type   = $parameter->getType();
            $plan[] = [
                'name' => $parameter->getName(),
                // a union or builtin type carries no id to resolve by
                'id' => $type instanceof \ReflectionNamedType && ! $type->isBuiltin()
                    ? ltrim($type->getName(), '\\')
                    : null,
                'hasDefault' => $parameter->isDefaultValueAvailable(),
                'default'    => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            ];
        }
        return self::$plans[$class] = $plan;
    }

}
