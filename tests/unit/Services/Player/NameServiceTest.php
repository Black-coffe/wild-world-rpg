<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Models\CharacterModel;
use App\Services\Player\NameService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Валидация имени персонажа — имя на ЛЮБОМ языке (2026-06-01).
 * Раньше regex был latin-only (`[a-zA-Z0-9_]`), теперь `\p{L}\p{N}_` + /u
 * (кириллица, славянские диакритики и пр.). Пробелы/эмодзи/спецсимволы — запрещены.
 *
 * @internal
 */
final class NameServiceTest extends CIUnitTestCase
{
    private function service(): NameService
    {
        // Mock без DB (createMock отключает оригинальный конструктор).
        $model = $this->createMock(CharacterModel::class);

        return new NameService($model);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function freshChar(): array
    {
        // level<2 + has_renamed=0 → первая установка, успех без tier-блокировок.
        return ['id' => 1, 'level' => 1, 'has_renamed' => 0, 'last_name_change' => null];
    }

    /**
     * @dataProvider validNames
     */
    public function testAcceptsNamesInAnyLanguage(string $name): void
    {
        $res = $this->service()->applyName($this->freshChar(), $name);
        $this->assertTrue($res['ok'], "Имя '{$name}' должно приниматься");
    }

    /**
     * @return list<array{string}>
     */
    public static function validNames(): array
    {
        return [
            ['Иван_Грозный'],   // кириллица + _
            ['Андрей88'],       // кириллица + цифры
            ['My_Hero123'],     // латиница (back-compat)
            ['Łukasz'],         // польская диакритика
            ['František'],      // чешская
            ['Müller'],         // немецкая
            ['Ολυμπος'],        // греческая
            ['ВасяПупкин'],     // кириллица слитно
        ];
    }

    /**
     * @dataProvider invalidNames
     */
    public function testRejectsBadNames(string $name): void
    {
        $res = $this->service()->applyName($this->freshChar(), $name);
        $this->assertFalse($res['ok'], "Имя '{$name}' должно отклоняться");
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            ['ab'],             // слишком короткое (<3)
            [''],               // пусто
            ['Иван Грозный'],   // пробел
            ['Вася😀'],         // эмодзи
            ['Hero!!!'],        // спецсимволы
            ['Имя-С-Дефисом'],  // дефис не разрешён
            [str_repeat('я', 21)], // >20 code points
        ];
    }
}
