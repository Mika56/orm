<?php

declare(strict_types=1);

namespace Doctrine\ORM\Proxy;

use Closure;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\Common\Proxy\Proxy as CommonProxy;
use Doctrine\Common\Proxy\ProxyDefinition;
use Doctrine\Common\Proxy\ProxyGenerator;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\Proxy\Proxy as LegacyProxy;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Proxy;
use Symfony\Component\VarExporter\ProxyHelper;
use Symfony\Component\VarExporter\VarExporter;

use function array_flip;
use function str_replace;
use function strpos;
use function substr;
use function uksort;

/**
 * This factory is used to create proxy objects for entities at runtime.
 *
 * @psalm-type AutogenerateMode = ProxyFactory::AUTOGENERATE_NEVER|ProxyFactory::AUTOGENERATE_ALWAYS|ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS|ProxyFactory::AUTOGENERATE_EVAL|ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED
 */
class ProxyFactory extends AbstractProxyFactory
{
    private const PROXY_CLASS_TEMPLATE = <<<'EOPHP'
<?php

namespace <namespace>;

/**
 * DO NOT EDIT THIS FILE - IT WAS CREATED BY DOCTRINE'S PROXY GENERATOR
 */
class <proxyShortClassName> extends \<className> implements \<baseProxyInterface>
{
    <useLazyGhostTrait>

    /**
     * @internal
     */
    public bool $__isCloning = false;

    public function __construct(?\Closure $initializer = null)
    {
        self::createLazyGhost($initializer, <skippedProperties>, $this);
    }

    public function __isInitialized(): bool
    {
        return isset($this->lazyObjectState) && $this->isLazyObjectInitialized();
    }

    public function __clone()
    {
        $this->__isCloning = true;

        try {
            $this->__doClone();
        } finally {
            $this->__isCloning = false;
        }
    }

    public function __serialize(): array
    {
        <serializeImpl>
    }
}

EOPHP;

    /** The UnitOfWork this factory uses to retrieve persisters */
    private readonly UnitOfWork $uow;

    /** The IdentifierFlattener used for manipulating identifiers */
    private readonly IdentifierFlattener $identifierFlattener;

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>EntityManager</tt>.
     *
     * @param EntityManagerInterface $em           The EntityManager the new factory works for.
     * @param string                 $proxyDir     The directory to use for the proxy classes. It must exist.
     * @param string                 $proxyNs      The namespace to use for the proxy classes.
     * @param bool|int               $autoGenerate The strategy for automatically generating proxy classes. Possible
     *                                             values are constants of {@see ProxyFactory::AUTOGENERATE_*}.
     * @psalm-param bool|AutogenerateMode $autoGenerate
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        string $proxyDir,
        private readonly string $proxyNs,
        bool|int $autoGenerate = self::AUTOGENERATE_NEVER,
    ) {
        $proxyGenerator = new ProxyGenerator($proxyDir, $proxyNs);

        if ($em->getConfiguration()->isLazyGhostObjectEnabled()) {
            $proxyGenerator->setPlaceholder('baseProxyInterface', Proxy::class);
            $proxyGenerator->setPlaceholder('useLazyGhostTrait', Closure::fromCallable([$this, 'generateUseLazyGhostTrait']));
            $proxyGenerator->setPlaceholder('skippedProperties', Closure::fromCallable([$this, 'generateSkippedProperties']));
            $proxyGenerator->setPlaceholder('serializeImpl', Closure::fromCallable([$this, 'generateSerializeImpl']));
            $proxyGenerator->setProxyClassTemplate(self::PROXY_CLASS_TEMPLATE);
        } else {
            $proxyGenerator->setPlaceholder('baseProxyInterface', LegacyProxy::class);
        }

        parent::__construct($proxyGenerator, $em->getMetadataFactory(), $autoGenerate);

        $this->uow                 = $em->getUnitOfWork();
        $this->identifierFlattener = new IdentifierFlattener($this->uow, $em->getMetadataFactory());
    }

    protected function skipClass(ClassMetadata $metadata): bool
    {
        return $metadata->isMappedSuperclass
            || $metadata->isEmbeddedClass
            || $metadata->getReflectionClass()->isAbstract();
    }

    /**
     * {@inheritDoc}
     */
    protected function createProxyDefinition($className): ProxyDefinition
    {
        $classMetadata   = $this->em->getClassMetadata($className);
        $entityPersister = $this->uow->getEntityPersister($className);

        if ($this->em->getConfiguration()->isLazyGhostObjectEnabled()) {
            $initializer = $this->createLazyInitializer($classMetadata, $entityPersister);
            $cloner      = static function (): void {
            };
        } else {
            $initializer = $this->createInitializer($classMetadata, $entityPersister);
            $cloner      = $this->createCloner($classMetadata, $entityPersister);
        }

        return new ProxyDefinition(
            ClassUtils::generateProxyClassName($className, $this->proxyNs),
            $classMetadata->getIdentifierFieldNames(),
            $classMetadata->getReflectionProperties(),
            $initializer,
            $cloner,
        );
    }

    /**
     * Creates a closure capable of initializing a proxy
     *
     * @psalm-return Closure(CommonProxy):void
     *
     * @throws EntityNotFoundException
     */
    private function createInitializer(ClassMetadata $classMetadata, EntityPersister $entityPersister): Closure
    {
        $wakeupProxy = $classMetadata->getReflectionClass()->hasMethod('__wakeup');

        return function (CommonProxy $proxy) use ($entityPersister, $classMetadata, $wakeupProxy): void {
            $initializer = $proxy->__getInitializer();
            $cloner      = $proxy->__getCloner();

            $proxy->__setInitializer(null);
            $proxy->__setCloner(null);

            if ($proxy->__isInitialized()) {
                return;
            }

            $properties = $proxy->__getLazyProperties();

            foreach ($properties as $propertyName => $property) {
                if (! isset($proxy->$propertyName)) {
                    $proxy->$propertyName = $properties[$propertyName];
                }
            }

            $proxy->__setInitialized(true);

            if ($wakeupProxy) {
                $proxy->__wakeup();
            }

            $identifier = $classMetadata->getIdentifierValues($proxy);

            if ($entityPersister->loadById($identifier, $proxy) === null) {
                $proxy->__setInitializer($initializer);
                $proxy->__setCloner($cloner);
                $proxy->__setInitialized(false);

                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $this->identifierFlattener->flattenIdentifier($classMetadata, $identifier),
                );
            }
        };
    }

    /**
     * Creates a closure capable of initializing a proxy
     *
     * @return Closure(Proxy):void
     *
     * @throws EntityNotFoundException
     */
    private function createLazyInitializer(ClassMetadata $classMetadata, EntityPersister $entityPersister): Closure
    {
        return function (Proxy $proxy) use ($entityPersister, $classMetadata): void {
            $identifier = $classMetadata->getIdentifierValues($proxy);
            $entity     = $entityPersister->loadById($identifier, $proxy->__isCloning ? null : $proxy);

            if ($entity === null) {
                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $this->identifierFlattener->flattenIdentifier($classMetadata, $identifier),
                );
            }

            if (! $proxy->__isCloning) {
                return;
            }

            $class = $entityPersister->getClassMetadata();

            foreach ($class->getReflectionProperties() as $property) {
                if (! $property || ! $class->hasField($property->name) && ! $class->hasAssociation($property->name)) {
                    continue;
                }

                $property->setAccessible(true);
                $property->setValue($proxy, $property->getValue($entity));
            }
        };
    }

    /**
     * Creates a closure capable of finalizing state a cloned proxy
     *
     * @psalm-return Closure(CommonProxy):void
     *
     * @throws EntityNotFoundException
     */
    private function createCloner(ClassMetadata $classMetadata, EntityPersister $entityPersister): Closure
    {
        return function (CommonProxy $proxy) use ($entityPersister, $classMetadata): void {
            if ($proxy->__isInitialized()) {
                return;
            }

            $proxy->__setInitialized(true);
            $proxy->__setInitializer(null);

            $class      = $entityPersister->getClassMetadata();
            $identifier = $classMetadata->getIdentifierValues($proxy);
            $original   = $entityPersister->loadById($identifier);

            if ($original === null) {
                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $this->identifierFlattener->flattenIdentifier($classMetadata, $identifier),
                );
            }

            foreach ($class->getReflectionProperties() as $property) {
                if (! $class->hasField($property->name) && ! $class->hasAssociation($property->name)) {
                    continue;
                }

                $property->setValue($proxy, $property->getValue($original));
            }
        };
    }

    private function generateUseLazyGhostTrait(ClassMetadata $class): string
    {
        $code = ProxyHelper::generateLazyGhost($class->getReflectionClass());
        $code = substr($code, 7 + (int) strpos($code, "\n{"));
        $code = substr($code, 0, (int) strpos($code, "\n}"));
        $code = str_replace('LazyGhostTrait;', str_replace("\n    ", "\n", 'LazyGhostTrait {
            initializeLazyObject as __load;
            setLazyObjectAsInitialized as public __setInitialized;
            isLazyObjectInitialized as private;
            createLazyGhost as private;
            resetLazyObject as private;
            __clone as private __doClone;
        }'), $code);

        return $code;
    }

    private function generateSkippedProperties(ClassMetadata $class): string
    {
        $skippedProperties = ['__isCloning' => true];
        $identifiers       = array_flip($class->getIdentifierFieldNames());

        foreach ($class->getReflectionClass()->getProperties() as $property) {
            $name = $property->getName();

            if ($property->isStatic() || (($class->hasField($name) || $class->hasAssociation($name)) && ! isset($identifiers[$name]))) {
                continue;
            }

            $prefix = $property->isPrivate() ? "\0" . $property->getDeclaringClass()->getName() . "\0" : ($property->isProtected() ? "\0*\0" : '');

            $skippedProperties[$prefix . $name] = true;
        }

        uksort($skippedProperties, 'strnatcmp');

        $code = VarExporter::export($skippedProperties);
        $code = str_replace(VarExporter::export($class->getName()), 'parent::class', $code);
        $code = str_replace("\n", "\n        ", $code);

        return $code;
    }

    private function generateSerializeImpl(ClassMetadata $class): string
    {
        $reflector  = $class->getReflectionClass();
        $properties = $reflector->hasMethod('__serialize') ? 'parent::__serialize()' : '(array) $this';

        $code = '$properties = ' . $properties . ';
        unset($properties["\0" . self::class . "\0lazyObjectState"], $properties[\'__isCloning\']);

        ';

        if ($reflector->hasMethod('__serialize') || ! $reflector->hasMethod('__sleep')) {
            return $code . 'return $properties;';
        }

        return $code . '$data = [];

        foreach (parent::__sleep() as $name) {
            $value = $properties[$k = $name] ?? $properties[$k = "\0*\0$name"] ?? $properties[$k = "\0' . $reflector->getName() . '\0$name"] ?? $k = null;

            if (null === $k) {
                trigger_error(sprintf(\'serialize(): "%s" returned as member variable from __sleep() but does not exist\', $name), \E_USER_NOTICE);
            } else {
                $data[$k] = $value;
            }
        }

        return $data;';
    }
}
