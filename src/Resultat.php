<?php

declare(strict_types=1);

namespace Asin\Tma;

/**
 * Resultat d'une operation metier.
 *
 * Le code et le message sont directement transposables en reponse HTTP :
 * 201 creation, 200 succes, 400 saisie invalide, 409 action interdite.
 */
final readonly class Resultat
{
    public function __construct(
        public bool $ok,
        public int $code,
        public ?string $message = null,
        public mixed $donnees = null,
    ) {
    }

    public static function succes(int $code, mixed $donnees = null): self
    {
        return new self(true, $code, null, $donnees);
    }

    public static function refus(int $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
