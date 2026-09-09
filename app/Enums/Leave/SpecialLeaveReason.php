<?php

namespace App\Enums\Leave;

enum SpecialLeaveReason: string
{
    case EmployeeMarriage = 'employee_marriage';

    case ChildMarriage = 'child_marriage';

    case DeathSpouse = 'death_spouse';

    case DeathParent = 'death_parent';

    case DeathChild = 'death_child';

    case DeathSibling = 'death_sibling';

    case DeathGrandparent = 'death_grandparent';

    case DeathGrandchild = 'death_grandchild';

    case DeathSpouseParent = 'death_spouse_parent';

    case DeathChildSpouse = 'death_child_spouse';

    case DeathSiblingSpouse = 'death_sibling_spouse';

    case DeathSpouseSibling = 'death_spouse_sibling';

    case DeathSpouseGrandparent = 'death_spouse_grandparent';

    public function label(): string
    {
        return match ($this) {
            self::EmployeeMarriage => 'Employee gets married',
            self::ChildMarriage => "Employee's child gets married",
            self::DeathSpouse => 'Death – spouse',
            self::DeathParent => 'Death – parent / adoptive parent',
            self::DeathChild => 'Death – child',
            self::DeathSibling => 'Death – brother / sister',
            self::DeathGrandparent => "Death – employee's grandparent",
            self::DeathGrandchild => "Death – employee's grandchild",
            self::DeathSpouseParent => "Death – spouse's parent",
            self::DeathChildSpouse => "Death – child's spouse",
            self::DeathSiblingSpouse => "Death – sibling's spouse",
            self::DeathSpouseSibling => "Death – spouse's sibling",
            self::DeathSpouseGrandparent => "Death – spouse's grandparent",
        };
    }

    public function isDeath(): bool
    {
        return str_starts_with($this->value, 'death_');
    }
}
