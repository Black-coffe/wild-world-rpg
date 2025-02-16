<?php

namespace App\Entities;

class CharacterEntity
{
    public int $id;
    public string $name;
    public int $level;
    public float $health;
    public float $maxHealth;  // Добавили в объявленные свойства
    public float $tired;
    public float $strength;
    public float $agility;
    public float $intellect;
    public float $armor;
    public int $cell_number;
    public string $damageType;
    public float $damageValue;
    public float $attackSpeed;
    public float $experience = 0.0;
    public float $gold = 0.0;
    public bool $isBoss = false;
    public array $equipment = [];
    public bool $isRage = false;

    public function __construct(array $data)
    {
        $this->id         = $data['id']         ?? 0;
        $this->name       = $data['name']       ?? 'Unknown';
        $this->level      = $data['level']      ?? 1;
        $this->health     = $data['health']     ?? 100;
        // Если в БД хранится maxHealth отдельно, то используем его;
        // иначе просто ставим равным health:
        $this->maxHealth  = $data['maxHealth']  ?? ($data['health'] ?? 100);
        $this->tired      = $data['tired']      ?? 100;
        $this->strength   = $data['strength']   ?? 1;
        $this->agility    = $data['agility']    ?? 1;
        $this->intellect  = $data['intellect']  ?? 1;
        $this->armor      = $data['armor']      ?? 0;
        $this->damageType = $data['damage_type'] ?? 'Physical';
        $this->damageValue= $data['damage_value']?? 5;
        $this->attackSpeed= $data['attack_speed']?? 1;
        $this->experience = $data['experience'] ?? 0.0;
        $this->gold       = $data['gold']       ?? 0.0;
        $this->cell_number = $data['cell_number'] ?? 0;
        $this->isBoss     = $data['isBoss']     ?? false;
        $this->isRage     = $data['isRage']     ?? false;

        // Если в массиве есть ключ 'equipment', берём его как экипировку
        if (isset($data['equipment'])) {
            $this->equipment = $data['equipment'];
        }
    }

    /**
     * Возвращает предмет, надетый на заданный слот (например, 'head', 'body' и т.д.)
     */
    public function getEquippedItem(string $slot): ?array
    {
        return $this->equipment[$slot] ?? null;
    }
}
