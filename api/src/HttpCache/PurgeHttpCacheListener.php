<?php

/*
 * Это переопределенный слушатель сброса кэширования, в него просто добавлено удаление GetCollection родительской сущности
 */

declare(strict_types=1);

namespace App\HttpCache;

use ApiPlatform\Api\IriConverterInterface as LegacyIriConverterInterface;
use ApiPlatform\Api\ResourceClassResolverInterface as LegacyResourceClassResolverInterface;
use ApiPlatform\Exception\InvalidArgumentException;
use ApiPlatform\Exception\OperationNotFoundException;
use ApiPlatform\Exception\RuntimeException;
use ApiPlatform\HttpCache\PurgerInterface;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\Metadata\Util\ClassInfoTrait;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\PersistentCollection;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Purges responses containing modified entities from the proxy cache.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class PurgeHttpCacheListener
{
    use ClassInfoTrait;
    private readonly PropertyAccessorInterface $propertyAccessor;

    private array $tags = [];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'api_platform.http_cache.purger.souin')]
        private readonly PurgerInterface $purger,
        private readonly IriConverterInterface | LegacyIriConverterInterface $iriConverter,
        private readonly ResourceClassResolverInterface | LegacyResourceClassResolverInterface $resourceClassResolver,
        private readonly ?LoggerInterface $logger,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
        ?PropertyAccessorInterface $propertyAccessor = null, private readonly ?string $env = null)
    {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    /**
     * Collects tags from the previous and the current version of the updated entities to purge related documents.
     */
    public function preUpdate(PreUpdateEventArgs $eventArgs): void
    {
        if ($this->env === 'test') {
            return;
        }

        $object = $eventArgs->getObject();
        $this->gatherResourceAndItemTags($object, true);

        $changeSet = $eventArgs->getEntityChangeSet();
        $objectManager = method_exists($eventArgs, 'getObjectManager') ? $eventArgs->getObjectManager() : $eventArgs->getEntityManager();
        $associationMappings = $objectManager->getClassMetadata(ClassUtils::getClass($eventArgs->getObject()))->getAssociationMappings();

        foreach ($changeSet as $key => $value) {
            if (!isset($associationMappings[$key])) {
                continue;
            }

            $this->addTagsFor($value[0]);
            $this->addTagsFor($value[1]);
        }
    }

    /**
     * Collects tags from inserted and deleted entities, including relations.
     */
    public function onFlush(OnFlushEventArgs $eventArgs): void
    {
        if ($this->env === 'test') {
            return;
        }

        $em = method_exists($eventArgs, 'getObjectManager') ? $eventArgs->getObjectManager() : $eventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->gatherResourceAndItemTags($entity, false);
            $this->gatherRelationTags($em, $entity);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->gatherResourceAndItemTags($entity, true);
            $this->gatherRelationTags($em, $entity);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->gatherResourceAndItemTags($entity, true);
            $this->gatherRelationTags($em, $entity);
        }
    }

    /**
     * Purges tags collected during this request, and clears the tag list.
     */
    public function postFlush(): void
    {
        if ($this->env === 'test') {
            return;
        }

        if ($this->tags === []) {
            return;
        }

        $this->purger->purge(array_values($this->tags));

        $this->tags = [];
    }

    private function gatherResourceAndItemTags(object $entity, bool $purgeItem): void
    {
        try {
            $resourceClass = $this->resourceClassResolver->getResourceClass($entity);
            $iri = $this->iriConverter->getIriFromResource($resourceClass, UrlGeneratorInterface::ABS_PATH, new GetCollection());
            $this->tags[$iri] = $iri;

            $collectionIris = $this->getCollectionIris($resourceClass);

            foreach ($collectionIris as $collectionIri) {
                $this->tags[$collectionIri] = $collectionIri;
            }

            if ($purgeItem) {
                $this->addTagForItem($entity);
            }
        } catch (OperationNotFoundException | InvalidArgumentException) {
        }
    }

    private function gatherRelationTags(EntityManagerInterface $em, object $entity): void
    {
        $associationMappings = $em->getClassMetadata(ClassUtils::getClass($entity))->getAssociationMappings();

        foreach (array_keys($associationMappings) as $property) {
            if (
                \array_key_exists('targetEntity', $associationMappings[$property])
                && !$this->resourceClassResolver->isResourceClass($associationMappings[$property]['targetEntity'])) {
                return;
            }

            if ($this->propertyAccessor->isReadable($entity, $property)) {
                $this->addTagsFor($this->propertyAccessor->getValue($entity, $property));
            }
        }
    }

    private function addTagsFor(mixed $value): void
    {
        if (!$value || \is_scalar($value)) {
            return;
        }

        if (!is_iterable($value)) {
            $this->addTagForItem($value);

            return;
        }

        if ($value instanceof PersistentCollection) {
            $value = clone $value;
        }

        foreach ($value as $v) {
            $this->addTagForItem($v);
        }
    }

    private function addTagForItem(mixed $value): void
    {
        if (!$this->resourceClassResolver->isResourceClass($this->getObjectClass($value))) {
            return;
        }

        try {
            $iri = $this->iriConverter->getIriFromResource($value);
            $this->tags[$iri] = $iri;

            $resourceClass = $this->resourceClassResolver->getResourceClass($value);

            $collectionIri = $this->iriConverter->getIriFromResource($resourceClass, UrlGeneratorInterface::ABS_PATH, new GetCollection());
            $this->tags[$collectionIri] = $collectionIri;
            $collectionIris = $this->getCollectionIris($resourceClass);

            foreach ($collectionIris as $collectionIri) {
                $this->tags[$collectionIri] = $collectionIri;
            }
        } catch (RuntimeException | InvalidArgumentException $e) {
            $this->logger?->warning('Unable to add tags for item: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function getCollectionIris(string $resourceClass): array
    {
        $iris = [];

        try {
            $resourceMetadataCollection = $this->resourceMetadataCollectionFactory->create($resourceClass);

            foreach ($resourceMetadataCollection as $resourceMetadata) {
                foreach ($resourceMetadata->getOperations() as $operation) {
                    if ($operation instanceof GetCollection) {
                        try {
                            $iri = str_replace('{._format}', '', $operation->getUriTemplate());
                            $iris[] = $iri;
                        } catch (Exception $e) {
                            $this->logger?->warning('Unable to generate IRI for operation {operation}: {message}', [
                                'operation' => $operation->getName(),
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        } catch (Exception $exception) {
            $this->logger?->warning('Unable to retrieve metadata for resource {resource}: {message}', [
                'resource' => $resourceClass,
                'message' => $exception->getMessage(),
            ]);
        }

        return array_unique($iris); // Удаляем возможные дубликаты
    }
}
