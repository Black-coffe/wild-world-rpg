<?php

namespace App\Entities\Traits;

/**
 * F1.4.1 — даёт CI4 Entity нативный array-style access (`$entity['key']`).
 *
 * Контекст: `CodeIgniter\Entity\Entity` имеет `__get`/`__set`/`__isset`/`__unset`,
 * но НЕ implementует `\ArrayAccess`. После migration `Model::$returnType = MyEntity::class`
 * существующий код вида `$row['name']` упадёт с TypeError.
 *
 * Этот trait делегирует ArrayAccess-операции к магическим методам Entity,
 * сохраняя backwards-compatibility со strangler-pattern: новый код может
 * использовать `$entity->name`, старый — `$entity['name']`.
 *
 * Использование:
 *
 *     class BiomeEntity extends \CodeIgniter\Entity\Entity implements \ArrayAccess
 *     {
 *         use ArrayAccessibleEntity;
 *     }
 *
 * @phpstan-require-extends \CodeIgniter\Entity\Entity
 */
trait ArrayAccessibleEntity
{
    public function offsetExists(mixed $offset): bool
    {
        if (!is_string($offset) && !is_int($offset)) {
            return false;
        }
        return $this->__isset((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_string($offset) && !is_int($offset)) {
            return null;
        }
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_string($offset) && !is_int($offset)) {
            return;
        }
        /** @var array<int|string, mixed>|bool|float|int|object|string|null $value */
        $this->__set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!is_string($offset) && !is_int($offset)) {
            return;
        }
        $this->__unset((string) $offset);
    }
}
