<?php


namespace Monei\Model;

interface ModelInterface
{
    public function toArray(): array;

    public function toString(): string;

    public function toJSON(): ?string;

    public function toAPI(): ?array;
}
