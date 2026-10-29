<?php

declare(strict_types=1);

namespace Asin\Tma;

use DateTimeImmutable;

/**
 * Representation metier d'un rendez-vous.
 *
 * Les dates sont naives du point de vue de l'appelant : elles sont exprimees
 * en heure du Benin (UTC+1), sans changement d'heure.
 */
final readonly class RendezVous
{
    public function __construct(
        public ?int $id,
        public string $npi,
        public string $service,
        public DateTimeImmutable $dateHeure,
        public string $statut = 'confirme',
        public ?DateTimeImmutable $creeLe = null,
    ) {
    }
}
