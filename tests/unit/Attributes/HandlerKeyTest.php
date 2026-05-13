<?php

declare(strict_types=1);

namespace Tests\Unit\Attributes;

use App\Attributes\HandlerKey;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Phase B1 (ADR-023) — unit-тесты PHP-атрибута {@see HandlerKey}.
 *
 * Проверяем: immutability, defaults, accept всех обязательных и опциональных
 * полей, reflection-loop (атрибут реально читается через ReflectionClass).
 *
 * @internal
 */
final class HandlerKeyTest extends CIUnitTestCase
{
    public function testRequiredFields(): void
    {
        $attr = new HandlerKey(key: 'damage_health', displayName: 'Урон здоровью');

        $this->assertSame('damage_health', $attr->key);
        $this->assertSame('Урон здоровью', $attr->displayName);
        $this->assertSame('', $attr->description);
        $this->assertSame([], $attr->inputSchema);
    }

    public function testAllFields(): void
    {
        $schema = [
            ['name' => 'min', 'type' => 'int'],
            ['name' => 'kind', 'type' => 'enum', 'values' => ['a', 'b']],
        ];
        $attr = new HandlerKey(
            key:         'foo',
            displayName: 'Foo',
            description: 'desc',
            inputSchema: $schema,
        );

        $this->assertSame('foo', $attr->key);
        $this->assertSame('Foo', $attr->displayName);
        $this->assertSame('desc', $attr->description);
        $this->assertSame($schema, $attr->inputSchema);
    }

    public function testAttributeIsReadableViaReflection(): void
    {
        $obj = new #[HandlerKey(key: 'inline', displayName: 'Inline test')]
        class {};

        $refl  = new ReflectionClass($obj);
        $attrs = $refl->getAttributes(HandlerKey::class);

        $this->assertCount(1, $attrs);

        $hk = $attrs[0]->newInstance();
        $this->assertInstanceOf(HandlerKey::class, $hk);
        $this->assertSame('inline', $hk->key);
        $this->assertSame('Inline test', $hk->displayName);
    }

    public function testAttributeTargetIsClassOnly(): void
    {
        $refl = new ReflectionClass(HandlerKey::class);
        $meta = $refl->getAttributes(\Attribute::class);

        $this->assertCount(1, $meta, 'HandlerKey must itself be marked #[Attribute]');
        $flags = $meta[0]->getArguments()[0] ?? null;
        $this->assertSame(\Attribute::TARGET_CLASS, $flags, 'Target must be TARGET_CLASS only');
    }

    public function testReadonlyPropertiesCannotBeMutated(): void
    {
        $attr = new HandlerKey(key: 'k', displayName: 'd');
        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line — intentional readonly violation for test
        $attr->key = 'mutated';
    }
}
