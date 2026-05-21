<?php

namespace Mercurio\Tables\Filter;

enum Operator: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case In = 'in';
    case NotIn = 'not_in';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case NotStartsWith = 'not_starts_with';
    case EndsWith = 'ends_with';
    case NotEndsWith = 'not_ends_with';
    case Between = 'between';
    case NotBetween = 'not_between';
    // 'empty' зарезервировано в PHP, поэтому case с trailing underscore, value сохраняем без него
    case Empty_ = 'empty';
    case NotEmpty = 'not_empty';
    case Gt = 'gt';
    case Lt = 'lt';
    case Gte = 'gte';
    case Lte = 'lte';

    public function pair(): ?self
    {
        return match ($this) {
            self::Eq => self::Neq,
            self::Neq => self::Eq,
            self::In => self::NotIn,
            self::NotIn => self::In,
            self::Contains => self::NotContains,
            self::NotContains => self::Contains,
            self::StartsWith => self::NotStartsWith,
            self::NotStartsWith => self::StartsWith,
            self::EndsWith => self::NotEndsWith,
            self::NotEndsWith => self::EndsWith,
            self::Between => self::NotBetween,
            self::NotBetween => self::Between,
            self::Empty_ => self::NotEmpty,
            self::NotEmpty => self::Empty_,
            self::Gt, self::Lt, self::Gte, self::Lte => null,
        };
    }
}
